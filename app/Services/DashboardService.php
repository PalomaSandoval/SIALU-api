<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\UserRole;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Construye el contexto común utilizado por el dashboard
 * y por las pantallas administrativas que utilizan
 * layouts.dashboard.
 */
final class DashboardService
{
    public function __construct(private AuthenticationService $auth, private AuthorizationService $authorization, private SessionManager $session, private Request $request) {}

    /**
     * Construye el contexto común del dashboard.
     *
     * @param  array<string, string>  $routes
     * @return array<string, mixed>
     */
    public function context(
        string $activeKey,
        array $routes
    ): array {
        $user = $this->authenticatedUser();

        $tipoUsuario = $this->resolveUserRole(
            $user
        );

        return [
            'user' => $user,

            'fullName' => $this->resolveFullName(
                $user
            ),

            'tipoUsuario' => $tipoUsuario,

            'csrf' => $this->request->session()->token(),

            'logoutAction' => $routes['logout'] ?? '#',

            'session' => $this->sessionContext(),

            'menu' => $this->buildMenu(
                $tipoUsuario,
                $activeKey,
                $routes
            ),
        ];
    }

    /**
     * Obtiene el usuario autenticado.
     *
     * @return array<string, mixed>
     */
    private function authenticatedUser(): array
    {
        $user = $this->auth
            ->user();

        if (! is_array($user) || $user === []) {
            throw new RuntimeException(
                'No existe un usuario autenticado '
                .'para construir el dashboard.'
            );
        }

        return $user;
    }

    /**
     * Construye el nombre que se muestra en el dashboard.
     *
     * @param  array<string, mixed>  $user
     */
    private function resolveFullName(
        array $user
    ): string {
        $givenName = trim(
            (string) (
                $user['givenName']
                ?? $user['Nombre']
                ?? $user['nombre']
                ?? ''
            )
        );

        $primerApellido = trim(
            (string) (
                $user['sn']
                ?? $user['PrimerApellido']
                ?? $user['primerApellido']
                ?? ''
            )
        );

        $segundoApellido = trim(
            (string) (
                $user['SegundoApellido']
                ?? $user['segundoApellido']
                ?? ''
            )
        );

        $parts = array_filter(
            [
                $givenName,
                $primerApellido,
                $segundoApellido,
            ],
            static fn (
                string $value
            ): bool => $value !== ''
        );

        $fullName = trim(
            implode(
                ' ',
                $parts
            )
        );

        if ($fullName !== '') {
            return $fullName;
        }

        $cn = trim(
            (string) (
                $user['cn']
                ?? $user['Cn']
                ?? ''
            )
        );

        if ($cn !== '') {
            return $cn;
        }

        $uid = trim(
            (string) (
                $user['uid']
                ?? $user['Uid']
                ?? ''
            )
        );

        if ($uid !== '') {
            return $uid;
        }

        return 'Usuario';
    }

    /**
     * Determina el tipo de usuario autenticado.
     *
     * @param  array<string, mixed>  $user
     */
    private function resolveUserRole(
        array $user
    ): string {
        $tipoUsuario = strtoupper(
            trim(
                (string) (
                    $user['TipoUsuario']
                    ?? $user['tipoUsuario']
                    ?? ''
                )
            )
        );

        if ($tipoUsuario === '') {
            throw new RuntimeException(
                'No fue posible determinar '
                .'el tipo de usuario autenticado.'
            );
        }

        return $tipoUsuario;
    }

    /**
     * Información común de la sesión.
     *
     * @return array<string, mixed>
     */
    private function sessionContext(): array
    {
        $session = $this->session;

        return [
            'id' => $session->sessionRecordId(),

            'inactivity_seconds' => $session->inactivitySeconds(),
        ];
    }

    /**
     * @param  array<string, string>  $routes
     * @return array<int, array<string, mixed>>
     */
    private function buildMenu(
        string $tipoUsuario,
        string $activeKey,
        array $routes
    ): array {
        $menu = [];

        $menu[] = [
            'key' => 'dashboard',

            'label' => 'Dashboard',

            'url' => $routes['dashboard'] ?? '#',

            'icon' => 'home',

            'active' => $activeKey === 'dashboard',

            'enabled' => true,
        ];

        /*
        * =========================================================
        * USUARIOS LDAP
        * =========================================================
        *
        * La visibilidad del módulo y de cada operación se
        * determina mediante los permisos efectivos almacenados
        * en SQL Server.
        *
        * LDAP solamente controla el acceso inicial a SGUU.
        */
        $canConsultLdap =
            $this->authorization
                ->hasPermission(
                    'USUARIO_LDAP_CONSULTAR'
                );

        $canCreateLdap =
            $this->authorization
                ->hasPermission(
                    'USUARIO_LDAP_CREAR'
                );

        $canModifyLdap =
            $this->authorization
                ->hasPermission(
                    'USUARIO_LDAP_MODIFICAR'
                );

        if (
            $canConsultLdap
            || $canCreateLdap
            || $canModifyLdap
        ) {
            $children = [];

            /*
            * CONSULTAR
            */
            if ($canConsultLdap) {
                $children[] =
                    $this->catalogItem(
                        'usuarios_ldap_consultar',
                        'Consultar usuario',
                        $routes['usuarios_ldap']
                            ?? null,
                        isset(
                            $routes['usuarios_ldap']
                        ),
                        $activeKey
                    );
            }

            /*
            * CREAR
            */
            if ($canCreateLdap) {
                $children[] =
                    $this->catalogItem(
                        'usuarios_ldap_crear',
                        'Crear usuario',
                        $routes['usuarios_ldap_crear']
                            ?? null,
                        isset(
                            $routes['usuarios_ldap_crear']
                        ),
                        $activeKey
                    );
            }

            /*
            * MODIFICAR
            *
            * Se habilitará cuando registremos la ruta
            * correspondiente.
            */
            if ($canModifyLdap) {
                $children[] =
                    $this->catalogItem(
                        'usuarios_ldap_modificar',
                        'Modificar usuario',
                        $routes['usuarios_ldap_modificar']
                            ?? null,
                        isset(
                            $routes['usuarios_ldap_modificar']
                        ),
                        $activeKey
                    );
            }

            $menu[] = [
                'key' => 'usuarios_ldap',

                'label' => 'Usuarios LDAP',

                'url' => null,

                'icon' => 'users',

                'active' => $activeKey === 'usuarios_ldap'
                    || $this->hasActiveChild(
                        $children
                    ),

                'enabled' => true,

                'children' => $children,
            ];
        }

        if (
            $this->isAdministrator(
                $tipoUsuario
            )
        ) {
            $children = [
                $this->catalogItem(
                    'tipos_usuarios',
                    'Tipos de Usuario',
                    $routes['tipos_usuarios']
                        ?? null,
                    true,
                    $activeKey
                ),

                /*
                * Catálogo de permisos.
                */
                $this->catalogItem(
                    'permisos',
                    'Permisos',
                    $routes['permisos']
                        ?? null,
                    true,
                    $activeKey
                ),

                $this->catalogItem(
                    'tipos_usuarios_permisos',
                    'Tipos de Usuarios - Permisos',
                    $routes['tipos_usuarios_permisos'] ?? null,
                    true,
                    $activeKey
                ),

                $this->catalogItem(
                    'tiempo_sesiones',
                    'Tiempo de Sesiones',
                    $routes['tiempo_sesiones']
                        ?? null,
                    isset($routes['tiempo_sesiones']),
                    $activeKey
                ),

                $this->catalogItem(
                    'aplicaciones_web',
                    'Aplicaciones Web',
                    $routes['aplicaciones_web']
                        ?? null,
                    isset($routes['aplicaciones_web']),
                    $activeKey
                ),

                $this->catalogItem(
                    'grupos',
                    'Grupos',
                    $routes['grupos']
                        ?? null,
                    isset($routes['grupos']),
                    $activeKey
                ),
            ];

            $menu[] = [
                'key' => 'catalogos',

                'label' => 'Administración',

                'url' => null,

                'icon' => 'settings',

                'active' => $this->hasActiveChild(
                    $children
                ),

                'enabled' => true,

                'children' => $children,
            ];
        }

        /*
        echo '<pre>';

        var_dump(
            $menu
        );

        echo '</pre>';

        die;
        */

        return $menu;
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogItem(
        string $key,
        string $label,
        ?string $url,
        bool $enabled,
        string $activeKey
    ): array {
        $enabled = $enabled && $url !== null;

        return [
            'key' => $key,

            'label' => $label,

            'url' => $enabled
                    ? $url
                    : null,

            'active' => $activeKey === $key,

            'enabled' => $enabled,
        ];
    }

    /**
     * Determina si una opción hija está activa.
     *
     * @param  array<int, array<string, mixed>>  $children
     */
    private function hasActiveChild(
        array $children
    ): bool {
        foreach ($children as $child) {
            if (
                ($child['active'] ?? false)
                === true
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determina si el usuario es administrador.
     */
    private function isAdministrator(
        string $tipoUsuario
    ): bool {
        /*
         * Si UserRole::ADMINISTRATOR es un string/constante,
         * esta comparación utiliza la definición centralizada
         * del proyecto.
         */
        return $tipoUsuario
            === strtoupper(
                (string) UserRole::ADMINISTRATOR
            );
    }
}
