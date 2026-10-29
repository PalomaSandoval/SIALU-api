<?php

declare(strict_types=1);

namespace App\Models;

use InvalidArgumentException;

/**
 * Estados de movcSesiones utilizados por la aplicación.
 */
final class SessionStatus
{
    public const ACTIVE = 'ACTIVA';

    public const CLOSED = 'CERRADA';

    public const EXPIRED = 'EXPIRADA';

    private function __construct() {}

    public static function normalize(string $status): string
    {
        $status = strtoupper(trim($status));

        if (! in_array($status, self::all(), true)) {
            throw new InvalidArgumentException(
                'El estado de sesión no es válido.'
            );
        }

        return $status;
    }

    /** @return array<int, string> */
    public static function all(): array
    {
        return [self::ACTIVE, self::CLOSED, self::EXPIRED];
    }
}
