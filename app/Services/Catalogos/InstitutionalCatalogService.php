<?php

namespace App\Services\Catalogos;

use App\Repositories\Catalogos\InstitutionalCatalogRepository;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class InstitutionalCatalogService
{
    public function __construct(private InstitutionalCatalogRepository $repository) {}

    public function definition(string $catalog): array
    {
        return $this->repository->definition($catalog);
    }

    public function all(string $catalog): array
    {
        return array_map($this->normalizeRow(...), $this->repository->call($catalog, 4));
    }

    public function find(string $catalog, int $id): array
    {
        $definition = $this->definition($catalog);
        $row = $this->repository->call($catalog, 5, [$definition['id'] => $id])[0] ?? null;
        abort_if($row === null, 404, 'El registro solicitado no existe.');

        return $this->normalizeRow($row);
    }

    public function save(string $catalog, array $input, ?int $id = null): array
    {
        $definition = $this->definition($catalog);
        $current = $id === null ? null : $this->find($catalog, $id);
        $data = [];
        foreach ($definition['fields'] as $field => $specification) {
            if (isset($specification['catalog'])) {
                $data[$specification['column']] = (int) $input[$field];
                if ($current === null || $current[$specification['column']] !== $data[$specification['column']]) {
                    $related = $this->find($specification['catalog'], $data[$specification['column']]);
                    if (! $related['Vigente']) {
                        throw ValidationException::withMessages([$field => 'Seleccione un registro vigente.']);
                    }
                }
            } else {
                $value = trim(preg_replace('/\s+/u', ' ', $input[$field]));
                $data[$specification['column']] = ($specification['type'] ?? '') === 'email'
                    ? mb_strtolower($value) : mb_strtoupper($value);
            }
        }
        if ($current !== null && array_intersect_key($current, $data) === $data) {
            return $current;
        }
        foreach ($this->all($catalog) as $row) {
            if ($id !== null && (int) $row[$definition['id']] === $id) {
                continue;
            }
            $duplicate = true;
            foreach ($definition['unique'] as $column) {
                $duplicate = $duplicate && mb_strtolower((string) $row[$column]) === mb_strtolower((string) $data[$column]);
            }
            if ($duplicate) {
                throw ValidationException::withMessages([array_key_first($definition['fields']) => 'Ya existe un registro con esos datos.']);
            }
        }
        // Coordinacion and Departamentos overwrite Vigente in action 3: preserve it explicitly.
        $data['Vigente'] = (int) ($current['Vigente'] ?? true);
        if ($id !== null) {
            $data[$definition['id']] = $id;
        }
        $result = $this->repository->call($catalog, $id === null ? 1 : 3, $data)[0] ?? [];
        if ($id === null) {
            $id = (int) ($result[$definition['id']] ?? 0);
            if ($id < 1) {
                throw new RuntimeException('El procedimiento no devolvió el identificador creado.');
            }
        } elseif ((int) ($result['FilasAfectadas'] ?? 0) !== 1) {
            throw new RuntimeException('No se actualizó exactamente un registro.');
        }

        return $this->find($catalog, $id);
    }

    public function setActive(string $catalog, int $id, bool $active): array
    {
        $definition = $this->definition($catalog);
        $row = $this->find($catalog, $id);
        if ($row['Vigente'] === $active) {
            return $row;
        }
        $parameters = [$definition['id'] => $id];
        $action = $active ? $definition['activate_action'] : 2;
        if ($active && $action === 3) {
            $parameters['Descripcion'] = $row['Descripcion'];
            $parameters['Vigente'] = 1;
        }
        $result = $this->repository->call($catalog, $action, $parameters)[0] ?? [];
        if ((int) ($result['FilasAfectadas'] ?? 0) !== 1) {
            throw new RuntimeException('No se actualizó exactamente un registro.');
        }

        return $this->find($catalog, $id);
    }

    private function normalizeRow(array $row): array
    {
        $row['Vigente'] = in_array($row['Vigente'], [true, 1, '1'], true);

        return $row;
    }
}
