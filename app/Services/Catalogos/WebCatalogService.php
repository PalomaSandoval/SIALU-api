<?php

namespace App\Services\Catalogos;

class WebCatalogService
{
    private const LEGACY = [
        'tipos-usuarios' => TipoUsuarioService::class,
        'permisos' => PermisoService::class,
        'tipos-usuarios-permisos' => TipoUsuarioPermisoService::class,
    ];

    public function __construct(private InstitutionalCatalogService $institutional) {}

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
            default => $this->institutional->definition($catalog),
        };
    }

    public function isLegacy(string $catalog): bool
    {
        return isset(self::LEGACY[$catalog]);
    }

    public function all(string $catalog): array
    {
        return $this->isLegacy($catalog) ? app(self::LEGACY[$catalog])->all() : $this->institutional->all($catalog);
    }

    public function find(string $catalog, int $id): object|array
    {
        $item = $this->isLegacy($catalog) ? app(self::LEGACY[$catalog])->find($id) : $this->institutional->find($catalog, $id);
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
        if (! $this->isLegacy($catalog)) {
            return $this->institutional->save($catalog, $data, $id);
        }
        $arguments = match ($catalog) {
            'tipos-usuarios' => [$data['descripcion']],
            'permisos' => [$data['clave'], $data['descripcion']],
            default => [(int) $data['id_tipo_usuario'], (int) $data['id_permiso']],
        };
        if ($id !== null) {
            $this->find($catalog, $id);
            array_unshift($arguments, $id);
        }

        return app(self::LEGACY[$catalog])->{$id === null ? 'create' : 'update'}(...$arguments);
    }

    public function setActive(string $catalog, int $id, bool $active): object|array
    {
        if (! $this->isLegacy($catalog)) {
            return $this->institutional->setActive($catalog, $id, $active);
        }
        $this->find($catalog, $id);

        return app(self::LEGACY[$catalog])->{$active ? 'activate' : 'deactivate'}($id);
    }
}
