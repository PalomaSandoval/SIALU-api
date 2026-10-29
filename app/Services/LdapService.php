<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\LdapGateway;
use App\Support\Config;
use App\Support\Logger;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Servicio de autenticación y consulta OpenLDAP.
 *
 * Responsabilidades:
 *
 * - Conectarse al servidor OpenLDAP.
 * - Realizar bind con la cuenta de consulta.
 * - Buscar usuarios por uid.
 * - Validar las credenciales mediante bind con el usuario.
 * - Detectar cuentas LDAP bloqueadas.
 * - Verificar pertenencia al grupo autorizado.
 * - Recuperar atributos LDAP necesarios para la aplicación.
 *
 * Este servicio nunca almacena ni registra contraseñas.
 */
final class LdapService implements LdapGateway
{
    /**
     * Configuración general.
     */
    private Config $config;

    /**
     * Administrador de logs.
     */
    private Logger $logger;

    /**
     * Constructor.
     */
    public function __construct(
        Config $config,
        Logger $logger
    ) {
        $this->config = $config;
        $this->logger = $logger;

    }

    /**
     * Autentica al usuario contra OpenLDAP.
     *
     * Flujo:
     *
     * 1. Valida el formato del uid.
     * 2. Busca el usuario mediante la cuenta de consultas.
     * 3. Verifica que la cuenta no esté bloqueada.
     * 4. Realiza bind con el DN del usuario y su contraseña.
     * 5. Verifica la pertenencia al grupo Soporte.
     * 6. Devuelve los atributos LDAP.
     *
     * @return array<string, mixed>
     */
    public function authenticate(
        string $uid,
        string $password
    ): array {
        $uid = $this->validateUid($uid);
        $this->validatePassword($password);

        $readonlyConnection = null;
        $userConnection = null;

        try {
            $readonlyConnection = $this->createConnection();

            $this->bindReadonly($readonlyConnection);

            $user = $this->findUserUsingConnection(
                $readonlyConnection,
                $uid
            );

            if ($user === null) {
                $this->logger->security(
                    'Intento de autenticación LDAP para usuario inexistente.',
                    [
                        'uid' => $uid,
                    ]
                );

                throw new RuntimeException(
                    'Usuario o contraseña incorrectos.'
                );
            }

            if ($this->isAccountLocked($user)) {
                $this->logger->security(
                    'Intento de autenticación con cuenta LDAP bloqueada.',
                    [
                        'uid' => $uid,
                        'dn' => $user['dn'] ?? null,
                    ]
                );

                throw new RuntimeException(
                    'La cuenta del usuario se encuentra bloqueada.'
                );
            }

            $userDn = (string) ($user['dn'] ?? '');

            if ($userDn === '') {
                throw new RuntimeException(
                    'El usuario LDAP no contiene un DN válido.'
                );
            }

            /*
             * Se utiliza una conexión independiente para validar la
             * contraseña. Así, la conexión de consultas conserva el bind
             * de solo lectura para verificar posteriormente el grupo.
             */
            $userConnection = $this->createConnection();

            if (! $this->bind($userConnection, $userDn, $password)) {
                $this->logger->security(
                    'Credenciales LDAP inválidas.',
                    [
                        'uid' => $uid,
                        'dn' => $userDn,
                    ]
                );

                throw new RuntimeException(
                    'Usuario o contraseña incorrectos.'
                );
            }

            if (
                ! $this->isSupportMemberUsingConnection(
                    $readonlyConnection,
                    $user
                )
            ) {
                $this->logger->security(
                    'Usuario LDAP sin pertenencia al grupo autorizado.',
                    [
                        'uid' => $uid,
                        'dn' => $userDn,
                        'required_group' => $this->supportGroupName(),
                        'required_gid' => $this->supportGroupGid(),
                    ]
                );

                throw new RuntimeException(
                    'El usuario no pertenece al grupo autorizado.'
                );
            }

            $this->logger->ldap(
                'Usuario autenticado correctamente contra OpenLDAP.',
                [
                    'uid' => $uid,
                    'dn' => $userDn,
                    'group' => $this->supportGroupName(),
                ]
            );

            return $this->sanitizeUserResult($user);
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->logger->ldap(
                'Error no controlado durante la autenticación LDAP.',
                [
                    'uid' => $uid,
                    'exception' => get_class($exception),
                    'message' => $exception->getMessage(),
                ],
                'error'
            );

            throw new RuntimeException(
                'No fue posible completar la autenticación LDAP.',
                0,
                $exception
            );
        } finally {
            $this->disconnect($userConnection);
            $this->disconnect($readonlyConnection);
        }
    }

    /**
     * Busca un usuario LDAP sin validar su contraseña.
     *
     * La consulta se realiza con la cuenta de solo lectura.
     *
     * @return array<string, mixed>|null
     */
    public function findUser(string $uid): ?array
    {
        $uid = $this->validateUid($uid);
        $connection = null;

        try {
            $connection = $this->createConnection();

            $this->bindReadonly($connection);

            $user = $this->findUserUsingConnection(
                $connection,
                $uid
            );

            if ($user === null) {
                return null;
            }

            return $this->sanitizeUserResult($user);
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->logger->ldap(
                'Error al buscar un usuario en OpenLDAP.',
                [
                    'uid' => $uid,
                    'exception' => get_class($exception),
                    'message' => $exception->getMessage(),
                ],
                'error'
            );

            throw new RuntimeException(
                'No fue posible consultar el usuario en OpenLDAP.',
                0,
                $exception
            );
        } finally {
            $this->disconnect($connection);
        }
    }

    /**
     * Comprueba públicamente si el usuario pertenece al grupo autorizado.
     */
    public function isSupportMember(string $uid): bool
    {
        $uid = $this->validateUid($uid);
        $connection = null;

        try {
            $connection = $this->createConnection();

            $this->bindReadonly($connection);

            $user = $this->findUserUsingConnection(
                $connection,
                $uid
            );

            if ($user === null) {
                return false;
            }

            return $this->isSupportMemberUsingConnection(
                $connection,
                $user
            );
        } finally {
            $this->disconnect($connection);
        }
    }

    /**
     * Verifica la conectividad básica con OpenLDAP.
     *
     * @return array<string, mixed>
     */
    public function healthCheck(): array
    {
        $startedAt = microtime(true);
        $connection = null;

        try {
            $connection = $this->createConnection();

            $this->bindReadonly($connection);

            return [
                'success' => true,
                'status' => 'ok',
                'uri' => $this->ldapUri(),
                'response_time_ms' => round(
                    (microtime(true) - $startedAt) * 1000,
                    2
                ),
            ];
        } catch (Throwable $exception) {
            $this->logger->ldap(
                'Falló la comprobación de conectividad LDAP.',
                [
                    'uri' => $this->ldapUri(),
                    'exception' => get_class($exception),
                    'message' => $exception->getMessage(),
                ],
                'error'
            );

            return [
                'success' => false,
                'status' => 'error',
                'uri' => $this->ldapUri(),
                'response_time_ms' => round(
                    (microtime(true) - $startedAt) * 1000,
                    2
                ),
            ];
        } finally {
            $this->disconnect($connection);
        }
    }

    /**
     * Crea y configura una conexión LDAP.
     *
     * @return mixed
     */
    private function createConnection()
    {
        $this->validateEnvironment();
        $uri = $this->ldapUri();

        $connection = @ldap_connect($uri);

        if ($connection === false) {
            throw new RuntimeException(
                sprintf(
                    'No fue posible crear la conexión LDAP con %s.',
                    $uri
                )
            );
        }

        $protocolVersion = (int) $this->config->get(
            'ldap.protocol_version',
            3
        );

        if (
            ! @ldap_set_option(
                $connection,
                LDAP_OPT_PROTOCOL_VERSION,
                $protocolVersion
            )
        ) {
            $this->disconnect($connection);

            throw new RuntimeException(
                'No fue posible configurar la versión del protocolo LDAP.'
            );
        }

        $referrals = (bool) $this->config->get(
            'ldap.referrals',
            false
        );

        @ldap_set_option(
            $connection,
            LDAP_OPT_REFERRALS,
            $referrals ? 1 : 0
        );

        $timeout = (int) $this->config->get(
            'ldap.network_timeout',
            10
        );

        if (
            defined('LDAP_OPT_NETWORK_TIMEOUT')
            && $timeout > 0
        ) {
            @ldap_set_option(
                $connection,
                LDAP_OPT_NETWORK_TIMEOUT,
                $timeout
            );
        }

        $startTls = (bool) $this->config->get(
            'ldap.start_tls',
            false
        );

        if ($startTls) {
            if (! function_exists('ldap_start_tls')) {
                $this->disconnect($connection);

                throw new RuntimeException(
                    'La instalación de PHP no soporta LDAP StartTLS.'
                );
            }

            if (! @ldap_start_tls($connection)) {
                $error = $this->connectionError($connection);

                $this->disconnect($connection);

                throw new RuntimeException(
                    sprintf(
                        'No fue posible iniciar StartTLS: %s',
                        $error
                    )
                );
            }
        }

        return $connection;
    }

    /**
     * Realiza bind con la cuenta de consultas.
     *
     * @param  mixed  $connection
     */
    private function bindReadonly($connection): void
    {
        $dn = (string) $this->config->getRequired(
            'ldap.readonly.dn'
        );

        $password = (string) $this->config->getRequired(
            'ldap.readonly.password'
        );

        if (! $this->bind($connection, $dn, $password)) {
            $error = $this->connectionError($connection);

            $this->logger->ldap(
                'No fue posible realizar bind con la cuenta de consultas.',
                [
                    'dn' => $dn,
                    'error' => $error,
                ],
                'error'
            );

            throw new RuntimeException(
                'No fue posible autenticar la cuenta de consultas LDAP.'
            );
        }
    }

    /**
     * Realiza bind con la cuenta administrativa.
     *
     * Esta cuenta se utiliza exclusivamente para operaciones
     * que requieren permisos de escritura sobre OpenLDAP.
     *
     * @param  mixed  $connection
     */
    private function bindAdmin($connection): void
    {
        $dn = (string) $this->config->getRequired(
            'ldap.admin.dn'
        );

        $password = (string) $this->config->getRequired(
            'ldap.admin.password'
        );

        if (! $this->bind($connection, $dn, $password)) {
            $error = $this->connectionError($connection);

            $this->logger->ldap(
                'No fue posible realizar bind con la cuenta administrativa LDAP.',
                [
                    'dn' => $dn,
                    'error' => $error,
                ],
                'error'
            );

            throw new RuntimeException(
                'No fue posible autenticar la cuenta administrativa LDAP.'
            );
        }
    }

    /**
     * Ejecuta una operación bind.
     *
     * @param  mixed  $connection
     */
    private function bind(
        $connection,
        string $dn,
        string $password
    ): bool {
        $dn = trim($dn);

        if ($dn === '') {
            throw new InvalidArgumentException(
                'El DN utilizado para el bind no puede estar vacío.'
            );
        }

        /*
         * La contraseña no se recorta porque un espacio puede formar parte
         * legítima de una contraseña LDAP.
         */
        if ($password === '') {
            return false;
        }

        return @ldap_bind(
            $connection,
            $dn,
            $password
        );
    }

    /**
     * Busca un usuario utilizando una conexión ya autenticada.
     *
     * @param  mixed  $connection
     * @return array<string, mixed>|null
     */
    private function findUserUsingConnection(
        $connection,
        string $uid
    ): ?array {
        $baseDn = $this->peopleBaseDn();

        $escapedUid = $this->escapeFilterValue($uid);

        $filterTemplate = (string) $this->config->get(
            'ldap.filters.user',
            '(&(objectClass=inetOrgPerson)(uid=%s))'
        );

        $filter = $this->buildUserFilter(
            $filterTemplate,
            $escapedUid
        );

        $attributes = $this->userAttributes();

        $search = @ldap_search(
            $connection,
            $baseDn,
            $filter,
            $attributes,
            0,
            2,
            $this->searchTimeout()
        );

        if ($search === false) {
            throw new RuntimeException(
                sprintf(
                    'La búsqueda LDAP del usuario falló: %s',
                    $this->connectionError($connection)
                )
            );
        }

        $entries = @ldap_get_entries(
            $connection,
            $search
        );

        if (! is_array($entries)) {
            throw new RuntimeException(
                'OpenLDAP devolvió una respuesta de usuario inválida.'
            );
        }

        $count = (int) ($entries['count'] ?? 0);

        if ($count === 0) {
            return null;
        }

        if ($count > 1) {
            $this->logger->security(
                'La búsqueda LDAP devolvió varios usuarios con el mismo uid.',
                [
                    'uid' => $uid,
                    'count' => $count,
                ]
            );

            throw new RuntimeException(
                'La búsqueda LDAP devolvió más de un usuario.'
            );
        }

        return $this->normalizeUserEntry(
            $entries[0]
        );
    }

    /**
     * Comprueba pertenencia al grupo configurado.
     *
     * Se consideran válidas estas formas de pertenencia:
     *
     * 1. El usuario aparece en memberUid.
     * 2. El DN del usuario aparece en member.
     * 3. El gidNumber primario del usuario coincide con el grupo.
     *
     * @param  mixed  $connection
     * @param  array<string, mixed>  $user
     */
    private function isSupportMemberUsingConnection(
        $connection,
        array $user
    ): bool {
        $supportGid = $this->supportGroupGid();

        /*
         * Un gidNumber primario igual al gid del grupo representa
         * pertenencia válida a un grupo POSIX.
         */
        $userGid = $user['gidNumber'] ?? null;

        if (
            $userGid !== null
            && is_numeric($userGid)
            && (int) $userGid === $supportGid
        ) {
            return true;
        }

        $group = $this->findSupportGroup(
            $connection
        );

        if ($group === null) {
            throw new RuntimeException(
                sprintf(
                    'No se encontró el grupo LDAP autorizado "%s".',
                    $this->supportGroupName()
                )
            );
        }

        $uid = strtolower(
            trim((string) ($user['uid'] ?? ''))
        );

        $userDn = strtolower(
            trim((string) ($user['dn'] ?? ''))
        );

        $memberUids = $group['memberUid'] ?? [];

        if (! is_array($memberUids)) {
            $memberUids = [$memberUids];
        }

        foreach ($memberUids as $memberUid) {
            if (
                strtolower(trim((string) $memberUid))
                === $uid
            ) {
                return true;
            }
        }

        $members = $group['member'] ?? [];

        if (! is_array($members)) {
            $members = [$members];
        }

        foreach ($members as $memberDn) {
            if (
                strtolower(trim((string) $memberDn))
                === $userDn
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Busca el grupo autorizado.
     *
     * @param  mixed  $connection
     * @return array<string, mixed>|null
     */
    private function findSupportGroup($connection): ?array
    {
        $baseDn = $this->groupsBaseDn();

        $groupName = $this->escapeFilterValue(
            $this->supportGroupName()
        );

        $groupGid = $this->supportGroupGid();

        $filterTemplate = (string) $this->config->get(
            'ldap.filters.support_group',
            '(&(objectClass=posixGroup)(cn=%s)(gidNumber=%d))'
        );

        $filter = $this->buildGroupFilter(
            $filterTemplate,
            $groupName,
            $groupGid
        );

        $attributes = [
            'cn',
            'gidNumber',
            'memberUid',
            'member',
        ];

        $search = @ldap_search(
            $connection,
            $baseDn,
            $filter,
            $attributes,
            0,
            2,
            $this->searchTimeout()
        );

        if ($search === false) {
            throw new RuntimeException(
                sprintf(
                    'La búsqueda del grupo LDAP falló: %s',
                    $this->connectionError($connection)
                )
            );
        }

        $entries = @ldap_get_entries(
            $connection,
            $search
        );

        if (! is_array($entries)) {
            throw new RuntimeException(
                'OpenLDAP devolvió una respuesta de grupo inválida.'
            );
        }

        $count = (int) ($entries['count'] ?? 0);

        if ($count === 0) {
            return null;
        }

        if ($count > 1) {
            throw new RuntimeException(
                'La búsqueda LDAP devolvió más de un grupo autorizado.'
            );
        }

        return $this->normalizeGroupEntry(
            $entries[0]
        );
    }

    /**
     * Normaliza una entrada de usuario LDAP.
     *
     * @param  array<string|int, mixed>  $entry
     * @return array<string, mixed>
     */
    private function normalizeUserEntry(array $entry): array
    {
        return [
            'dn' => trim(
                (string) ($entry['dn'] ?? '')
            ),

            'uid' => $this->entryFirstValue(
                $entry,
                'uid'
            ),

            'cn' => $this->entryFirstValue(
                $entry,
                'cn'
            ),

            'sn' => $this->entryFirstValue(
                $entry,
                'sn'
            ),

            'givenName' => $this->entryFirstValue(
                $entry,
                'givenname'
            ),

            'employeeNumber' => $this->entryFirstValue(
                $entry,
                'employeenumber'
            ),

            'mail' => $this->entryFirstValue(
                $entry,
                'mail'
            ),

            'gidNumber' => $this->entryFirstValue(
                $entry,
                'gidnumber'
            ),

            'pwdAccountLockedTime' => $this->entryFirstValue(
                $entry,
                'pwdaccountlockedtime'
            ),
        ];
    }

    /**
     * Normaliza una entrada de grupo LDAP.
     *
     * @param  array<string|int, mixed>  $entry
     * @return array<string, mixed>
     */
    private function normalizeGroupEntry(array $entry): array
    {
        return [
            'dn' => trim(
                (string) ($entry['dn'] ?? '')
            ),

            'cn' => $this->entryFirstValue(
                $entry,
                'cn'
            ),

            'gidNumber' => $this->entryFirstValue(
                $entry,
                'gidnumber'
            ),

            'memberUid' => $this->entryAllValues(
                $entry,
                'memberuid'
            ),

            'member' => $this->entryAllValues(
                $entry,
                'member'
            ),
        ];
    }

    /**
     * Obtiene el primer valor de un atributo LDAP.
     *
     * @param  array<string|int, mixed>  $entry
     */
    private function entryFirstValue(
        array $entry,
        string $attribute
    ): ?string {
        $attribute = strtolower($attribute);

        if (
            ! isset($entry[$attribute])
            || ! is_array($entry[$attribute])
            || (int) ($entry[$attribute]['count'] ?? 0) < 1
        ) {
            return null;
        }

        return trim(
            (string) $entry[$attribute][0]
        );
    }

    /**
     * Obtiene todos los valores de un atributo LDAP.
     *
     * @param  array<string|int, mixed>  $entry
     * @return array<int, string>
     */
    private function entryAllValues(
        array $entry,
        string $attribute
    ): array {
        $attribute = strtolower($attribute);

        if (
            ! isset($entry[$attribute])
            || ! is_array($entry[$attribute])
        ) {
            return [];
        }

        $count = (int) (
            $entry[$attribute]['count'] ?? 0
        );

        $values = [];

        for ($index = 0; $index < $count; $index++) {
            if (! isset($entry[$attribute][$index])) {
                continue;
            }

            $value = trim(
                (string) $entry[$attribute][$index]
            );

            if ($value !== '') {
                $values[] = $value;
            }
        }

        return array_values(
            array_unique($values)
        );
    }

    /**
     * Indica si la cuenta contiene pwdAccountLockedTime.
     *
     * @param  array<string, mixed>  $user
     */
    private function isAccountLocked(array $user): bool
    {
        $lockedTime = $user['pwdAccountLockedTime'] ?? null;

        return is_string($lockedTime)
            && trim($lockedTime) !== '';
    }

    /**
     * Elimina cualquier atributo que no deba salir del servicio.
     *
     * @param  array<string, mixed>  $user
     * @return array<string, mixed>
     */
    private function sanitizeUserResult(array $user): array
    {
        return [
            'dn' => (string) ($user['dn'] ?? ''),
            'uid' => (string) ($user['uid'] ?? ''),
            'cn' => (string) ($user['cn'] ?? ''),
            'sn' => (string) ($user['sn'] ?? ''),
            'givenName' => (string) (
                $user['givenName'] ?? ''
            ),
            'employeeNumber' => (string) (
                $user['employeeNumber'] ?? ''
            ),
            'mail' => (string) ($user['mail'] ?? ''),
            'gidNumber' => is_numeric(
                $user['gidNumber'] ?? null
            )
                ? (int) $user['gidNumber']
                : null,
        ];
    }

    /**
     * Devuelve los atributos necesarios para la búsqueda.
     *
     * @return array<int, string>
     */
    private function userAttributes(): array
    {
        $attributes = $this->config->get(
            'ldap.user_attributes',
            []
        );

        if (! is_array($attributes)) {
            $attributes = [];
        }

        $attributes[] = 'uid';
        $attributes[] = 'cn';
        $attributes[] = 'sn';
        $attributes[] = 'givenName';
        $attributes[] = 'employeeNumber';
        $attributes[] = 'mail';
        $attributes[] = 'gidNumber';
        $attributes[] = 'pwdAccountLockedTime';

        $attributes = array_filter(
            array_map(
                static function ($attribute): string {
                    return trim((string) $attribute);
                },
                $attributes
            ),
            static function (string $attribute): bool {
                return $attribute !== '';
            }
        );

        return array_values(
            array_unique($attributes)
        );
    }

    /**
     * Valida y normaliza el uid.
     */
    private function validateUid(string $uid): string
    {
        $uid = trim($uid);

        if ($uid === '') {
            throw new InvalidArgumentException(
                'El usuario es obligatorio.'
            );
        }

        $maximumLength = (int) $this->config->get(
            'security.username.max_length',
            50
        );

        if (
            strlen($uid) > $maximumLength
            || strlen($uid) > 128
        ) {
            throw new InvalidArgumentException(
                'El usuario excede la longitud permitida.'
            );
        }

        $pattern = (string) $this->config->get(
            'security.username.pattern',
            '/^[A-Za-z0-9!_]+$/'
        );

        $result = @preg_match(
            $pattern,
            $uid
        );

        if ($result === false) {
            throw new RuntimeException(
                'La expresión de validación del usuario no es válida.'
            );
        }

        if ($result !== 1) {
            throw new InvalidArgumentException(
                'El formato del usuario no es válido.'
            );
        }

        return $uid;
    }

    /**
     * Valida la contraseña recibida.
     */
    private function validatePassword(string $password): void
    {
        if ($password === '') {
            throw new InvalidArgumentException(
                'La contraseña es obligatoria.'
            );
        }

        /*
         * No se aplica trim() porque los espacios pueden formar parte de
         * la contraseña real.
         */
        if (strlen($password) > 1024) {
            throw new InvalidArgumentException(
                'La contraseña excede la longitud permitida.'
            );
        }

        if (strpos($password, "\0") !== false) {
            throw new InvalidArgumentException(
                'La contraseña contiene caracteres inválidos.'
            );
        }
    }

    /**
     * Escapa un valor para utilizarlo en filtros LDAP.
     */
    private function escapeFilterValue(string $value): string
    {
        if (function_exists('ldap_escape')) {
            return ldap_escape(
                $value,
                '',
                LDAP_ESCAPE_FILTER
            );
        }

        /*
         * Compatibilidad defensiva con instalaciones antiguas.
         */
        return strtr(
            $value,
            [
                '\\' => '\5c',
                '*' => '\2a',
                '(' => '\28',
                ')' => '\29',
                "\0" => '\00',
            ]
        );
    }

    /**
     * Construye el filtro de usuario.
     */
    private function buildUserFilter(
        string $template,
        string $escapedUid
    ): string {
        if (substr_count($template, '%s') !== 1) {
            throw new RuntimeException(
                'LDAP_USER_FILTER debe contener exactamente un marcador %s.'
            );
        }

        return sprintf(
            $template,
            $escapedUid
        );
    }

    /**
     * Construye el filtro del grupo autorizado.
     */
    private function buildGroupFilter(
        string $template,
        string $escapedName,
        int $gid
    ): string {
        if (
            strpos($template, '%s') === false
            || strpos($template, '%d') === false
        ) {
            throw new RuntimeException(
                'LDAP_SUPPORT_GROUP_FILTER debe contener %s y %d.'
            );
        }

        return sprintf(
            $template,
            $escapedName,
            $gid
        );
    }

    /**
     * Construye la base DN de usuarios.
     */
    private function peopleBaseDn(): string
    {
        return $this->composeBaseDn(
            (string) $this->config->getRequired(
                'ldap.people_ou'
            )
        );
    }

    /**
     * Construye la base DN de grupos.
     */
    private function groupsBaseDn(): string
    {
        return $this->composeBaseDn(
            (string) $this->config->getRequired(
                'ldap.groups_ou'
            )
        );
    }

    /**
     * Une una OU relativa con LDAP_BASE_DN.
     *
     * También acepta un DN completo.
     */
    private function composeBaseDn(string $ou): string
    {
        $ou = trim($ou);
        $baseDn = trim(
            (string) $this->config->getRequired(
                'ldap.base_dn'
            )
        );

        if ($ou === '') {
            return $baseDn;
        }

        if (
            substr(
                strtolower($ou),
                -strlen(strtolower($baseDn))
            ) === strtolower($baseDn)
        ) {
            return $ou;
        }

        return rtrim($ou, ',')
            .','
            .ltrim($baseDn, ',');
    }

    /**
     * Obtiene el DN del objeto que administra
     * el siguiente uidNumber disponible.
     */
    private function nextFreeUnixIdDn(): string
    {
        $baseDn = trim(
            (string) $this->config->getRequired(
                'ldap.base_dn'
            )
        );

        return 'cn=NextFreeUnixId,'.$baseDn;
    }

    /**
     * Lee el uidNumber actualmente disponible.
     *
     * IMPORTANTE:
     * Este método NO reserva ni incrementa el número.
     * Solamente consulta el contador.
     *
     * @param  mixed  $connection
     */
    private function readNextUidNumber(
        $connection
    ): int {
        $dn = $this->nextFreeUnixIdDn();

        $search = @ldap_read(
            $connection,
            $dn,
            '(objectClass=*)',
            ['uidNumber']
        );

        if ($search === false) {
            throw new RuntimeException(
                'No fue posible consultar el contador de uidNumber.'
            );
        }

        $entries = ldap_get_entries(
            $connection,
            $search
        );

        if (
            ! is_array($entries)
            || ($entries['count'] ?? 0) !== 1
        ) {
            throw new RuntimeException(
                'El contador de uidNumber no fue encontrado.'
            );
        }

        if (
            ! isset($entries[0]['uidnumber'][0])
            || ! is_string(
                $entries[0]['uidnumber'][0]
            )
        ) {
            throw new RuntimeException(
                'El contador no contiene un uidNumber válido.'
            );
        }

        $value = trim(
            $entries[0]['uidnumber'][0]
        );

        if (
            $value === ''
            || ! ctype_digit($value)
        ) {
            throw new RuntimeException(
                'El uidNumber del contador no es numérico.'
            );
        }

        $uidNumber = (int) $value;

        if ($uidNumber <= 0) {
            throw new RuntimeException(
                'El uidNumber del contador debe ser mayor que cero.'
            );
        }

        return $uidNumber;
    }

    /**
     * Reserva de forma atómica el siguiente uidNumber disponible.
     *
     * El valor almacenado en NextFreeUnixId representa el próximo
     * uidNumber que puede ser asignado.
     *
     * La modificación utiliza LDAP Assertion Control para garantizar
     * que el contador solamente sea incrementado si conserva el valor
     * que acabamos de leer.
     *
     * @param  mixed  $connection
     */
    private function reserveNextUidNumber(
        $connection
    ): int {
        $dn = $this->nextFreeUnixIdDn();

        /*
        * Número máximo de intentos ante modificaciones concurrentes.
        */
        $maximumAttempts = 5;

        for (
            $attempt = 1;
            $attempt <= $maximumAttempts;
            $attempt++
        ) {
            /*
            * Ejemplo:
            *
            * NextFreeUnixId = 17570
            *
            * current = 17570
            * next    = 17571
            */
            $current = $this->readNextUidNumber(
                $connection
            );

            if ($current >= PHP_INT_MAX) {
                throw new RuntimeException(
                    'El contador de uidNumber alcanzó el límite permitido.'
                );
            }

            $next = $current + 1;

            /*
            * Assertion Control:
            *
            * La modificación solamente debe ejecutarse si
            * uidNumber continúa teniendo el valor que acabamos
            * de consultar.
            */
            $controls = [
                [
                    'oid' => '1.3.6.1.1.12',
                    'iscritical' => true,
                    'value' => sprintf(
                        '(uidNumber=%d)',
                        $current
                    ),
                ],
            ];

            /*
            * Reemplaza el valor actual por el siguiente.
            */
            $entry = [
                'uidNumber' => (string) $next,
            ];

            $modified = @ldap_modify(
                $connection,
                $dn,
                $entry,
                $controls
            );

            if ($modified) {
                $this->logger->ldap(
                    'uidNumber reservado correctamente.',
                    [
                        'uid_number' => $current,
                        'next_uid_number' => $next,
                        'attempt' => $attempt,
                    ]
                );

                /*
                * Se devuelve el número reservado, NO el siguiente.
                *
                * LDAP queda:
                * NextFreeUnixId = 17571
                *
                * Usuario nuevo:
                * uidNumber = 17570
                */
                return $current;
            }

            /*
            * Obtenemos el código LDAP para distinguir una colisión
            * de concurrencia de un error real.
            */
            $errorNumber = @ldap_errno(
                $connection
            );

            /*
            * LDAP_ASSERTION_FAILED = 122
            *
            * No todas las versiones de PHP definen una constante
            * específica, por lo que utilizamos el código LDAP.
            */
            if ($errorNumber === 122) {
                $this->logger->ldap(
                    'Conflicto concurrente al reservar uidNumber; se reintentará.',
                    [
                        'uid_number' => $current,
                        'attempt' => $attempt,
                    ]
                );

                continue;
            }

            $error = $this->connectionError(
                $connection
            );

            $this->logger->ldap(
                'No fue posible reservar el siguiente uidNumber.',
                [
                    'uid_number' => $current,
                    'next_uid_number' => $next,
                    'ldap_errno' => $errorNumber,
                    'error' => $error,
                ],
                'error'
            );

            throw new RuntimeException(
                sprintf(
                    'No fue posible reservar el uidNumber: %s',
                    $error
                )
            );
        }

        throw new RuntimeException(
            'No fue posible reservar un uidNumber después de varios intentos concurrentes.'
        );
    }

    /**
     * Obtiene la URI LDAP definitiva.
     */
    private function ldapUri(): string
    {
        $uri = trim(
            (string) $this->config->get(
                'ldap.uri',
                ''
            )
        );

        if ($uri !== '') {
            return $uri;
        }

        $scheme = trim(
            (string) $this->config->get(
                'ldap.scheme',
                'ldap'
            )
        );

        $host = trim(
            (string) $this->config->getRequired(
                'ldap.host'
            )
        );

        $port = (int) $this->config->get(
            'ldap.port',
            389
        );

        if (
            strpos($host, 'ldap://') === 0
            || strpos($host, 'ldaps://') === 0
        ) {
            return $host;
        }

        return sprintf(
            '%s://%s:%d',
            $scheme,
            $host,
            $port
        );
    }

    /**
     * Nombre del grupo autorizado.
     */
    private function supportGroupName(): string
    {
        return trim(
            (string) $this->config->getRequired(
                'ldap.support_group.name'
            )
        );
    }

    /**
     * gidNumber del grupo autorizado.
     */
    private function supportGroupGid(): int
    {
        $gid = (int) $this->config->getRequired(
            'ldap.support_group.gid_number'
        );

        if ($gid < 1) {
            throw new RuntimeException(
                'El gidNumber del grupo autorizado no es válido.'
            );
        }

        return $gid;
    }

    /**
     * Tiempo máximo de una búsqueda LDAP.
     */
    private function searchTimeout(): int
    {
        return max(
            1,
            (int) $this->config->get(
                'ldap.network_timeout',
                10
            )
        );
    }

    /**
     * Obtiene el último error LDAP disponible.
     *
     * @param  mixed  $connection
     */
    private function connectionError($connection): string
    {
        $message = '';

        if (
            defined('LDAP_OPT_DIAGNOSTIC_MESSAGE')
            && @ldap_get_option(
                $connection,
                LDAP_OPT_DIAGNOSTIC_MESSAGE,
                $message
            )
            && is_string($message)
            && trim($message) !== ''
        ) {
            return trim($message);
        }

        $error = @ldap_error($connection);

        if (is_string($error) && trim($error) !== '') {
            return trim($error);
        }

        return 'Error LDAP no especificado.';
    }

    /**
     * Cierra una conexión LDAP.
     *
     * @param  mixed  $connection
     */
    private function disconnect($connection): void
    {
        if ($connection === null || $connection === false) {
            return;
        }

        try {
            @ldap_unbind($connection);
        } catch (Throwable $exception) {
            /*
             * No se propaga una excepción durante el cierre de la conexión.
             */
        }
    }

    /**
     * Valida que PHP y la configuración permitan utilizar LDAP.
     */
    private function validateEnvironment(): void
    {
        if (! extension_loaded('ldap')) {
            throw new RuntimeException(
                'La extensión LDAP de PHP no está habilitada.'
            );
        }

        $this->config->getRequired('ldap.base_dn');
        $this->config->getRequired('ldap.people_ou');
        $this->config->getRequired('ldap.groups_ou');

        $this->config->getRequired('ldap.readonly.dn');
        $this->config->getRequired('ldap.readonly.password');

        /*$this->config->getRequired('ldap.admin.dn');
        $this->config->getRequired('ldap.admin.password');
        */

        $this->config->getRequired('ldap.support_group.name');
        $this->config->getRequired(
            'ldap.support_group.gid_number'
        );

        /*
         * Obliga a resolver la URI durante la inicialización del servicio.
         */
        $this->ldapUri();
    }
}
