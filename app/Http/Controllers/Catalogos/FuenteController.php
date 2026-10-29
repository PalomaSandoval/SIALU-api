<?php

namespace App\Http\Controllers\Catalogos;

use App\Http\Controllers\Controller;
use App\Support\Database;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    public function __construct(private Database $database) {}

    private function call(int $action, array $parameters = []): array
    {
        return $this->database->callProcedureAll('dbo.sp_catcFuentes', ['Accion' => $action] + $parameters);
    }

    private function present(array $row): array
    {
        $result = ['id' => (int) $row['IdFuente'], 'vigente' => (bool) $row['Vigente']];
        foreach (self::FIELDS as $field => $column) $result[$field] = (string) ($row[$column] ?? '');
        return $result;
    }

    public function index()
    {
        return response()->json(['data' => array_map($this->present(...), $this->call(4))]);
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
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:100'], 'fabricante' => ['nullable', 'string', 'max:100'],
            'origen' => ['nullable', 'string', 'max:200'], 'metodo' => ['required', Rule::in(['API','SYSLOG','BD','ARCHIVO','AGENTE','WEF','OTRO'])],
            'version' => ['nullable', 'string', 'max:50'], 'zona' => ['required', 'string', 'max:64', 'timezone:all'],
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
            foreach ($this->call(4) as $row) {
                if ((int) $row['IdFuente'] !== $id && mb_strtolower(trim($row['Nombre'])) === mb_strtolower(trim($data['nombre']))) {
                    throw ValidationException::withMessages(['nombre' => 'Ya existe una fuente con ese nombre.']);
                }
            }
            if ($id !== null && $id === (int) config('sialu.sources.orion') && $data['zona'] !== 'UTC') {
                throw ValidationException::withMessages(['zona' => 'La integración de Orion requiere UTC.']);
            }
            if ($current && $data['zona'] !== $current['zona'] && DB::connection('sqlsrv')->table('dbo.movdEventos')->where('IdFuente', $id)->exists()) {
                throw ValidationException::withMessages(['zona' => 'La fuente ya tiene eventos. El cambio de zona requiere revisar su integración e histórico.']);
            }
            if ($id !== null && in_array($id, [(int) config('sialu.sources.orion'), (int) config('sialu.sources.paloalto')], true) && $data['metodo'] !== 'API') {
                throw ValidationException::withMessages(['metodo' => 'La integración actual de esta fuente utiliza API.']);
            }
            $parameters = [];
            foreach (self::FIELDS as $field => $column) {
                $value = $data[$field] ?? null;
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

    public function activate(int $id) { return $this->state($id, true); }
    public function deactivate(int $id) { return $this->state($id, false); }
    private function state(int $id, bool $active)
    {
        $this->find($id);
        $this->call($active ? 6 : 2, ['IdFuente' => $id]);
        return response()->json(['data' => $this->find($id)]);
    }
}
