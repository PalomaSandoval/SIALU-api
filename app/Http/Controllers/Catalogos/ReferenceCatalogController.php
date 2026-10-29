<?php

namespace App\Http\Controllers\Catalogos;

use App\Http\Controllers\Controller;
use App\Support\Database;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReferenceCatalogController extends Controller
{
    public function __construct(private Database $database) {}
    private function definition(string $reference): array
    {
        $definition = config('reference_catalogs.'.$reference);
        abort_unless(is_array($definition), 404);
        return $definition;
    }
    private function call(string $reference, int $action, array $parameters = []): array
    {
        return $this->database->callProcedureAll($this->definition($reference)['procedure'], ['Accion' => $action] + $parameters);
    }
    private function present(string $reference, array $row): array
    {
        $definition = $this->definition($reference);
        $result = ['id' => (int) $row[$definition['id']], 'vigente' => in_array($row['Vigente'], [true, 1, '1'], true)];
        foreach ($definition['fields'] as $field => $spec) {
            $value = $row[$spec['column']] ?? null;
            $result[$field] = ($spec['numeric'] ?? false) ? ($value === null ? null : (int)$value) : (string)($value ?? '');
        }
        return $result;
    }
    private function raw(string $reference, int $id): array
    {
        $row = $this->call($reference, 5, [$this->definition($reference)['id'] => $id])[0] ?? null;
        abort_if($row === null, 404, 'El registro no existe.');
        return $row;
    }
    public function index(string $reference)
    {
        return response()->json(['data' => array_map(fn ($row) => $this->present($reference, $row), $this->call($reference, 4))]);
    }
    public function show(int $id, string $reference)
    {
        return response()->json(['data' => $this->present($reference, $this->raw($reference, $id))]);
    }
    public function store(Request $request, string $reference) { return $this->save($request, $reference); }
    public function update(Request $request, int $id, string $reference) { return $this->save($request, $reference, $id); }
    private function save(Request $request, string $reference, ?int $id = null)
    {
        $definition = $this->definition($reference);
        $rules = array_map(fn ($spec) => $spec['rules'], $definition['fields']);
        if (($definition['manual_id'] ?? false) && $id === null) $rules['id'] = ['required','integer','min:0','max:255'];
        $data = $request->validate($rules);
        $result = DB::connection('sqlsrv')->transaction(function () use ($data, $reference, $id, $definition) {
            $current = $id === null ? null : $this->raw($reference, $id);
            $parameters = [];
            foreach ($definition['fields'] as $field => $spec) {
                $value = $data[$field] ?? null;
                $parameters[$spec['column']] = ($spec['numeric'] ?? false) ? ($value === null || $value === '' ? null : (int) $value) : (is_string($value) ? trim($value) : $value);
                if (in_array('required', $spec['rules'], true) && is_string($value) && trim($value) === '') {
                    throw ValidationException::withMessages([$field => 'Este campo es obligatorio.']);
                }
            }
            foreach ($this->call($reference, 4) as $row) {
                if ($id !== null && (int) $row[$definition['id']] === $id) continue;
                foreach ($definition['unique'] as $field) {
                    if (mb_strtolower(trim((string) $row[$definition['fields'][$field]['column']])) === mb_strtolower(trim((string) ($data[$field] ?? '')))) {
                        throw ValidationException::withMessages([$field => 'Ya existe un registro con este valor.']);
                    }
                }
            }
            $savedId = $id;
            if ($id !== null) $parameters[$definition['id']] = $id;
            elseif ($definition['manual_id'] ?? false) {
                $savedId = (int) $data['id'];
                if ($this->call($reference, 5, [$definition['id'] => $savedId])) throw ValidationException::withMessages(['id' => 'El identificador ya está en uso.']);
                $parameters[$definition['id']] = $savedId;
            }
            if ($reference === 'usuarios') {
                $this->validateUserRelations($parameters, $current);
                // Preserve access history; never clear it through the catalog form.
                foreach (['Intentos','Bloqueado','Fecha_Alta','UltimoAcceso','UltimaIP','UltimoHost'] as $column) {
                    $parameters[$column] = $current[$column] ?? match ($column) {
                        'Intentos', 'Bloqueado' => 0, 'Fecha_Alta' => now()->format('Y-m-d H:i:s'), default => null,
                    };
                }
                $parameters['Segundo_Apellido'] ??= '';
                $parameters['Uid'] = strtolower($parameters['Uid']);
            }
            if ($id === null) $parameters['Vigente'] = 1;
            $saved = $this->call($reference, $id === null ? 1 : 3, $parameters)[0] ?? [];
            if ($savedId === null) $savedId = (int) ($saved[$definition['id']] ?? 0);
            abort_if(($id !== null && (int) ($saved['FilasAfectadas'] ?? 0) !== 1) || ($savedId === null || $savedId < 1), 409, 'No se pudo guardar el registro.');
            return $this->present($reference, $this->raw($reference, $savedId));
        });
        return response()->json(['data' => $result], $id === null ? 201 : 200);
    }
    private function validateUserRelations(array $parameters, ?array $current): void
    {
        if ($current && (int)$current['IdUsuario'] === (int)app(\App\Services\SessionManager::class)->userValue('IdUsuario')) {
            abort_if((int)$current['IdTipoUsuario'] !== $parameters['IdTipoUsuario'] || strtolower($current['Uid']) !== strtolower($parameters['Uid']),409,'No puedes cambiar tu propia cuenta o rol desde este catálogo.');
        }
        foreach (['IdTipoUsuario' => ['dbo.catcTiposUsuarios','tipo_usuario'], 'IdArea' => ['dbo.catcAreas','area']] as $column => [$table, $field]) {
            if ($parameters[$column] === null) continue;
            $relation = DB::connection('sqlsrv')->table($table)->where($column, $parameters[$column])->first(['Vigente']);
            if (! $relation || (! $relation->Vigente && (int) ($current[$column] ?? 0) !== $parameters[$column])) {
                throw ValidationException::withMessages([$field => 'Selecciona un registro vigente.']);
            }
        }
    }
    public function activate(int $id, string $reference) { return $this->state($reference, $id, true); }
    public function deactivate(int $id, string $reference) { return $this->state($reference, $id, false); }
    private function state(string $reference, int $id, bool $active)
    {
        $result = DB::connection('sqlsrv')->transaction(function () use ($reference, $id, $active) {
            $this->raw($reference, $id);
            if (!$active) app(\App\Services\Catalogos\CatalogDependencies::class)->assertCanDeactivate($reference, $id);
            if (!$active) app(\App\Services\Catalogos\LocationDependencies::class)->assertCanDeactivate($reference, $id);
            $this->call($reference, $active ? 6 : 2, [$this->definition($reference)['id'] => $id]);
            return $this->present($reference, $this->raw($reference, $id));
        });
        return response()->json(['data' => $result]);
    }
}
