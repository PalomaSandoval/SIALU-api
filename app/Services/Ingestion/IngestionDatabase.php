<?php

namespace App\Services\Ingestion;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class IngestionDatabase
{
    public static function verifyConfiguredConnection(): void
    {
        $name = self::name();
        $connection = DB::connection($name);
        if ($connection->getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('SIALU requiere una conexión SQL Server.');
        }

        $expected = config("database.connections.{$name}.database");
        $actual = $connection->selectOne('SELECT DB_NAME() AS name')->name;
        if (! is_string($expected) || strcasecmp($actual, $expected) !== 0) {
            throw new RuntimeException('La base conectada no coincide con DB_DATABASE.');
        }
    }

    public static function name(): string
    {
        return config('sialu.connection', config('database.default', 'sqlsrv'));
    }
}
