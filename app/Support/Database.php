<?php

namespace App\Support;

use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use PDO;
use PDOStatement;

/** Executes the existing SQL Server procedure contracts through Laravel's connection. */
class Database
{
    public function __construct(private DatabaseManager $manager) {}

    public function execute(string $sql, array $parameters = []): PDOStatement
    {
        $statement = $this->manager->connection('sqlsrv')->getPdo()->prepare($sql);
        foreach ($parameters as $name => $value) {
            $type = match (true) {
                $value === null => PDO::PARAM_NULL,
                is_bool($value) => PDO::PARAM_BOOL,
                is_int($value) => PDO::PARAM_INT,
                default => PDO::PARAM_STR,
            };
            $statement->bindValue(':'.ltrim((string) $name, ':'), $value, $type);
        }
        $statement->execute();

        return $statement;
    }

    public function fetchAll(string $sql, array $parameters = []): array
    {
        $statement = $this->execute($sql, $parameters);
        try {
            // SQL Server procedures may emit row-count results before the SELECT.
            do {
                if ($statement->columnCount() > 0) {
                    return $statement->fetchAll(PDO::FETCH_ASSOC);
                }
            } while ($statement->nextRowset());

            return [];
        } finally {
            $statement->closeCursor();
        }
    }

    public function fetchOne(string $sql, array $parameters = []): ?array
    {
        return $this->fetchAll($sql, $parameters)[0] ?? null;
    }

    public function callProcedureAll(string $procedure, array $parameters = []): array
    {
        return $this->fetchAll($this->procedureSql($procedure, $parameters), $parameters);
    }

    public function callProcedureOne(string $procedure, array $parameters = []): ?array
    {
        return $this->callProcedureAll($procedure, $parameters)[0] ?? null;
    }

    public function procedureSql(string $procedure, array $parameters = []): string
    {
        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)?$/', $procedure)) {
            throw new InvalidArgumentException('Nombre de procedimiento no válido.');
        }
        $bindings = [];
        foreach (array_keys($parameters) as $name) {
            if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
                throw new InvalidArgumentException('Nombre de parámetro no válido.');
            }
            $bindings[] = "@{$name} = :{$name}";
        }

        return 'EXEC '.$procedure.' '.implode(', ', $bindings);
    }
}
