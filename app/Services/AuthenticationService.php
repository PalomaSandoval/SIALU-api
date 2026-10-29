<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\LdapGateway;
use App\Exceptions\AuthenticationException;
use App\Models\ApplicationUser;
use App\Models\AuthenticatedUser;
use App\Models\DatabaseSession;
use App\Repositories\SesionRepository;
use App\Repositories\UsuarioRepository;
use App\Support\Logger;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Servicio central de autenticación de LdapSupport Enterprise.
 *
 * Orquesta:
 *
 * OpenLDAP
 *      ↓
 * catcUsuarios
 *      ↓
 * movcSesiones
 *      ↓
 * movcSesiones
 *      ↓
 * $_SESSION
 *
 * No realiza consultas LDAP ni SQL directamente.
 */
final class AuthenticationService
{
    private LdapGateway $ldap;

    private UsuarioRepository $usuarios;

    private SesionRepository $sesiones;

    private SessionManager $session;

    private Request $request;

    private Logger $logger;

    public function __construct(
        LdapGateway $ldap,
        UsuarioRepository $usuarios,
        SesionRepository $sesiones,
        SessionManager $session,
        Request $request,
        Logger $logger
    ) {
        $this->ldap = $ldap;
        $this->usuarios = $usuarios;
        $this->sesiones = $sesiones;
        $this->session = $session;
        $this->request = $request;
        $this->logger = $logger;
    }

    /**
     * Autentica completamente al usuario.
     *
     * @throws AuthenticationException
     */
    public function login(
        string $uid,
        string $password
    ): AuthenticatedUser {
        $uid = $this->normalizeUid($uid);

        if ($password === '') {
            throw AuthenticationException::invalidCredentials();
        }

        $this->logger->security(
            'Inicio de proceso de autenticación.',
            [
                'uid' => $uid,
                'ip' => $this->request->ip(),
            ]
        );

        /*
         * Impide iniciar nuevamente una sesión PHP autenticada
         * sobre la sesión actual.
         */
        if ($this->session->authenticated()) {
            $this->logger->security(
                'Intento de login con una sesión ya autenticada.',
                [
                    'current_uid' => $this->session->userValue('uid'),
                    'requested_uid' => $uid,
                    'ip' => $this->request->ip(),
                ]
            );

            throw AuthenticationException::invalidUser();
        }

        /*
         * ---------------------------------------------------------
         * 1. AUTENTICACIÓN OPENLDAP
         * ---------------------------------------------------------
         */

        $ldapUser = $this->authenticateLdap(
            $uid,
            $password
        );

        /*
         * Comprobación defensiva:
         * el uid devuelto por LDAP debe coincidir con el solicitado.
         */
        $ldapUid = strtolower(
            trim(
                (string) ($ldapUser['uid'] ?? '')
            )
        );

        if (
            $ldapUid === ''
            || $ldapUid !== strtolower($uid)
        ) {
            $this->logger->security(
                'LDAP devolvió un uid inconsistente.',
                [
                    'requested_uid' => $uid,
                    'returned_uid' => $ldapUid,
                    'ip' => $this->request->ip(),
                ]
            );

            throw AuthenticationException::invalidUser();
        }

        /*
         * ---------------------------------------------------------
         * 2. VALIDACIÓN CONTRA SGUU
         * ---------------------------------------------------------
         */

        $applicationUser = $this->findApplicationUser(
            $uid
        );

        $this->validateApplicationUser(
            $applicationUser,
            $uid
        );

        /*
         * ---------------------------------------------------------
         * 3. CONSTRUIR USUARIO AUTENTICADO
         * ---------------------------------------------------------
         */

        $authenticatedUser =
            $this->buildAuthenticatedUser(
                $applicationUser,
                $ldapUser
            );

        $databaseSession = null;

        try {
            $databaseSession = $this->sesiones->create(
                $applicationUser->idUsuario(),
                $this->request->ip(),
                $this->resolveClientHost(),
                null
            );

            /*
             * -----------------------------------------------------
             * 6. CREAR SESIÓN PHP
             * -----------------------------------------------------
             */

            $this->session->authenticate(
                $authenticatedUser,
                $databaseSession->idSesion()
            );

        } catch (Throwable $exception) {

            /*
             * Si movcSesiones fue creada pero $_SESSION falló,
             * cerramos la sesión SQL para evitar dejar sesiones
             * ACTIVA huérfanas.
             */
            if (
                $databaseSession instanceof DatabaseSession
                && $databaseSession->activa()
            ) {
                try {
                    $this->sesiones->close(
                        $databaseSession->idSesion()
                    );
                } catch (Throwable $cleanupException) {
                    $this->logger->database(
                        'No fue posible cerrar una sesión '
                        .'huérfana después de un fallo de login.',
                        [
                            'IdSesion' => $databaseSession->idSesion(),
                            'uid' => $uid,
                            'exception' => get_class($cleanupException),
                            'message' => $cleanupException->getMessage(),
                        ],
                        'error'
                    );
                }
            }

            $this->logger->security(
                'Falló la creación de la sesión autenticada.',
                [
                    'uid' => $uid,
                    'IdUsuario' => $applicationUser->idUsuario(),
                    'exception' => get_class($exception),
                    'message' => $exception->getMessage(),
                ],
                'error'
            );

            throw AuthenticationException::unavailable(
                $exception
            );
        }

        /*
         * ---------------------------------------------------------
         * 7. AUDITORÍA
         * ---------------------------------------------------------
         */

        $this->logger->security(
            'Login completado correctamente.',
            [
                'uid' => $authenticatedUser->uid(),
                'IdUsuario' => $authenticatedUser->idUsuario(),
                'IdSesion' => $databaseSession->idSesion(),
                'TipoUsuario' => $authenticatedUser->tipoUsuario(),
                'ip' => $this->request->ip(),
                'host' => $this->resolveClientHost(),
            ]
        );

        return $authenticatedUser;
    }

    /**
     * Cierra voluntariamente la sesión actual.
     */
    public function logout(): void
    {
        if (! $this->session->authenticated()) {
            $this->session->destroy();

            return;
        }

        $idSesion = $this->session->sessionRecordId();

        $uid = (string) $this->session->userValue(
            'uid',
            ''
        );

        try {
            if ($idSesion !== null) {
                $this->sesiones->close(
                    $idSesion
                );
            }

            $this->logger->security(
                'Logout de usuario.',
                [
                    'uid' => $uid,
                    'IdSesion' => $idSesion,
                    'ip' => $this->request->ip(),
                ]
            );

        } catch (Throwable $exception) {

            /*
             * El usuario debe poder cerrar su sesión PHP aunque
             * temporalmente falle SQL Server.
             */
            $this->logger->database(
                'No fue posible cerrar movcSesiones durante logout.',
                [
                    'uid' => $uid,
                    'IdSesion' => $idSesion,
                    'exception' => get_class($exception),
                    'message' => $exception->getMessage(),
                ],
                'error'
            );

        } finally {
            $this->session->destroy();
        }
    }

    /**
     * Comprueba la vigencia de la sesión.
     *
     * Debe ejecutarse antes de acceder a rutas protegidas.
     *
     * Devuelve:
     *
     * true  = sesión continúa vigente.
     * false = no existe sesión o expiró.
     */
    public function validateSession(): bool
    {
        if (!$this->session->authenticated()) {
            return false;
        }
        $this->session->touch();
        return true;
    }

    /**
     * Indica si existe usuario autenticado.
     */
    public function authenticated(): bool
    {
        return $this->session->authenticated();
    }

    /**
     * Devuelve los datos almacenados en la sesión.
     *
     * @return array<string, mixed>|null
     */
    public function user(): ?array
    {
        return $this->session->user();
    }

    /**
     * Autentica en OpenLDAP y transforma errores internos en
     * AuthenticationException.
     *
     * @return array<string, mixed>
     */
    private function authenticateLdap(
        string $uid,
        string $password
    ): array {
        try {
            return $this->ldap->authenticate(
                $uid,
                $password
            );

        } catch (InvalidArgumentException $exception) {

            throw AuthenticationException::invalidCredentials(
                $exception
            );

        } catch (RuntimeException $exception) {

            $message = strtolower(
                $exception->getMessage()
            );

            if (
                strpos(
                    $message,
                    'usuario o contraseña incorrectos'
                ) !== false
            ) {
                throw AuthenticationException::invalidCredentials(
                    $exception
                );
            }

            if (
                strpos(
                    $message,
                    'bloqueada'
                ) !== false
            ) {
                throw AuthenticationException::ldapLocked(
                    $exception
                );
            }

            if (
                strpos(
                    $message,
                    'grupo autorizado'
                ) !== false
            ) {
                throw AuthenticationException::unauthorizedGroup(
                    $exception
                );
            }

            $this->logger->ldap(
                'Fallo de infraestructura durante login.',
                [
                    'uid' => $uid,
                    'exception' => get_class($exception),
                    'message' => $exception->getMessage(),
                ],
                'error'
            );

            throw AuthenticationException::unavailable(
                $exception
            );
        }
    }

    /**
     * Busca el usuario en catcUsuarios.
     */
    private function findApplicationUser(
        string $uid
    ): ApplicationUser {
        try {
            $user = $this->usuarios->findByUid(
                $uid
            );

            if ($user === null) {
                $this->logger->security(
                    'Usuario LDAP no registrado en SGUU.',
                    [
                        'uid' => $uid,
                        'ip' => $this->request->ip(),
                    ]
                );

                throw AuthenticationException::invalidUser();
            }

            return $user;

        } catch (AuthenticationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {

            $this->logger->database(
                'Error al consultar catcUsuarios durante login.',
                [
                    'uid' => $uid,
                    'exception' => get_class($exception),
                    'message' => $exception->getMessage(),
                ],
                'error'
            );

            throw AuthenticationException::unavailable(
                $exception
            );
        }
    }

    /**
     * Valida las reglas de autorización de SGUU.
     */
    private function validateApplicationUser(
        ApplicationUser $user,
        string $uid
    ): void {
        if (! $user->vigente()) {
            $this->logger->security(
                'Usuario SGUU no vigente.',
                [
                    'uid' => $uid,
                    'IdUsuario' => $user->idUsuario(),
                ]
            );

            throw AuthenticationException::invalidUser();
        }

        if ($user->bloqueado()) {
            $this->logger->security(
                'Usuario SGUU bloqueado.',
                [
                    'uid' => $uid,
                    'IdUsuario' => $user->idUsuario(),
                ]
            );

            throw AuthenticationException::invalidUser();
        }

        if (! $user->tipoUsuarioVigente()) {
            $this->logger->security(
                'Tipo de usuario SGUU no vigente.',
                [
                    'uid' => $uid,
                    'IdUsuario' => $user->idUsuario(),
                    'IdTipoUsuario' => $user->idTipoUsuario(),
                    'TipoUsuario' => $user->tipoUsuario(),
                ]
            );

            throw AuthenticationException::invalidUser();
        }
    }

    /**
     * Combina atributos LDAP con autorización SGUU.
     *
     * @param  array<string, mixed>  $ldapUser
     */
    private function buildAuthenticatedUser(
        ApplicationUser $applicationUser,
        array $ldapUser
    ): AuthenticatedUser {
        return new AuthenticatedUser(
            $applicationUser->idUsuario(),

            $applicationUser->idTipoUsuario(),

            $applicationUser->tipoUsuario(),

            (string) (
                $ldapUser['uid'] ?? ''
            ),

            (string) (
                $ldapUser['cn'] ?? ''
            ),

            (string) (
                $ldapUser['sn'] ?? ''
            ),

            (string) (
                $ldapUser['givenName'] ?? ''
            ),

            (string) (
                $ldapUser['employeeNumber'] ?? ''
            ),

            (string) (
                $ldapUser['mail'] ?? ''
            )
        );
    }

    /**
     * Normaliza el uid recibido por el login.
     */
    private function normalizeUid(
        string $uid
    ): string {
        $uid = strtolower(
            trim($uid)
        );

        if ($uid === '') {
            throw AuthenticationException::invalidCredentials();
        }

        if (strlen($uid) > 40) {
            throw AuthenticationException::invalidCredentials();
        }

        if (
            preg_match(
                '/^[A-Za-z0-9!_]+$/',
                $uid
            ) !== 1
        ) {
            throw AuthenticationException::invalidCredentials();
        }

        return $uid;
    }

    /**
     * Intenta determinar el nombre del equipo origen.
     *
     * Los navegadores NO proporcionan el hostname del equipo cliente.
     * Se utiliza reverse DNS como información auxiliar.
     */
    private function resolveClientHost(): string
    {
        $ip = $this->request->ip();

        if ($ip === '') {
            return 'DESCONOCIDO';
        }

        $host = @gethostbyaddr($ip);

        if (
            $host === false
            || $host === ''
            || $host === $ip
        ) {
            return $ip;
        }

        return substr(
            $host,
            0,
            80
        );
    }
}
