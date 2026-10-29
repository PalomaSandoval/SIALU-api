<?php

declare(strict_types=1);

namespace App\Repositories\Catalogos;

use App\Models\Catalogos\TipoUsuarioPermiso;
use App\Support\Database;
use RuntimeException;

final class TipoUsuarioPermisoRepository
{
    private const PROCEDURE =
        'sp_catdTiposUsuariosPermisos';

    private Database $database;

    public function __construct(
        Database $database
    ) {
        $this->database = $database;
    }

    /**
     * =========================================================
     * CONSULTA GENERAL
     * Acción 4
     * =========================================================
     *
     * @return array<int, TipoUsuarioPermiso>
     */
    public function all(): array
    {
        $rows = $this->database->fetchAll(
            $this->procedureSql(),
            $this->parameters(
                4
            )
        );

        $result = [];

        foreach ($rows as $row) {
            $result[] =
                $this->mapRow(
                    $row
                );
        }

        return $result;
    }

    /**
     * =========================================================
     * CONSULTA POR ID
     * Acción 5
     * =========================================================
     */
    public function findById(
        int $idTipoUsuarioPermiso
    ): ?TipoUsuarioPermiso {
        $row = $this->database->fetchOne(
            $this->procedureSql(),
            $this->parameters(
                5,
                $idTipoUsuarioPermiso
            )
        );

        if ($row === null) {
            return null;
        }

        return $this->mapRow(
            $row
        );
    }

    /**
     * =========================================================
     * CONSULTA POR RELACIÓN
     * Acción 7
     * =========================================================
     *
     * Busca una combinación exacta:
     *
     * IdTipoUsuario
     * +
     * IdPermiso
     *
     * Incluye registros vigentes e inactivos.
     */
    public function findByRelation(
        int $idTipoUsuario,
        int $idPermiso
    ): ?TipoUsuarioPermiso {
        $row = $this->database->fetchOne(
            $this->procedureSql(),
            $this->parameters(
                7,
                null,
                $idTipoUsuario,
                $idPermiso
            )
        );

        if ($row === null) {
            return null;
        }

        return $this->mapRow(
            $row
        );
    }

    /**
     * =========================================================
     * ALTA
     * Acción 1
     * =========================================================
     *
     * @return int IdTipoUsuarioPermiso generado.
     */
    public function create(
        int $idTipoUsuario,
        int $idPermiso
    ): int {
        $row = $this->database->fetchOne(
            $this->procedureSql(),
            $this->parameters(
                1,
                null,
                $idTipoUsuario,
                $idPermiso
            )
        );

        if (
            $row === null
            || ! array_key_exists(
                'IdTipoUsuarioPermiso',
                $row
            )
        ) {
            throw new RuntimeException(
                'El procedimiento almacenado no devolvió '
                .'el IdTipoUsuarioPermiso generado.'
            );
        }

        $id =
            (int) $row[
                'IdTipoUsuarioPermiso'
            ];

        if ($id < 1) {
            throw new RuntimeException(
                'El procedimiento almacenado devolvió '
                .'un IdTipoUsuarioPermiso no válido.'
            );
        }

        return $id;
    }

    /**
     * =========================================================
     * MODIFICACIÓN
     * Acción 3
     * =========================================================
     */
    public function update(
        int $idTipoUsuarioPermiso,
        int $idTipoUsuario,
        int $idPermiso
    ): bool {
        $row = $this->database->fetchOne(
            $this->procedureSql(),
            $this->parameters(
                3,
                $idTipoUsuarioPermiso,
                $idTipoUsuario,
                $idPermiso
            )
        );

        return $this->affectedRows(
            $row
        ) > 0;
    }

    /**
     * =========================================================
     * BAJA LÓGICA
     * Acción 2
     * =========================================================
     */
    public function deactivate(
        int $idTipoUsuarioPermiso
    ): bool {
        $row = $this->database->fetchOne(
            $this->procedureSql(),
            $this->parameters(
                2,
                $idTipoUsuarioPermiso
            )
        );

        return $this->affectedRows(
            $row
        ) > 0;
    }

    /**
     * =========================================================
     * REACTIVACIÓN
     * Acción 6
     * =========================================================
     */
    public function activate(
        int $idTipoUsuarioPermiso
    ): bool {
        $row = $this->database->fetchOne(
            $this->procedureSql(),
            $this->parameters(
                6,
                $idTipoUsuarioPermiso
            )
        );

        return $this->affectedRows(
            $row
        ) > 0;
    }

    /**
     * =========================================================
     * SQL DEL PROCEDIMIENTO
     * =========================================================
     */
    private function procedureSql(): string
    {
        return '
            EXEC '.self::PROCEDURE.'
                @Accion = :Accion,
                @IdTipoUsuarioPermiso =
                    :IdTipoUsuarioPermiso,
                @IdTipoUsuario =
                    :IdTipoUsuario,
                @IdPermiso =
                    :IdPermiso,
                @Vigente =
                    :Vigente
        ';
    }

    /**
     * =========================================================
     * PARÁMETROS
     * =========================================================
     *
     * Centralizamos aquí los parámetros del SP para evitar
     * repetir el mismo arreglo en todos los métodos.
     *
     * @return array<string, int|null>
     */
    private function parameters(
        int $accion,
        ?int $idTipoUsuarioPermiso = null,
        ?int $idTipoUsuario = null,
        ?int $idPermiso = null,
        ?int $vigente = null
    ): array {
        return [
            'Accion' => $accion,

            'IdTipoUsuarioPermiso' => $idTipoUsuarioPermiso,

            'IdTipoUsuario' => $idTipoUsuario,

            'IdPermiso' => $idPermiso,

            'Vigente' => $vigente,
        ];
    }

    /**
     * =========================================================
     * MAPEO
     * =========================================================
     *
     * @param  array<string, mixed>  $row
     */
    private function mapRow(
        array $row
    ): TipoUsuarioPermiso {
        $required = [
            'IdTipoUsuarioPermiso',
            'IdTipoUsuario',
            'TipoUsuario',
            'IdPermiso',
            'ClavePermiso',
            'DescripcionPermiso',
            'Vigente',
        ];

        foreach ($required as $column) {
            if (
                ! array_key_exists(
                    $column,
                    $row
                )
            ) {
                throw new RuntimeException(
                    sprintf(
                        'La consulta de '
                        .'catdTiposUsuariosPermisos '
                        .'no devolvió la columna requerida "%s".',
                        $column
                    )
                );
            }
        }

        $idRelacion =
            (int) $row[
                'IdTipoUsuarioPermiso'
            ];

        $idTipoUsuario =
            (int) $row[
                'IdTipoUsuario'
            ];

        $idPermiso =
            (int) $row[
                'IdPermiso'
            ];

        if (
            $idRelacion < 1
            || $idTipoUsuario < 1
            || $idPermiso < 1
        ) {
            throw new RuntimeException(
                'La consulta devolvió identificadores '
                .'no válidos para la relación '
                .'TipoUsuario-Permiso.'
            );
        }

        return new TipoUsuarioPermiso(
            $idRelacion,
            $idTipoUsuario,
            trim(
                (string) $row['TipoUsuario']
            ),
            $idPermiso,
            trim(
                (string) $row['ClavePermiso']
            ),
            trim(
                (string) $row['DescripcionPermiso']
            ),
            $this->toBool(
                $row['Vigente']
            )
        );
    }

    /**
     * =========================================================
     * FILAS AFECTADAS
     * =========================================================
     *
     * @param  array<string, mixed>|null  $row
     */
    private function affectedRows(
        ?array $row
    ): int {
        if (
            $row === null
            || ! array_key_exists(
                'FilasAfectadas',
                $row
            )
        ) {
            throw new RuntimeException(
                'El procedimiento almacenado no devolvió '
                .'el valor FilasAfectadas.'
            );
        }

        return max(
            0,
            (int) $row['FilasAfectadas']
        );
    }

    /**
     * =========================================================
     * CONVERSIÓN BIT → BOOL
     * =========================================================
     *
     * @param  mixed  $value
     */
    private function toBool(
        $value
    ): bool {
        if (is_bool($value)) {
            return $value;
        }

        if (
            $value === 1
            || $value === '1'
        ) {
            return true;
        }

        if (
            $value === 0
            || $value === '0'
            || $value === null
        ) {
            return false;
        }

        $boolean =
            filter_var(
                $value,
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            );

        if ($boolean === null) {
            throw new RuntimeException(
                'El procedimiento almacenado devolvió '
                .'un valor Vigente no válido.'
            );
        }

        return $boolean;
    }
}
