<?php

namespace App\Http\Controllers\Catalogos;

use App\Http\Controllers\Controller;
use App\Support\Database;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FuenteController extends Controller
{
    private const FIELDS = [
        'nombre' => 'Nombre', 'fabricante' => 'Fabricante', 'origen' => 'OrigenDatos',
        'metodo' => 'MetodoIntegracion', 'version' => 'VersionFuente', 'zona' => 'ZonaHoraria',
        'responsable' => 'Responsable', 'raw' => 'DiasRetencionRaw',
        'eventos' => 'DiasRetencionEventos', 'parametros' => 'ParametrosJson',
    ];
    // Records kept by a logical deactivation; they become usable again when the source is reactivated.
    private const PRESERVED = [
        'eventos' => ['movdEventos', 'Eventos registrados', false], 'disponibilidad' => ['movdDisponibilidad', 'Episodios de disponibilidad', false],
        'lotes' => ['movdProcesamiento', 'Lotes de ingesta', false], 'staging' => ['movdstgEventos', 'Eventos en staging', false],
        'archivos' => ['movdArchivosLog', 'Archivos de log', false], 'identificadores_activo' => ['catdActivoIdentificadorFuente', 'Identificadores de activo vigentes', true],
        'identificadores_interfaz' => ['catdInterfazIdentificadorFuente', 'Identificadores de interfaz', false], 'contextos' => ['catdContextoFuente', 'Contextos de fuente', false],
        'zonas_presentacion' => ['catdZonaPresentacion', 'Zonas de presentación', false], 'zonas_trafico' => ['catdZonaTrafico', 'Zonas de tráfico', false],
        'mapeo_acciones' => ['catdMapeoAccion', 'Equivalencias de acciones vigentes', true], 'mapeo_severidades' => ['catdMapeoSeveridad', 'Equivalencias de severidad vigentes', true],
        'mapeo_tipos' => ['catdMapeoTipoEvento', 'Equivalencias de tipo de evento vigentes', true],
    ];

    public function __construct(private Database $database) {}

    private function call(int $action, array $parameters = []): array
    {
        return $this->database->callProcedureAll('dbo.sp_catcFuentes', ['Accion' => $action] + $parameters);
    }

    private function present(array $row): array
    {
        $id = (int) $row['IdFuente'];
        $iconPath = $this->iconPath($id);
        $result = ['id' => $id, 'vigente' => (bool) $row['Vigente'],
            'integracion_configurada' => in_array($id, [(int) config('sialu.sources.orion'), (int) config('sialu.sources.paloalto')], true),
            'icono_url' => $iconPath === null ? null : "/api/v1/catalogos/fuentes/{$id}/icono?v=".Storage::disk('local')->lastModified($iconPath)];
        foreach (self::FIELDS as $field => $column) $result[$field] = (string) ($row[$column] ?? '');
        return $result;
    }

    private function iconPath(int $id): ?string
    {
        $disk = Storage::disk('local');
        foreach (['png', 'jpg', 'jpeg', 'webp'] as $extension) {
            $path = "source-icons/{$id}.{$extension}";
            if ($disk->exists($path)) return $path;
        }
        return null;
    }

    public function showIcon(int $id)
    {
        $this->find($id);
        $path = $this->iconPath($id);
        abort_if($path === null, 404, 'La fuente no tiene ícono.');
        $mime = match (pathinfo($path, PATHINFO_EXTENSION)) {
            'png' => 'image/png', 'webp' => 'image/webp', default => 'image/jpeg',
        };
        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => $mime, 'Cache-Control' => 'private, no-store',
        ]);
    }

    public function uploadIcon(Request $request, int $id)
    {
        $this->find($id);
        $request->validate(['icono' => ['required', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048']]);
        $file = $request->file('icono');
        $extension = $file->extension();
        $disk = Storage::disk('local');
        $path = "source-icons/{$id}.{$extension}";
        abort_if($disk->putFileAs('source-icons', $file, "{$id}.{$extension}") === false, 500, 'No se pudo guardar el ícono.');
        foreach (['png', 'jpg', 'jpeg', 'webp'] as $oldExtension) {
            $oldPath = "source-icons/{$id}.{$oldExtension}";
            if ($oldPath !== $path) $disk->delete($oldPath);
        }
        return response()->json(['data' => $this->find($id)]);
    }

    public function index()
    {
        return response()->json(['data' => array_map($this->present(...), $this->call(4))]);
    }

    public function navigation()
    {
        $sources = [];
        foreach ($this->call(4) as $row) {
            $id = (int) $row['IdFuente'];
            $path = $this->iconPath($id);
            if ($path === null) continue;
            $sources[] = [
                'id' => $id,
                'nombre' => (string) $row['Nombre'],
                'icono_url' => "/api/v1/catalogos/fuentes/{$id}/icono?v=".Storage::disk('local')->lastModified($path),
            ];
        }
        return response()->json(['data' => $sources]);
    }

    public function show(int $id)
    {
        return response()->json(['data' => $this->find($id)]);
    }

    private function find(int $id): array
    {
        $row = $this->call(5, ['IdFuente' => $id])[0] ?? null;
        abort_if($row === null, 404, 'La fuente no existe.');
        return $this->present($row);
    }

    public function store(Request $request) { return $this->save($request); }
    public function update(Request $request, int $id) { return $this->save($request, $id); }

    private function save(Request $request, ?int $id = null)
    {
        // On edit, metodo and zona may only repeat the stored value, and omitted optional fields keep it.
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:100'], 'fabricante' => ['nullable', 'string', 'max:100'],
            'origen' => ['nullable', 'string', 'max:200'], 'metodo' => [$id === null ? 'required' : 'sometimes', Rule::in(['API','SYSLOG','BD','ARCHIVO','AGENTE','WEF','OTRO'])],
            'version' => ['nullable', 'string', 'max:50'], 'zona' => [$id === null ? 'required' : 'sometimes', 'string', 'max:64', 'timezone:all'],
            'responsable' => ['nullable', 'string', 'max:150'],
            'raw' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'eventos' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'parametros' => ['nullable', 'string', 'json', 'max:65536'], 'vigente' => ['required', 'boolean'],
        ]);
        if (isset($data['parametros']) && trim($data['parametros']) !== '') {
            $json = json_decode($data['parametros']);
            if (! is_object($json) && ! is_array($json)) throw ValidationException::withMessages(['parametros' => 'Use un objeto o arreglo JSON.']);
        }
        $result = DB::connection('sqlsrv')->transaction(function () use ($id, $data) {
            $current = $id === null ? null : $this->find($id);
            foreach (['metodo' => 'El método de integración', 'zona' => 'La zona horaria'] as $field => $label) {
                if ($current && array_key_exists($field, $data) && trim($data[$field]) !== $current[$field]) {
                    throw ValidationException::withMessages([$field => "{$label} no se puede modificar al editar una fuente."]);
                }
            }
            if ($current && $current['vigente'] && ! $data['vigente']) $this->assertCanDeactivate($id);
            foreach ($this->call(4) as $row) {
                if ((int) $row['IdFuente'] !== $id && mb_strtolower(trim($row['Nombre'])) === mb_strtolower(trim($data['nombre']))) {
                    throw ValidationException::withMessages(['nombre' => 'Ya existe una fuente con ese nombre.']);
                }
            }
            $parameters = [];
            foreach (self::FIELDS as $field => $column) {
                $value = $current && ! array_key_exists($field, $data) ? ($current[$field] === '' ? null : $current[$field]) : ($data[$field] ?? null);
                $parameters[$column] = in_array($field, ['raw','eventos'], true) ? ($value === null ? null : (int) $value) : (is_string($value) ? trim($value) : $value);
            }
            if ($id !== null) $parameters['IdFuente'] = $id;
            else $parameters['Vigente'] = (int) $data['vigente'];
            $saved = $this->call($id === null ? 1 : 3, $parameters)[0] ?? [];
            $savedId = $id ?? (int) ($saved['IdFuente'] ?? 0);
            abort_if($savedId < 1 || ($id !== null && (int) ($saved['FilasAfectadas'] ?? 0) !== 1), 409, 'No se pudo guardar la fuente.');
            if ($current && $current['vigente'] !== (bool) $data['vigente']) $this->call($data['vigente'] ? 6 : 2, ['IdFuente' => $savedId]);
            return $this->find($savedId);
        });
        return response()->json(['data' => $result], $id === null ? 201 : 200);
    }

    public function dependencies(int $id)
    {
        $this->find($id);
        $db = DB::connection('sqlsrv');
        $preserved = [];
        foreach (self::PRESERVED as $key => [$table, $label, $activeOnly]) {
            $total = $db->table('dbo.'.$table)->where('IdFuente', $id)->when($activeOnly, fn ($query) => $query->where('Vigente', 1))->count();
            if ($total > 0) $preserved[] = ['clave' => $key, 'descripcion' => $label, 'total' => $total];
        }
        return response()->json(['data' => ['bloqueos' => $this->blockers($id), 'conservados' => $preserved]]);
    }

    /** Conditions under which deactivating the source would break ingestion or strand in-flight data. */
    private function blockers(int $id): array
    {
        $db = DB::connection('sqlsrv');
        $blockers = [];
        foreach (['orion' => ['Orion', 'ORION_SOURCE_ID'], 'paloalto' => ['Palo Alto', 'PALOALTO_SOURCE_ID']] as $adapter => [$name, $variable]) {
            if ((int) config('sialu.sources.'.$adapter) === $id) $blockers[] = "La integración de {$name} usa esta fuente ({$variable}). Cambie la configuración del backend antes de desactivarla.";
        }
        $running = $db->table('dbo.movdProcesamiento')->where('IdFuente', $id)->where('Estado', 'EN_PROCESO')->count();
        if ($running > 0) $blockers[] = "Hay {$running} intento(s) de ingesta en proceso. Espere a que terminen o se recuperen.";
        $pending = $db->table('dbo.movdstgEventos')->where('IdFuente', $id)->where('Estado', 'PENDIENTE')->count();
        if ($pending > 0) $blockers[] = "Hay {$pending} evento(s) en staging pendientes de procesar.";
        return $blockers;
    }

    /** Must run inside a transaction: holds the ingestion lock and the source row until commit. */
    private function assertCanDeactivate(int $id): void
    {
        $db = DB::connection('sqlsrv');
        $lock = $db->selectOne("DECLARE @r int; EXEC @r=sys.sp_getapplock @Resource=?, @LockMode='Exclusive', @LockOwner='Transaction', @LockTimeout=5000; SELECT @r AS result", ['SIALU:ingestion:source:'.$id]);
        abort_if((int) $lock->result < 0, 409, 'La fuente tiene una ingesta en curso. Intente desactivarla más tarde.');
        abort_if($db->table('dbo.catcFuentes')->where('IdFuente', $id)->lockForUpdate()->first() === null, 404, 'La fuente no existe.');
        if ($blockers = $this->blockers($id)) throw ValidationException::withMessages(['vigente' => $blockers]);
    }

    public function activate(int $id) { return $this->state($id, true); }
    public function deactivate(int $id) { return $this->state($id, false); }
    private function state(int $id, bool $active)
    {
        $result = DB::connection('sqlsrv')->transaction(function () use ($id, $active) {
            $current = $this->find($id);
            if ($current['vigente'] === $active) return $current;
            if (! $active) $this->assertCanDeactivate($id);
            abort_if((int) ($this->call($active ? 6 : 2, ['IdFuente' => $id])[0]['FilasAfectadas'] ?? 0) !== 1, 409, 'No se pudo cambiar el estado de la fuente.');
            return $this->find($id);
        });
        return response()->json(['data' => $result]);
    }
}
