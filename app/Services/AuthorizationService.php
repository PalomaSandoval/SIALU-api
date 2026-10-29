<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuthorizationRepository;
use App\Support\Logger;
use RuntimeException;
use Throwable;

final class AuthorizationService
{
    private AuthorizationRepository $repository;

    private SessionManager $session;

    private Logger $logger;

    /**
     * Cache de permisos durante la petición HTTP actual.
     *
     * @var array<int, string>|null
     */
    private ?array $permissionsCache = null;

    /**
     * IdTipoUsuario asociado al cache actual.
     */
    private ?int $cachedTipoUsuarioId = null;

    public function __construct(
        AuthorizationRepository $repository,
        SessionManager $session,
        Logger $logger
    ) {
        $this->repository = $repository;
        $this->session = $session;
        $this->logger = $logger;
    }

    /**
     * Obtiene los permisos efectivos del usuario autenticado.
     *
     * @return array<int, string>
     */
    public function permissions(): array
    {
        $idTipoUsuario = $this->authenticatedTipoUsuarioId();

        /*
         * Cache exclusivamente durante la petición actual.
         *
         * Evita consultar repetidamente SQL Server si:
         *
         * - el middleware consulta permisos;
         * - el controlador vuelve a consultar permisos;
         * - la vista verifica varios permisos.
         */
        if (
            $this->permissionsCache !== null
            && $this->cachedTipoUsuarioId === $idTipoUsuario
        ) {
            return $this->permissionsCache;
        }

        try {
            $permissions =
                $this->repository
                    ->permissionsByTipoUsuario(
                        $idTipoUsuario
                    );

        } catch (Throwable $exception) {
            $this->logger->database(
                'No fue posible obtener los permisos '
                .'efectivos del usuario.',
                [
                    'IdTipoUsuario' => $idTipoUsuario,

                    'IdUsuario' => $this->session->userValue(
                        'IdUsuario'
                    ),

                    'uid' => $this->session->userValue(
                        'uid'
                    ),

                    'exception' => get_class($exception),

                    'message' => $exception->getMessage(),
                ],
                'error'
            );

            throw new RuntimeException(
                'No fue posible determinar los permisos '
                .'del usuario autenticado.',
                0,
                $exception
            );
        }

        /*
         * Normalización defensiva.
         */
        $normalized = [];

        foreach ($permissions as $permission) {
            $permission = trim($permission);

            if ($permission === '') {
                continue;
            }

            $normalized[] = $permission;
        }

        $normalized = array_values(
            array_unique(
                $normalized
            )
        );

        $this->permissionsCache =
            $normalized;

        $this->cachedTipoUsuarioId =
            $idTipoUsuario;

        return $this->permissionsCache;
    }

    /**
     * Indica si el usuario posee exactamente
     * una clave de permiso.
     */
    public function hasPermission(
        string $permission
    ): bool {
        $permission =
            $this->normalizePermission(
                $permission
            );

        return in_array(
            $permission,
            $this->permissions(),
            true
        );
    }

    /**
     * Indica si el usuario posee al menos uno
     * de los permisos indicados.
     *
     * @param  array<int, string>  $permissions
     */
    public function hasAnyPermission(
        array $permissions
    ): bool {
        if ($permissions === []) {
            return false;
        }

        foreach ($permissions as $permission) {
            if (
                $this->hasPermission(
                    $permission
                )
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Indica si el usuario posee todos los
     * permisos indicados.
     *
     * @param  array<int, string>  $permissions
     */
    public function hasAllPermissions(
        array $permissions
    ): bool {
        if ($permissions === []) {
            return false;
        }

        foreach ($permissions as $permission) {
            if (
                ! $this->hasPermission(
                    $permission
                )
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Obtiene el IdTipoUsuario de la sesión autenticada.
     */
    private function authenticatedTipoUsuarioId(): int
    {
        if (! $this->session->authenticated()) {
            throw new RuntimeException(
                'No existe una sesión autenticada.'
            );
        }

        $value =
            $this->session->userValue(
                'IdTipoUsuario'
            );

        if (! is_numeric($value)) {
            $this->logger->security(
                'La sesión autenticada no contiene '
                .'un IdTipoUsuario válido.',
                [
                    'IdUsuario' => $this->session->userValue(
                        'IdUsuario'
                    ),

                    'uid' => $this->session->userValue(
                        'uid'
                    ),
                ]
            );

            throw new RuntimeException(
                'La sesión autenticada no contiene '
                .'un tipo de usuario válido.'
            );
        }

        $idTipoUsuario =
            (int) $value;

        if ($idTipoUsuario < 1) {
            $this->logger->security(
                'La sesión contiene un IdTipoUsuario '
                .'fuera de rango.',
                [
                    'IdTipoUsuario' => $idTipoUsuario,

                    'IdUsuario' => $this->session->userValue(
                        'IdUsuario'
                    ),

                    'uid' => $this->session->userValue(
                        'uid'
                    ),
                ]
            );

            throw new RuntimeException(
                'La sesión autenticada no contiene '
                .'un tipo de usuario válido.'
            );
        }

        return $idTipoUsuario;
    }

    /**
     * Normaliza y valida una clave de permiso.
     */
    private function normalizePermission(
        string $permission
    ): string {
        $permission =
            trim($permission);

        if ($permission === '') {
            throw new RuntimeException(
                'La clave de permiso no puede estar vacía.'
            );
        }

        if (strlen($permission) > 100) {
            throw new RuntimeException(
                'La clave de permiso excede '
                .'la longitud permitida.'
            );
        }

        /*
         * Las claves actuales siguen el formato:
         *
         * USUARIO_LDAP_CONSULTAR
         * USUARIO_LDAP_MODIFICAR
         */
        if (
            preg_match(
                '/^[A-Z0-9_]+$/',
                $permission
            ) !== 1
        ) {
            throw new RuntimeException(
                'La clave de permiso contiene '
                .'caracteres no válidos.'
            );
        }

        return $permission;
    }
}
