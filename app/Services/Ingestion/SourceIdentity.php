<?php

namespace App\Services\Ingestion;

use RuntimeException;

final class SourceIdentity
{
    public static function id(string $adapter): int
    {
        if (! in_array($adapter, ['orion', 'paloalto'], true)) {
            throw new RuntimeException('Integración desconocida.');
        }
        $id = filter_var(config('sialu.sources.'.$adapter), FILTER_VALIDATE_INT);
        if ($id === false || $id < 1 || $id > 32767) {
            throw new RuntimeException('Configure el IdFuente real de '.strtoupper($adapter).' en el entorno del backend.');
        }
        $other = $adapter === 'orion' ? 'paloalto' : 'orion';
        if ((int) config('sialu.sources.'.$other) === $id) {
            throw new RuntimeException('Orion y Palo Alto deben tener distintos IdFuente.');
        }

        return $id;
    }
}
