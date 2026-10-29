<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;
use InvalidArgumentException;
use RuntimeException;

final class UsuarioLdapRepository
{
    private const PROCEDURE =
        'dbo.sp_catcUsuariosLdap';

    private const ACTION_CREATE = 1;

    private const ACTION_UPDATE = 3;

    public function __construct(
        private Database $database
    ) {}

    /**
     * Registra en SQL Server un usuario
     * previamente creado en OpenLDAP.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): int
    {
        $this->validateCreateData($data);

        $row = $this->database
            ->callProcedureOne(
                self::PROCEDURE,
                [
                    'Accion' => self::ACTION_CREATE,

                    'dn' => trim((string) $data['dn']),

                    'Uid' => trim((string) $data['uid']),

                    'sn' => trim((string) $data['sn']),

                    'givenName' => trim((string) $data['givenName']),

                    'cn' => trim((string) $data['cn']),

                    'uIdNumber' => (int) $data['uidNumber'],

                    'gIdNumber' => (int) $data['gidNumber'],

                    'employeeNumber' => (int) $data['employeeNumber'],

                    'userPassword' => (string) $data['userPassword'],

                    'mail' => trim((string) $data['mail']),

                    'Origen' => trim((string) $data['origen']),

                    'IdUsuario' => (int) $data['idUsuario'],
                ]
            );

        if (
            $row === null
            || ! array_key_exists(
                'IdUsuarioLdap',
                $row
            )
        ) {
            throw new RuntimeException(
                'El procedimiento almacenado '
                .'no devolvió el IdUsuarioLdap '
                .'generado.'
            );
        }

        $idUsuarioLdap =
            (int) $row['IdUsuarioLdap'];

        if ($idUsuarioLdap <= 0) {
            throw new RuntimeException(
                'El IdUsuarioLdap generado '
                .'no es válido.'
            );
        }

        return $idUsuarioLdap;
    }

    /**
     * Actualiza en SQL Server los datos modificables
     * de un usuario LDAP.
     *
     * El uid, dn, uidNumber, gidNumber, Origen,
     * Fecha_Registro y Vigente no son modificados.
     *
     * userPassword es opcional. Si es null o vacío,
     * el procedimiento conserva el cifrado existente.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(
        int $idUsuarioLdap,
        array $data
    ): int {
        if ($idUsuarioLdap <= 0) {
            throw new InvalidArgumentException(
                'El IdUsuarioLdap debe ser mayor que cero.'
            );
        }

        $this->validateUpdateData(
            $data
        );

        $row = $this->database
            ->callProcedureOne(
                self::PROCEDURE,
                [
                    'Accion' => self::ACTION_UPDATE,

                    'IdUsuarioLdap' => $idUsuarioLdap,

                    'sn' => trim(
                        (string) $data['sn']
                    ),

                    'givenName' => trim(
                        (string) $data['givenName']
                    ),

                    'cn' => trim(
                        (string) $data['cn']
                    ),

                    'employeeNumber' => (int) $data['employeeNumber'],

                    /*
                    * null significa:
                    * conservar userPassword actual.
                    */
                    'userPassword' => $data['userPassword'] ?? null,

                    'mail' => trim(
                        (string) $data['mail']
                    ),

                ]
            );

        if (
            $row === null
            || ! array_key_exists(
                'IdUsuarioLdap',
                $row
            )
        ) {
            throw new RuntimeException(
                'El procedimiento almacenado '
                .'no devolvió el IdUsuarioLdap '
                .'actualizado.'
            );
        }

        $updatedId =
            (int) $row['IdUsuarioLdap'];

        if ($updatedId !== $idUsuarioLdap) {
            throw new RuntimeException(
                'El IdUsuarioLdap devuelto por '
                .'el procedimiento no coincide '
                .'con el usuario actualizado.'
            );
        }

        return $updatedId;
    }

    /**
     * Consulta un usuario LDAP registrado en SQL Server
     * mediante la Acción 5 del procedimiento almacenado.
     *
     * No devuelve la contraseña del usuario.
     *
     * @return array<string, mixed>|null
     */
    public function findByUid(string $uid): ?array
    {
        $uid = strtolower(trim($uid));

        if ($uid === '') {
            throw new InvalidArgumentException(
                'Debe proporcionar el UID del usuario LDAP.'
            );
        }

        if (
            preg_match('/^[A-Za-z0-9!_]{1,40}$/', $uid) !== 1
        ) {
            throw new InvalidArgumentException(
                'El UID contiene caracteres no permitidos.'
            );
        }

        $row = $this->database->callProcedureOne(
            self::PROCEDURE,
            [
                'Accion' => 5,
                'Uid' => $uid,
            ]
        );

        if ($row === null) {
            return null;
        }

        // Defensa adicional: nunca devolver contraseñas
        // al controlador, aunque cambie el procedimiento.
        unset($row['userPassword']);

        if (
            ! isset($row['IdUsuarioLdap'])
            || (int) $row['IdUsuarioLdap'] <= 0
        ) {
            throw new RuntimeException(
                'La consulta LDAP no devolvió '
                .'un IdUsuarioLdap válido.'
            );
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validateCreateData(
        array $data
    ): void {
        $required = [
            'dn',
            'uid',
            'sn',
            'givenName',
            'cn',
            'uidNumber',
            'gidNumber',
            'employeeNumber',
            'userPassword',
            'mail',
            'origen',
            'idUsuario',
        ];

        foreach ($required as $field) {
            if (! array_key_exists($field, $data)) {
                throw new InvalidArgumentException(
                    sprintf(
                        'El campo "%s" es requerido '
                        .'para registrar el usuario LDAP.',
                        $field
                    )
                );
            }
        }

        $stringFields = [
            'dn',
            'uid',
            'sn',
            'givenName',
            'cn',
            'userPassword',
            'mail',
            'origen',
        ];

        foreach ($stringFields as $field) {
            if (
                trim((string) $data[$field]) === ''
            ) {
                throw new InvalidArgumentException(
                    sprintf(
                        'El campo "%s" no puede '
                        .'estar vacío.',
                        $field
                    )
                );
            }
        }

        if ((int) $data['uidNumber'] <= 0) {
            throw new InvalidArgumentException(
                'El uidNumber debe ser mayor que cero.'
            );
        }

        if ((int) $data['gidNumber'] <= 0) {
            throw new InvalidArgumentException(
                'El gidNumber debe ser mayor que cero.'
            );
        }

        if ((int) $data['employeeNumber'] <= 0) {
            throw new InvalidArgumentException(
                'El employeeNumber debe ser mayor que cero.'
            );
        }

        if ((int) $data['idUsuario'] <= 0) {
            throw new InvalidArgumentException(
                'El IdUsuario debe ser mayor que cero.'
            );
        }
    }

    /**
     * Valida los datos requeridos para actualizar
     * un usuario LDAP.
     *
     * @param  array<string, mixed>  $data
     */
    private function validateUpdateData(
        array $data
    ): void {
        $required = [
            'sn',
            'givenName',
            'cn',
            'employeeNumber',
            'mail',
        ];

        foreach ($required as $field) {
            if (! array_key_exists($field, $data)) {
                throw new InvalidArgumentException(
                    sprintf(
                        'El campo "%s" es requerido '
                        .'para actualizar el usuario LDAP.',
                        $field
                    )
                );
            }
        }

        foreach (
            [
                'sn',
                'givenName',
                'cn',
                'mail',
            ] as $field
        ) {
            if (
                trim(
                    (string) $data[$field]
                ) === ''
            ) {
                throw new InvalidArgumentException(
                    sprintf(
                        'El campo "%s" no puede estar vacío.',
                        $field
                    )
                );
            }
        }

        if (
            (int) $data['employeeNumber']
            <= 0
        ) {
            throw new InvalidArgumentException(
                'El employeeNumber debe ser mayor que cero.'
            );
        }

        /*
        * userPassword es opcional.
        *
        * null = conservar el valor existente
        * en catcUsuariosLdap.
        */
        if (
            array_key_exists(
                'userPassword',
                $data
            )
            && $data['userPassword'] !== null
            && ! is_string(
                $data['userPassword']
            )
        ) {
            throw new InvalidArgumentException(
                'El userPassword debe ser una cadena válida.'
            );
        }
    }
}
