<?php

declare(strict_types=1);

namespace App\Models;

use InvalidArgumentException;

final class UserRole
{
    public const ADMINISTRATOR = 'ADMINISTRADOR';

    public const SERVICE = 'SERVICIO';

    public const SUPPORT = 'SOPORTE';

    private function __construct() {}

    public static function normalize(
        string $role
    ): string {
        return strtoupper(
            trim($role)
        );
    }

    public static function isValid(
        string $role
    ): bool {
        return in_array(
            self::normalize($role),
            self::all(),
            true
        );
    }

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::ADMINISTRATOR,
            self::SERVICE,
            self::SUPPORT,
        ];
    }

    public static function ensureValid(
        string $role
    ): string {
        $role = self::normalize($role);

        if (! self::isValid($role)) {
            throw new InvalidArgumentException(
                sprintf(
                    'El rol "%s" no es válido.',
                    $role
                )
            );
        }

        return $role;
    }
}
