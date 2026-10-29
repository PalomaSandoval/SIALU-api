<?php

declare(strict_types=1);

namespace App\Models;

use InvalidArgumentException;

/**
 * Representa un registro de movcSesiones.
 */
final class DatabaseSession
{
    private int $idSesion;

    private int $idUsuario;

    private string $ip;

    private string $host;

    private ?string $mac;

    private string $fechaInicio;

    private ?string $fechaFin;

    private int $tiempoSesion;

    private string $estatusSesion;

    public function __construct(
        int $idSesion,
        int $idUsuario,
        string $ip,
        string $host,
        ?string $mac,
        string $fechaInicio,
        ?string $fechaFin,
        int $tiempoSesion,
        string $estatusSesion
    ) {
        if ($idSesion < 1) {
            throw new InvalidArgumentException(
                'IdSesion debe ser mayor que cero.'
            );
        }

        if ($idUsuario < 1) {
            throw new InvalidArgumentException(
                'IdUsuario debe ser mayor que cero.'
            );
        }

        $estatusSesion = SessionStatus::normalize(
            $estatusSesion
        );

        $this->idSesion = $idSesion;
        $this->idUsuario = $idUsuario;
        $this->ip = trim($ip);
        $this->host = trim($host);

        $this->mac = $mac !== null && trim($mac) !== ''
            ? trim($mac)
            : null;

        $this->fechaInicio = trim($fechaInicio);

        $this->fechaFin =
            $fechaFin !== null && trim($fechaFin) !== ''
                ? trim($fechaFin)
                : null;

        $this->tiempoSesion = max(
            0,
            $tiempoSesion
        );

        $this->estatusSesion = $estatusSesion;
    }

    public function idSesion(): int
    {
        return $this->idSesion;
    }

    public function idUsuario(): int
    {
        return $this->idUsuario;
    }

    public function ip(): string
    {
        return $this->ip;
    }

    public function host(): string
    {
        return $this->host;
    }

    public function mac(): ?string
    {
        return $this->mac;
    }

    public function fechaInicio(): string
    {
        return $this->fechaInicio;
    }

    public function fechaFin(): ?string
    {
        return $this->fechaFin;
    }

    public function tiempoSesion(): int
    {
        return $this->tiempoSesion;
    }

    public function estatusSesion(): string
    {
        return $this->estatusSesion;
    }

    public function activa(): bool
    {
        return $this->estatusSesion
            === SessionStatus::ACTIVE;
    }

    public function cerrada(): bool
    {
        return $this->estatusSesion
            === SessionStatus::CLOSED;
    }

    public function expirada(): bool
    {
        return $this->estatusSesion
            === SessionStatus::EXPIRED;
    }
}
