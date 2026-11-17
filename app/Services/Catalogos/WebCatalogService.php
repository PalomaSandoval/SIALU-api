<?php

namespace App\Services\Catalogos;

class WebCatalogService
{
    private const LEGACY = [
        'tipos-usuarios' => TipoUsuarioService::class,
        'permisos' => PermisoService::class,
        'tipos-usuarios-permisos' => TipoUsuarioPermisoService::class,
    ];

    public function definition(string $catalog): array
    {
        return match ($catalog) {
            'tipos-usuarios' => ['title' => 'Tipos de usuario', 'id' => 'IdTipoUsuario', 'fields' => [
                'descripcion' => ['column' => 'Descripcion', 'label' => 'Descripción', 'max' => 50],
            ]],
            'permisos' => ['title' => 'Permisos', 'id' => 'IdPermiso', 'fields' => [
                'clave' => ['column' => 'Clave', 'label' => 'Clave', 'max' => 50],
                'descripcion' => ['column' => 'Descripcion', 'label' => 'Descripción', 'max' => 100],
            ]],
            'tipos-usuarios-permisos' => ['title' => 'Tipos de usuario · Permisos', 'id' => 'IdTipoUsuarioPermiso', 'fields' => [
                'id_tipo_usuario' => ['column' => 'IdTipoUsuario', 'label' => 'Tipo de usuario', 'catalog' => 'tipos-usuarios'],
                'id_permiso' => ['column' => 'IdPermiso', 'label' => 'Permiso', 'catalog' => 'permisos'],
            ]],
            default => abort(404),
        };
    }

    public function isLegacy(string $catalog): bool
    {
        return isset(self::LEGACY[$catalog]);
    }

    public function all(string $catalog): array
    {
        return $this->service($catalog)->all();
    }

    public function find(string $catalog, int $id): object|array
    {
        $item = $this->service($catalog)->find($id);
        abort_if($item === null, 404, 'El registro solicitado no existe.');

        return $item;
    }

    public function toArray(object|array $item): array
    {
        return is_array($item) ? $item : $item->toArray();
    }

    public function rules(string $catalog): array
    {
        $rules = [];
        foreach ($this->definition($catalog)['fields'] as $name => $field) {
            $rules[$name] = isset($field['catalog']) ? ['required', 'integer', 'min:1'] : ['required', 'string', 'max:'.$field['max']];
            if (($field['type'] ?? '') === 'email') {
                $rules[$name][] = 'email';
            }
        }

        return $rules;
    }

    public function save(string $catalog, array $data, ?int $id = null): object|array
    {
        $service = $this->service($catalog);
        $arguments = match ($catalog) {
            'tipos-usuarios' => [$data['descripcion']],
            'permisos' => [$data['clave'], $data['descripcion']],
            default => [(int) $data['id_tipo_usuario'], (int) $data['id_permiso']],
        };
        if ($id !== null) {
            $this->find($catalog, $id);
            array_unshift($arguments, $id);
        }

        return $service->{$id === null ? 'create' : 'update'}(...$arguments);
    }

    public function setActive(string $catalog, int $id, bool $active): object|array
    {
        $service = $this->service($catalog);
        $this->find($catalog, $id);

        return $service->{$active ? 'activate' : 'deactivate'}($id);
    }

    private function service(string $catalog): object
    {
        abort_unless($this->isLegacy($catalog), 404);

        return app(self::LEGACY[$catalog]);
    }
}
