<?php

declare(strict_types=1);

namespace App\Models;

use InvalidArgumentException;

/**
 * Usuario autenticado en LdapSupport.
 *
 * Combina:
 *
 * - Identificador y rol obtenidos desde SQL Server.
 * - Atributos personales obtenidos desde OpenLDAP.
 */
final class AuthenticatedUser
{
    private int $idUsuario;

    private int $idTipoUsuario;

    private string $tipoUsuario;

    private string $uid;

    private string $cn;

    private string $sn;

    private string $givenName;

    private string $employeeNumber;

    private string $mail;

    public function __construct(
        int $idUsuario,
        int $idTipoUsuario,
        string $tipoUsuario,
        string $uid,
        string $cn,
        string $sn,
        string $givenName,
        string $employeeNumber,
        string $mail
    ) {
        if ($idUsuario < 1) {
            throw new InvalidArgumentException(
                'IdUsuario debe ser mayor que cero.'
            );
        }

        if ($idTipoUsuario < 1) {
            throw new InvalidArgumentException(
                'IdTipoUsuario debe ser mayor que cero.'
            );
        }

        $uid = trim($uid);
        $tipoUsuario = trim($tipoUsuario);

        if ($uid === '') {
            throw new InvalidArgumentException(
                'El uid del usuario es obligatorio.'
            );
        }

        if ($tipoUsuario === '') {
            throw new InvalidArgumentException(
                'El tipo de usuario es obligatorio.'
            );
        }

        $this->idUsuario = $idUsuario;
        $this->idTipoUsuario = $idTipoUsuario;
        $this->tipoUsuario = $tipoUsuario;
        $this->uid = $uid;
        $this->cn = trim($cn);
        $this->sn = trim($sn);
        $this->givenName = trim($givenName);
        $this->employeeNumber = trim($employeeNumber);
        $this->mail = trim($mail);
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

    public function cn(): string
    {
        return $this->cn;
    }

    public function sn(): string
    {
        return $this->sn;
    }

    public function givenName(): string
    {
        return $this->givenName;
    }

    public function employeeNumber(): string
    {
        return $this->employeeNumber;
    }

    public function mail(): string
    {
        return $this->mail;
    }

    /**
     * Información que se almacenará en $_SESSION.
     *
     * Nunca contiene contraseña ni userPassword.
     *
     * @return array<string, int|string>
     */
    public function toSessionArray(): array
    {
        return [
            'IdUsuario' => $this->idUsuario,
            'IdTipoUsuario' => $this->idTipoUsuario,
            'TipoUsuario' => $this->tipoUsuario,
            'uid' => $this->uid,
            'cn' => $this->cn,
            'sn' => $this->sn,
            'givenName' => $this->givenName,
            'employeeNumber' => $this->employeeNumber,
            'mail' => $this->mail,
        ];
    }
}
