<?php

return [
    'persistence_enabled' => env('SIALU_PERSISTENCE_ENABLED', false),
    'staging' => ['disk' => 'ingestion'],
    'sources' => [
        'orion' => env('ORION_SOURCE_ID'),
        'paloalto' => env('PALOALTO_SOURCE_ID'),
    ],
    'paloalto' => ['timezone' => env('PALOALTO_TIMEZONE')],
    // Enable only with the reviewed severity rollout; existing workers retain their configuration.
    'orion' => ['severity_v2_enabled' => env('ORION_SEVERITY_V2_ENABLED', false)],
    'regenerate_interval' => (int) env('SESSION_REGENERATE_INTERVAL', 300),
    'procedures' => [
        'find_user' => env('DB_SP_FIND_USER', 'dbo.sp_catcUsuarios'),
        'sessions' => env('DB_SP_SESSIONS', 'dbo.sp_movcSesiones'),
    ],
    'session_actions' => [
        'create' => (int) env('DB_SP_SESSION_CREATE_ACTION', 1),
        'close' => (int) env('DB_SP_SESSION_CLOSE_ACTION', 6),
        'expire' => (int) env('DB_SP_SESSION_EXPIRE_ACTION', 7),
        'find' => (int) env('DB_SP_SESSION_FIND_ACTION', 5),
    ],
];
