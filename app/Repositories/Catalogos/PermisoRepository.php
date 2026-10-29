<?php

declare(strict_types=1);

namespace App\Repositories\Catalogos;

use App\Models\Catalogos\Permiso;
use App\Support\Database;
use InvalidArgumentException;
use RuntimeException;

final class PermisoRepository
{
    private const PROCEDURE =
        'dbo.sp_catcPermisos';

    private const ACTION_CREATE = 1;

    private const ACTION_DEACTIVATE = 2;

    private const ACTION_UPDATE = 3;

    private const ACTION_FIND_ALL = 4;

    private const ACTION_FIND_BY_ID = 5;

    private const ACTION_ACTIVATE = 6;

    private const ACTION_FIND_BY_CLAVE = 7;

    private const ACTION_FIND_ACTIVE = 8;

    private Database $database;

    public function __construct(
        Database $database
    ) {
        $this->database = $database;
    }

    /**
     * Obtiene todos los permisos.
     *
     * @return array<int, Permiso>
     */
    public function all(): array
    {
        $rows = $this->database
            ->callProcedureAll(
                self::PROCEDURE,
                [
                    'Accion' => self::ACTION_FIND_ALL,
                ]
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
     * LISTADO DE PERMISOS VIGENTES
     * =========================================================
     *
     * @return Permiso[]
     */
    public function active(): array
    {
        $rows = $this->database
            ->callProcedureAll(
                self::PROCEDURE,
                [
                    'Accion' => self::ACTION_FIND_ACTIVE,
                ]
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
     * Busca un permiso por ID.
     */
    public function findById(
        int $idPermiso
    ): ?Permiso {
        $this->assertValidId(
            $idPermiso
        );

        $row = $this->database
            ->callProcedureOne(
                self::PROCEDURE,
                [
                    'Accion' => self::ACTION_FIND_BY_ID,

                    'IdPermiso' => $idPermiso,
                ]
            );

        if ($row === null) {
            return null;
        }

        return $this->mapRow(
            $row
        );
    }

    /**
     * Busca un permiso por Clave.
     */
    public function findByClave(
        string $clave
    ): ?Permiso {
        $clave = trim(
            $clave
        );

        if ($clave === '') {
            throw new InvalidArgumentException(
                'La clave no puede estar vacía.'
            );
        }

        $row = $this->database
            ->callProcedureOne(
                self::PROCEDURE,
                [
                    'Accion' => self::ACTION_FIND_BY_CLAVE,

                    'Clave' => $clave,
                ]
            );

        if ($row === null) {
            return null;
        }

        return $this->mapRow(
            $row
        );
    }

    /**
     * Registra un nuevo permiso.
     *
     * Devuelve el IdPermiso generado.
     */
    public function create(
        string $clave,
        string $descripcion
    ): int {
        $row = $this->database
            ->callProcedureOne(
                self::PROCEDURE,
                [
                    'Accion' => self::ACTION_CREATE,

                    'Clave' => $clave,

                    'Descripcion' => $descripcion,

                    'Vigente' => 1,
                ]
            );

        if (
            $row === null
            || ! array_key_exists(
                'IdPermiso',
                $row
            )
        ) {
            throw new RuntimeException(
                'El procedimiento almacenado '
                .'no devolvió el IdPermiso generado.'
            );
        }

        $idPermiso =
            (int) $row['IdPermiso'];

        if ($idPermiso <= 0) {
            throw new RuntimeException(
                'El IdPermiso generado '
                .'no es válido.'
            );
        }

        return $idPermiso;
    }

    /**
     * Modifica Clave y Descripcion.
     *
     * Vigente no se modifica mediante update().
     */
    public function update(
        int $idPermiso,
        string $clave,
        string $descripcion
    ): bool {
        $this->assertValidId(
            $idPermiso
        );

        $row = $this->database
            ->callProcedureOne(
                self::PROCEDURE,
                [
                    'Accion' => self::ACTION_UPDATE,

                    'IdPermiso' => $idPermiso,

                    'Clave' => $clave,

                    'Descripcion' => $descripcion,
                ]
            );

        return $this->affectedRows(
            $row
        ) > 0;
    }

    /**
     * Realiza baja lógica.
     */
    public function deactivate(
        int $idPermiso
    ): bool {
        $this->assertValidId(
            $idPermiso
        );

        $row = $this->database
            ->callProcedureOne(
                self::PROCEDURE,
                [
                    'Accion' => self::ACTION_DEACTIVATE,

                    'IdPermiso' => $idPermiso,
                ]
            );

        return $this->affectedRows(
            $row
        ) > 0;
    }

    /**
     * Reactiva un permiso.
     */
    public function activate(
        int $idPermiso
    ): bool {
        $this->assertValidId(
            $idPermiso
        );

        $row = $this->database
            ->callProcedureOne(
                self::PROCEDURE,
                [
                    'Accion' => self::ACTION_ACTIVATE,

                    'IdPermiso' => $idPermiso,
                ]
            );

        return $this->affectedRows(
            $row
        ) > 0;
    }

    /**
     * Convierte una fila del SP en Permiso.
     *
     * @param  array<string, mixed>  $row
     */
    private function mapRow(
        array $row
    ): Permiso {
        $required = [
            'IdPermiso',
            'Clave',
            'Descripcion',
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
                        'La columna "%s" no fue devuelta '
                        .'por sp_catcPermisos.',
                        $column
                    )
                );
            }
        }

        $idPermiso =
            (int) $row['IdPermiso'];

        if ($idPermiso <= 0) {
            throw new RuntimeException(
                'El IdPermiso recuperado '
                .'no es válido.'
            );
        }

        $clave =
            trim(
                (string) $row['Clave']
            );

        $descripcion =
            trim(
                (string) $row['Descripcion']
            );

        if ($clave === '') {
            throw new RuntimeException(
                'La Clave recuperada '
                .'no puede estar vacía.'
            );
        }

        if ($descripcion === '') {
            throw new RuntimeException(
                'La Descripcion recuperada '
                .'no puede estar vacía.'
            );
        }

        return new Permiso(
            $idPermiso,
            $clave,
            $descripcion,
            $this->toBool(
                $row['Vigente']
            )
        );
    }

    /**
     * Recupera FilasAfectadas.
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
                'El procedimiento almacenado '
                .'no devolvió FilasAfectadas.'
            );
        }

        $affected =
            (int) $row['FilasAfectadas'];

        if ($affected < 0) {
            throw new RuntimeException(
                'El número de filas afectadas '
                .'devuelto no es válido.'
            );
        }

        return $affected;
    }

    /**
     * Convierte SQL Server BIT a booleano PHP.
     */
    private function toBool(
        mixed $value
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
        ) {
            return false;
        }

        throw new RuntimeException(
            'El valor Vigente devuelto '
            .'por SQL Server no es válido.'
        );
    }

    /**
     * Valida IDs antes de ejecutar el SP.
     */
    private function assertValidId(
        int $idPermiso
    ): void {
        if ($idPermiso <= 0) {
            throw new InvalidArgumentException(
                'El IdPermiso debe ser '
                .'mayor que cero.'
            );
        }
    }
}
