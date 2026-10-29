<?php

declare(strict_types=1);

namespace App\Repositories\Catalogos;

use App\Models\Catalogos\TipoUsuario;
use App\Support\Database;
use InvalidArgumentException;
use RuntimeException;

final class TipoUsuarioRepository
{
    private const PROCEDURE =
        'dbo.sp_catcTiposUsuarios';

    private const ACTION_CREATE = 1;

    private const ACTION_DEACTIVATE = 2;

    private const ACTION_UPDATE = 3;

    private const ACTION_FIND_ALL = 4;

    private const ACTION_FIND_BY_ID = 5;

    private const ACTION_ACTIVATE = 6;

    private const ACTION_FIND_BY_DESCRIPTION = 7;

    private const ACTION_FIND_ACTIVE = 8;

    public function __construct(
        private Database $database
    ) {}

    /**
     * Obtiene el catálogo completo.
     *
     * @return array<int, TipoUsuario>
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
     * LISTADO DE TIPOS DE USUARIO VIGENTES OPC 8
     * =========================================================
     *
     * @return TipoUsuario[]
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
     * Busca un tipo de usuario por ID.
     */
    public function findById(
        int $idTipoUsuario
    ): ?TipoUsuario {
        $this->assertValidId(
            $idTipoUsuario
        );

        $row = $this->database
            ->callProcedureOne(
                self::PROCEDURE,
                [
                    'Accion' => self::ACTION_FIND_BY_ID,

                    'IdTipoUsuario' => $idTipoUsuario,
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
     * Busca un tipo de usuario
     * por su descripción.
     */
    public function findByDescription(
        string $descripcion
    ): ?TipoUsuario {
        $descripcion = trim(
            $descripcion
        );

        if ($descripcion === '') {
            throw new InvalidArgumentException(
                'La descripción no puede estar vacía.'
            );
        }

        $row = $this->database
            ->callProcedureOne(
                self::PROCEDURE,
                [
                    'Accion' => self::ACTION_FIND_BY_DESCRIPTION,

                    'Descripcion' => $descripcion,
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
     * Registra un nuevo tipo de usuario.
     *
     * Devuelve el IdTipoUsuario generado.
     */
    public function create(
        string $descripcion
    ): int {
        $row = $this->database
            ->callProcedureOne(
                self::PROCEDURE,
                [
                    'Accion' => self::ACTION_CREATE,

                    'Descripcion' => $descripcion,

                    'Vigente' => 1,
                ]
            );

        if (
            $row === null
            || ! array_key_exists(
                'IdTipoUsuario',
                $row
            )
        ) {
            throw new RuntimeException(
                'El procedimiento almacenado '
                .'no devolvió el IdTipoUsuario '
                .'generado.'
            );
        }

        $idTipoUsuario =
            (int) $row['IdTipoUsuario'];

        if ($idTipoUsuario <= 0) {
            throw new RuntimeException(
                'El IdTipoUsuario generado '
                .'no es válido.'
            );
        }

        return $idTipoUsuario;
    }

    /**
     * Modifica la descripción.
     *
     * Vigente NO se modifica mediante update().
     */
    public function update(
        int $idTipoUsuario,
        string $descripcion
    ): bool {
        $this->assertValidId(
            $idTipoUsuario
        );

        $row = $this->database
            ->callProcedureOne(
                self::PROCEDURE,
                [
                    'Accion' => self::ACTION_UPDATE,

                    'IdTipoUsuario' => $idTipoUsuario,

                    'Descripcion' => $descripcion,
                ]
            );

        return $this->affectedRows(
            $row
        ) > 0;
    }

    /**
     * Realiza la baja lógica.
     */
    public function deactivate(
        int $idTipoUsuario
    ): bool {
        $this->assertValidId(
            $idTipoUsuario
        );

        $row = $this->database
            ->callProcedureOne(
                self::PROCEDURE,
                [
                    'Accion' => self::ACTION_DEACTIVATE,

                    'IdTipoUsuario' => $idTipoUsuario,
                ]
            );

        return $this->affectedRows(
            $row
        ) > 0;
    }

    /**
     * Reactiva un tipo de usuario.
     */
    public function activate(
        int $idTipoUsuario
    ): bool {
        $this->assertValidId(
            $idTipoUsuario
        );

        $row = $this->database
            ->callProcedureOne(
                self::PROCEDURE,
                [
                    'Accion' => self::ACTION_ACTIVATE,

                    'IdTipoUsuario' => $idTipoUsuario,
                ]
            );

        return $this->affectedRows(
            $row
        ) > 0;
    }

    /**
     * Convierte una fila SQL Server
     * en TipoUsuario.
     *
     * @param  array<string, mixed>  $row
     */
    private function mapRow(
        array $row
    ): TipoUsuario {
        $required = [
            'IdTipoUsuario',
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
                        'La columna "%s" no fue '
                        .'devuelta por el '
                        .'procedimiento almacenado.',
                        $column
                    )
                );
            }
        }

        $idTipoUsuario =
            (int) $row['IdTipoUsuario'];

        if ($idTipoUsuario <= 0) {
            throw new RuntimeException(
                'El IdTipoUsuario recuperado '
                .'no es válido.'
            );
        }

        return new TipoUsuario(
            $idTipoUsuario,

            trim(
                (string) $row['Descripcion']
            ),

            $this->toBool(
                $row['Vigente']
            )
        );
    }

    /**
     * Recupera FilasAfectadas de las
     * operaciones de modificación.
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
     * Convierte SQL Server BIT
     * a booleano PHP.
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
            'El valor de Vigente devuelto '
            .'por SQL Server no es válido.'
        );
    }

    /**
     * Valida identificadores antes de
     * ejecutar el procedimiento.
     */
    private function assertValidId(
        int $idTipoUsuario
    ): void {
        if ($idTipoUsuario <= 0) {
            throw new InvalidArgumentException(
                'El IdTipoUsuario debe ser '
                .'mayor que cero.'
            );
        }
    }
}
