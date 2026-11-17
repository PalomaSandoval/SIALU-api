<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\ApplicationUser;
use App\Support\Config;
use App\Support\Database;
use App\Support\Logger;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Acceso a usuarios de la aplicación SGUU.
 *
 * Toda consulta se realiza mediante procedimientos almacenados.
 */
final class UsuarioRepository
{
    private Database $database;

    private Config $config;

    private Logger $logger;

    public function __construct(
        Database $database,
        Config $config,
        Logger $logger
    ) {
        $this->database = $database;
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Busca un usuario de la aplicación por uid.
     */
    public function findByUid(
        string $uid
    ): ?ApplicationUser {
        $uid = $this->validateUid($uid);

        $procedure = (string) $this->config->getRequired(
            'sialu.procedures.find_user'
        );

        try {
            /*
             $row = $this->database->callProcedureOne(
                 $procedure,
                 [
                     'Uid' => $uid,
                 ]
             );
             */

            $row = $this->database->callProcedureOne(
                $procedure,
                [
                    'Accion' => 7,
                    'Uid' => $uid,
                ]
            );

            if ($row === null) {
                $this->logger->database(
                    'El usuario LDAP no está registrado en SGUU.',
                    [
                        'uid' => $uid,
                        'procedure' => $procedure,
                        'action' => 7,
                    ],
                    'info'
                );

                return null;
            }

            $user = $this->mapUser($row);

            $this->logger->database(
                'Usuario de SGUU localizado correctamente.',
                [
                    'uid' => $user->uid(),
                    'IdUsuario' => $user->idUsuario(),
                    'IdTipoUsuario' => $user->idTipoUsuario(),
                    'TipoUsuario' => $user->tipoUsuario(),
                    'Bloqueado' => $user->bloqueado(),
                    'Vigente' => $user->vigente(),
                    'action' => 7,
                ],
                'debug'
            );

            return $user;
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->logger->database(
                'Error al consultar el usuario de SGUU.',
                [
                    'uid' => $uid,
                    'procedure' => $procedure,
                    'action' => 7,
                    'exception' => get_class($exception),
                    'message' => $exception->getMessage(),
                ],
                'error'
            );

            throw new RuntimeException(
                'No fue posible consultar el usuario de la aplicación.',
                0,
                $exception
            );
        }
    }

    /**
     * Busca un usuario y valida que tenga acceso habilitado.
     *
     * Retorna null únicamente cuando el uid no está registrado.
     */
    public function findAuthorizedByUid(
        string $uid
    ): ?ApplicationUser {
        $user = $this->findByUid($uid);

        if ($user === null) {
            return null;
        }

        if (! $user->vigente()) {
            throw new RuntimeException(
                'Usuario no válido.'
            );
        }

        if ($user->bloqueado()) {
            throw new RuntimeException(
                'El usuario de la aplicación está bloqueado.'
            );
        }

        if (! $user->tipoUsuarioVigente()) {
            throw new RuntimeException(
                'El tipo de usuario no se encuentra vigente.'
            );
        }

        return $user;
    }

    /**
     * Convierte una fila SQL Server en ApplicationUser.
     *
     * @param  array<string, mixed>  $row
     */
    private function mapUser(
        array $row
    ): ApplicationUser {
        $normalized = [];

        foreach ($row as $key => $value) {
            $normalized[
                strtolower((string) $key)
            ] = $value;
        }

        $required = [
            'idusuario',
            'idtipousuario',
            'tipousuario',
            'uid',
            'vigente',
            'bloqueado',
        ];

        foreach ($required as $field) {
            if (! array_key_exists($field, $normalized)) {
                throw new RuntimeException(
                    sprintf(
                        'El procedimiento de usuarios no devolvió '
                        .'el campo obligatorio "%s".',
                        $field
                    )
                );
            }
        }

        return new ApplicationUser(
            $this->toPositiveInteger(
                $normalized['idusuario'],
                'IdUsuario'
            ),

            $this->toPositiveInteger(
                $normalized['idtipousuario'],
                'IdTipoUsuario'
            ),

            (string) $normalized['tipousuario'],

            (string) $normalized['uid'],

            (string) (
                $normalized['no_empleado']
                ?? $normalized['noempleado']
                ?? ''
            ),

            (string) ($normalized['nombre'] ?? ''),

            (string) (
                $normalized['primer_apellido']
                ?? $normalized['primerapellido']
                ?? ''
            ),

            (string) (
                $normalized['segundo_apellido']
                ?? $normalized['segundoapellido']
                ?? ''
            ),

            (string) ($normalized['email'] ?? ''),

            isset($normalized['extension'])
                ? (string) $normalized['extension']
                : null,

            max(
                0,
                (int) ($normalized['intentos'] ?? 0)
            ),

            $this->toBoolean(
                $normalized['bloqueado']
            ),

            $this->toBoolean(
                $normalized['vigente']
            ),

            $this->toBoolean(
                $normalized['tipousuariovigente']
                ?? true
            )
        );
    }

    private function validateUid(
        string $uid
    ): string {
        $uid = trim($uid);

        if ($uid === '') {
            throw new InvalidArgumentException(
                'El uid es obligatorio.'
            );
        }

        if (strlen($uid) > 40) {
            throw new InvalidArgumentException(
                'El uid excede la longitud permitida.'
            );
        }

        if (
            preg_match(
                '/^[A-Za-z0-9!_]+$/',
                $uid
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'El formato del uid no es válido.'
            );
        }

        return $uid;
    }

    /**
     * @param  mixed  $value
     */
    private function toPositiveInteger(
        $value,
        string $field
    ): int {
        if (
            ! is_numeric($value)
            || (int) $value < 1
        ) {
            throw new RuntimeException(
                sprintf(
                    'El campo "%s" no contiene un entero válido.',
                    $field
                )
            );
        }

        return (int) $value;
    }

    /**
     * Convierte valores bit, booleanos o textos comunes.
     *
     * @param  mixed  $value
     */
    private function toBoolean($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        $normalized = strtolower(
            trim((string) $value)
        );

        return in_array(
            $normalized,
            [
                '1',
                'true',
                'yes',
                'si',
                'sí',
                'activo',
                'vigente',
            ],
            true
        );
    }
}
