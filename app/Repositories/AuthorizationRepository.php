<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;
use RuntimeException;

final class AuthorizationRepository
{
    private const PROCEDURE =
        'sp_AutorizacionPermisos';

    private Database $database;

    public function __construct(
        Database $database
    ) {
        $this->database = $database;
    }

    /**
     * Obtiene las claves de los permisos efectivos
     * asignados a un tipo de usuario.
     *
     * Un permiso efectivo requiere:
     *
     * - Tipo de usuario vigente.
     * - Relación TipoUsuario-Permiso vigente.
     * - Permiso vigente.
     *
     * @return array<int, string>
     */
    public function permissionsByTipoUsuario(
        int $idTipoUsuario
    ): array {
        if ($idTipoUsuario < 1) {
            throw new RuntimeException(
                'El IdTipoUsuario no es válido.'
            );
        }

        $rows = $this->database->fetchAll(
            $this->procedureSql(),
            [
                'IdTipoUsuario' => $idTipoUsuario,
            ]
        );

        $permissions = [];

        foreach ($rows as $row) {
            if (! array_key_exists('Clave', $row)) {
                throw new RuntimeException(
                    'La consulta de autorización no devolvió '
                    .'la columna requerida "Clave".'
                );
            }

            $clave = trim(
                (string) $row['Clave']
            );

            if ($clave === '') {
                throw new RuntimeException(
                    'La consulta de autorización devolvió '
                    .'una clave de permiso vacía.'
                );
            }

            $permissions[] = $clave;
        }

        /*
         * Eliminamos duplicados defensivamente.
         *
         * La BD debería garantizar la unicidad de la relación,
         * pero AuthorizationRepository no debe depender de ello.
         */
        return array_values(
            array_unique(
                $permissions
            )
        );
    }

    /**
     * SQL para ejecutar el procedimiento de autorización.
     */
    private function procedureSql(): string
    {
        return '
            EXEC '.self::PROCEDURE.'
                @IdTipoUsuario = :IdTipoUsuario
        ';
    }
}
