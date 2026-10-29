<?php

namespace App\Repositories\Catalogos;

use App\Support\Database;

class InstitutionalCatalogRepository
{
    public function __construct(private Database $database) {}

    public function definition(string $catalog): array
    {
        $definition = config('catalogs.'.$catalog);
        abort_unless(is_array($definition), 404);

        return $definition;
    }

    public function call(string $catalog, int $action, array $parameters = []): array
    {
        $definition = $this->definition($catalog);

        return $this->database->callProcedureAll(
            $definition['procedure'],
            array_replace($definition['defaults'] ?? [], ['Accion' => $action], $parameters),
        );
    }
}
