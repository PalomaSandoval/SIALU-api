<?php

declare(strict_types=1);

namespace App\Models;

use InvalidArgumentException;

/**
 * Datos del usuario y su autorización devueltos por SGUU.
 */
final class ApplicationUser
{
    public function __construct(
        private int $idUsuario,
        private int $idTipoUsuario,
        private string $tipoUsuario,
        private string $uid,
        private string $noEmpleado,
        private string $nombre,
        private string $primerApellido,
        private string $segundoApellido,
        private string $email,
        private ?string $extension,
        private int $intentos,
        private bool $bloqueado,
        private bool $vigente,
        private bool $tipoUsuarioVigente
    ) {
        if ($idUsuario < 1 || $idTipoUsuario < 1) {
            throw new InvalidArgumentException(
                'Los identificadores del usuario y su tipo deben ser positivos.'
            );
        }

        $this->uid = trim($uid);
        $this->tipoUsuario = trim($tipoUsuario);

        if ($this->uid === '' || $this->tipoUsuario === '') {
            throw new InvalidArgumentException(
                'El uid y el tipo de usuario son obligatorios.'
            );
        }

        $this->intentos = max(0, $intentos);
    }

    public function idUsuario(): int
    {
        return $this->idUsuario;
    }

    public function idTipoUsuario(): int
    {
        return $this->idTipoUsuario;
    }

    public function tipoUsuario(): string
    {
        return $this->tipoUsuario;
    }

    public function uid(): string
    {
        return $this->uid;
    }

    public function noEmpleado(): string
    {
        return $this->noEmpleado;
    }

    public function nombre(): string
    {
        return $this->nombre;
    }

    public function primerApellido(): string
    {
        return $this->primerApellido;
    }

    public function segundoApellido(): string
    {
        return $this->segundoApellido;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function extension(): ?string
    {
        return $this->extension;
    }

    public function intentos(): int
    {
        return $this->intentos;
    }

    public function bloqueado(): bool
    {
        return $this->bloqueado;
    }

    public function vigente(): bool
    {
        return $this->vigente;
    }

    public function tipoUsuarioVigente(): bool
    {
        return $this->tipoUsuarioVigente;
    }
}
