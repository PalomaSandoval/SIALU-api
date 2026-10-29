<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Excepción controlada del proceso de autenticación.
 *
 * Permite diferenciar internamente el motivo del rechazo sin exponer
 * información sensible al usuario final.
 */
final class AuthenticationException extends RuntimeException
{
    public const INVALID_CREDENTIALS = 'invalid_credentials';

    public const INVALID_USER = 'invalid_user';

    public const LDAP_LOCKED = 'ldap_locked';

    public const UNAUTHORIZED_GROUP = 'unauthorized_group';

    public const SERVICE_UNAVAILABLE = 'service_unavailable';

    private string $reason;

    private string $userMessage;

    public function __construct(
        string $reason,
        string $userMessage,
        ?\Throwable $previous = null
    ) {
        $this->reason = $reason;
        $this->userMessage = $userMessage;

        parent::__construct(
            $userMessage,
            0,
            $previous
        );
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function userMessage(): string
    {
        return $this->userMessage;
    }

    public static function invalidCredentials(
        ?\Throwable $previous = null
    ): self {
        return new self(
            self::INVALID_CREDENTIALS,
            'Usuario o contraseña incorrectos.',
            $previous
        );
    }

    public static function invalidUser(
        ?\Throwable $previous = null
    ): self {
        return new self(
            self::INVALID_USER,
            'Usuario no válido.',
            $previous
        );
    }

    public static function ldapLocked(
        ?\Throwable $previous = null
    ): self {
        return new self(
            self::LDAP_LOCKED,
            'Usuario no válido.',
            $previous
        );
    }

    public static function unauthorizedGroup(
        ?\Throwable $previous = null
    ): self {
        return new self(
            self::UNAUTHORIZED_GROUP,
            'Usuario no válido.',
            $previous
        );
    }

    public static function unavailable(
        ?\Throwable $previous = null
    ): self {
        return new self(
            self::SERVICE_UNAVAILABLE,
            'No fue posible iniciar sesión. Intente nuevamente.',
            $previous
        );
    }
}
