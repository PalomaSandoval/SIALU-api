<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Servidor OpenLDAP
    |--------------------------------------------------------------------------
    */

    'scheme' => env('LDAP_SCHEME', 'ldap'),

    'host' => env('LDAP_HOST'),

    'port' => (int) env('LDAP_PORT', 389),

    'uri' => env('LDAP_URI'),

    'base_dn' => env('LDAP_BASE_DN'),

    /*
    |--------------------------------------------------------------------------
    | Unidades organizativas
    |--------------------------------------------------------------------------
    */

    'people_ou' => env('LDAP_PEOPLE_OU', 'ou=People'),

    'groups_ou' => env('LDAP_GROUPS_OU', 'ou=Groups'),
    /*

    |--------------------------------------------------------------------------
    | Cuenta de consulta
    |--------------------------------------------------------------------------
    |
    | Esta cuenta debe tener únicamente permisos de lectura sobre los
    | atributos requeridos por la aplicación.
    |
    */

    'readonly' => [
        'dn' => env('LDAP_READONLY_DN'),

        'password' => env('LDAP_READONLY_PASS'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cuenta administrativa
    |--------------------------------------------------------------------------
    |
    | Se conserva para operaciones administrativas futuras. No debe usarse
    | para autenticar usuarios ni para búsquedas ordinarias.
    |
    */

    'admin' => [
        'dn' => env('LDAP_USER_DN'),
        'password' => env('LDAP_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Grupo autorizado para soporte
    |--------------------------------------------------------------------------
    */
    'support_group' => [
        'name' => env('LDAP_SUPPORT_GROUP', 'Soporte'),
        'gid_number' => (int) env('LDAP_SUPPORT_GID', 5003),
    ],

    /*
    |--------------------------------------------------------------------------
    | Atributos LDAP requeridos
    |--------------------------------------------------------------------------
    */

    'user_attributes' => [
        'uid',
        'cn',
        'sn',
        'givenName',
        'employeeNumber',
        'mail',
        'gidNumber',
        'pwdAccountLockedTime',
    ],

    /*
    |--------------------------------------------------------------------------
    | Opciones de conexión |--------------------------------------------------------------------------
    */

    'protocol_version' => (int) env(
        'LDAP_PROTOCOL_VERSION',
        3
    ),

    'network_timeout' => (int) env(
        'LDAP_NETWORK_TIMEOUT',
        10
    ),

    'referrals' => env('LDAP_REFERRALS', false),

    'start_tls' => env('LDAP_START_TLS', false),

    /*
    |--------------------------------------------------------------------------
    | Filtros
    |--------------------------------------------------------------------------
    */

    'filters' => [
        'user' => env(
            'LDAP_USER_FILTER',
            '(&(objectClass=inetOrgPerson)(uid=%s))'
        ),

        'support_group' => env(
            'LDAP_SUPPORT_GROUP_FILTER',
            '(&(objectClass=posixGroup)(cn=%s)(gidNumber=%d))'
        ),
    ],
];
