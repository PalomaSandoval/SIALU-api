<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\DatabaseSession;
use App\Models\SessionStatus;
use App\Support\Config;
use App\Support\Database;
use App\Support\Logger;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Repositorio de sesiones de SGUU.
 *
 * Toda interacción con SQL Server se realiza exclusivamente
 * mediante procedimientos almacenados.
 *
 * Responsabilidades:
 *
 * - Crear movcSesiones.
 * - Consultar una sesión.
 * - Cerrar una sesión.
 * - Expirar una sesión.
 */
final class SesionRepository
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
     * Crea una nueva sesión ACTIVA.
     */
    public function create(
        int $idUsuario,
        string $ip,
        string $host,
        ?string $mac = null
    ): DatabaseSession {
        if ($idUsuario < 1) {
            throw new InvalidArgumentException(
                'IdUsuario debe ser mayor que cero.'
            );
        }

        $ip = $this->validateIp($ip);
        $host = $this->sanitizeHost($host);
        $mac = $this->sanitizeMac($mac);

        $procedure = $this->sessionsProcedure();

        $action = $this->sessionAction(
            'create'
        );

        try {
            $row = $this->database->callProcedureOne(
                $procedure,
                [
                    'Accion' => $action,
                    'IdUsuario' => $idUsuario,
                    'IP' => $ip,
                    'HOST' => $host,
                    'MAC' => $mac,
                    /*'Estatus_Sesion' =>
                        SessionStatus::ACTIVE,*/
                ]
            );

            if ($row === null) {
                throw new RuntimeException(
                    'El procedimiento no devolvió la sesión creada.'
                );
            }

            $session = $this->mapSession($row);

            if (! $session->activa()) {
                throw new RuntimeException(
                    'La sesión creada no quedó con estado ACTIVA.'
                );
            }

            $this->logger->audit(
                'Sesión de aplicación creada.',
                [
                    'IdSesion' => $session->idSesion(),
                    'IdUsuario' => $idUsuario,
                    'ip' => $ip,
                    'host' => $host,
                    'status' => SessionStatus::ACTIVE,
                ]
            );

            return $session;
        } catch (Throwable $exception) {
            $this->logger->database(
                'No fue posible crear movcSesiones.',
                [
                    'IdUsuario' => $idUsuario,
                    'procedure' => $procedure,
                    'action' => $action,
                    'exception' => get_class($exception),
                    'message' => $exception->getMessage(),
                ],
                'error'
            );

            throw new RuntimeException(
                'No fue posible registrar la sesión del usuario.',
                0,
                $exception
            );
        }
    }

    /**
     * Busca una sesión por IdSesion.
     */
    public function find(
        int $idSesion
    ): ?DatabaseSession {
        $this->validateSessionId(
            $idSesion
        );

        $procedure = $this->sessionsProcedure();

        $action = $this->sessionAction(
            'find'
        );

        try {
            $row = $this->database->callProcedureOne(
                $procedure,
                [
                    /* 'Accion' => $action, */
                    'Accion' => 5,
                    'IdSesion' => $idSesion,
                ]
            );

            if ($row === null) {
                return null;
            }

            return $this->mapSession($row);
        } catch (Throwable $exception) {
            $this->logger->database(
                'No fue posible consultar la sesión.',
                [
                    'IdSesion' => $idSesion,
                    'procedure' => $procedure,
                    'action' => $action,
                    'exception' => get_class($exception),
                    'message' => $exception->getMessage(),
                ],
                'error'
            );

            throw new RuntimeException(
                'No fue posible consultar la sesión.',
                0,
                $exception
            );
        }
    }

    /**
     * Cierra voluntariamente una sesión.
     */
    public function close(
        int $idSesion
    ): DatabaseSession {
        return $this->finish(
            $idSesion,
            SessionStatus::CLOSED,
            'close'
        );
    }

    /**
     * Expira una sesión por inactividad.
     */
    public function expire(
        int $idSesion
    ): DatabaseSession {
        return $this->finish(
            $idSesion,
            SessionStatus::EXPIRED,
            'expire'
        );
    }

    /**
     * Finaliza una sesión.
     *
     * Fecha_Fin y TiempoSesion deben calcularse en SQL Server,
     * no en PHP, para utilizar una única fuente de tiempo.
     */
    private function finish(
        int $idSesion,
        string $status,
        string $actionName
    ): DatabaseSession {
        $this->validateSessionId(
            $idSesion
        );

        $status = SessionStatus::normalize(
            $status
        );

        if ($status === SessionStatus::ACTIVE) {
            throw new InvalidArgumentException(
                'Una sesión finalizada no puede quedar ACTIVA.'
            );
        }

        $procedure = $this->sessionsProcedure();

        $action = $this->sessionAction(
            $actionName
        );

        try {
            /*
             * Primero consultamos la sesión para impedir modificar
             * accidentalmente una sesión ya finalizada.
             */
            $currentSession = $this->find(
                $idSesion
            );

            if ($currentSession === null) {
                throw new RuntimeException(
                    sprintf(
                        'La sesión %d no existe.',
                        $idSesion
                    )
                );
            }

            if (! $currentSession->activa()) {
                /*
                 * Operación idempotente:
                 * si ya terminó, regresamos su estado actual.
                 */
                return $currentSession;
            }

            $row = $this->database->callProcedureOne(
                $procedure,
                [
                    'Accion' => $action,
                    'IdSesion' => $idSesion,
                    /* 'Estatus_Sesion' => $status, */
                ]
            );

            if ($row === null) {
                throw new RuntimeException(
                    'El procedimiento no devolvió la sesión actualizada.'
                );
            }

            $session = $this->mapSession(
                $row
            );

            if ($session->estatusSesion() !== $status) {
                throw new RuntimeException(
                    'El estado devuelto por SQL Server no coincide '
                    .'con el solicitado.'
                );
            }

            $this->logger->audit(
                'Sesión de aplicación finalizada.',
                [
                    'IdSesion' => $session->idSesion(),
                    'IdUsuario' => $session->idUsuario(),
                    'status' => $session->estatusSesion(),
                    'TiempoSesion' => $session->tiempoSesion(),
                ]
            );

            return $session;
        } catch (Throwable $exception) {
            $this->logger->database(
                'No fue posible finalizar movcSesiones.',
                [
                    'IdSesion' => $idSesion,
                    'status' => $status,
                    'procedure' => $procedure,
                    'action' => $action,
                    'exception' => get_class($exception),
                    'message' => $exception->getMessage(),
                ],
                'error'
            );

            throw new RuntimeException(
                'No fue posible finalizar la sesión.',
                0,
                $exception
            );
        }
    }

    /**
     * Convierte una fila SQL en DatabaseSession.
     *
     * @param  array<string, mixed>  $row
     */
    private function mapSession(
        array $row
    ): DatabaseSession {
        $row = $this->normalizeRow(
            $row
        );

        /*
         * Algunos diseños anteriores utilizaban IsSesion.
         * Admitimos ambos nombres mientras se consolida el esquema.
         */
        $idSesion =
            $row['idsesion']
            ?? $row['issesion']
            ?? null;

        if (
            $idSesion === null
            || ! is_numeric($idSesion)
            || (int) $idSesion < 1
        ) {
            throw new RuntimeException(
                'El procedimiento no devolvió IdSesion válido.'
            );
        }

        if (
            ! isset($row['idusuario'])
            || ! is_numeric($row['idusuario'])
        ) {
            throw new RuntimeException(
                'El procedimiento no devolvió IdUsuario válido.'
            );
        }

        $status =
            $row['estatus_sesion']
            ?? $row['estatussesion']
            ?? null;

        if (
            ! is_string($status)
            || trim($status) === ''
        ) {
            throw new RuntimeException(
                'El procedimiento no devolvió Estatus_Sesion.'
            );
        }

        return new DatabaseSession(
            (int) $idSesion,

            (int) $row['idusuario'],

            (string) ($row['ip'] ?? ''),

            (string) ($row['host'] ?? ''),

            isset($row['mac'])
                ? (string) $row['mac']
                : null,

            $this->dateToString(
                $row['fecha_inicio'] ??
                $row['fechainicio'] ??
                ''
            ),

            $this->nullableDateToString(
                $row['fecha_fin'] ??
                $row['fechafin'] ??
                null
            ),

            max(
                0,
                (int) (
                    $row['tiemposesion']
                    ?? $row['tiempo_sesion']
                    ?? 0
                )
            ),

            $status
        );
    }

    /**
     * Normaliza los nombres de columnas a minúsculas.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeRow(
        array $row
    ): array {
        $normalized = [];

        foreach ($row as $key => $value) {
            $normalized[
                strtolower((string) $key)
            ] = $value;
        }

        return $normalized;
    }

    /**
     * Procedimiento principal de sesiones.
     */
    private function sessionsProcedure(): string
    {
        return (string) $this->config->getRequired(
            'sialu.procedures.sessions'
        );
    }

    /**
     * Obtiene el número de acción configurado.
     */
    private function sessionAction(
        string $action
    ): int {
        $value = (int) $this->config->getRequired(
            'sialu.session_actions.'.$action
        );

        if ($value < 1) {
            throw new RuntimeException(
                sprintf(
                    'La acción de sesión "%s" no es válida.',
                    $action
                )
            );
        }

        return $value;
    }

    private function validateSessionId(
        int $idSesion
    ): void {
        if ($idSesion < 1) {
            throw new InvalidArgumentException(
                'IdSesion debe ser mayor que cero.'
            );
        }
    }

    private function validateIp(
        string $ip
    ): string {
        $ip = trim($ip);

        if (
            filter_var(
                $ip,
                FILTER_VALIDATE_IP
            ) === false
        ) {
            throw new InvalidArgumentException(
                'La dirección IP no es válida.'
            );
        }

        return $ip;
    }

    private function sanitizeHost(
        string $host
    ): string {
        $host = trim($host);

        if ($host === '') {
            return 'DESCONOCIDO';
        }

        /*
         * Limita el tamaño para evitar datos anómalos.
         */
        return substr(
            $host,
            0,
            80
        );
    }

    private function sanitizeMac(
        ?string $mac
    ): ?string {
        if ($mac === null) {
            return null;
        }

        $mac = trim($mac);

        if ($mac === '') {
            return null;
        }

        if (
            preg_match(
                '/^([0-9A-Fa-f]{2}[:-]){5}'
                .'[0-9A-Fa-f]{2}$/',
                $mac
            ) !== 1
        ) {
            return null;
        }

        return strtoupper(
            str_replace(
                '-',
                ':',
                $mac
            )
        );
    }

    /**
     * @param  mixed  $value
     */
    private function dateToString(
        $value
    ): string {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(
                'Y-m-d H:i:s'
            );
        }

        return trim(
            (string) $value
        );
    }

    /**
     * @param  mixed  $value
     */
    private function nullableDateToString(
        $value
    ): ?string {
        if (
            $value === null
            || $value === ''
        ) {
            return null;
        }

        return $this->dateToString(
            $value
        );
    }
}
