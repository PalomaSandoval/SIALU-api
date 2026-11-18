<?php

return [
    'persistence_enabled' => env('SIALU_PERSISTENCE_ENABLED', false),
    'staging' => ['disk' => 'ingestion'],
    'sources' => [
        'orion' => env('ORION_SOURCE_ID'),
        'paloalto' => env('PALOALTO_SOURCE_ID'),
    ],
    'paloalto' => ['timezone' => env('PALOALTO_TIMEZONE')],
    // Severity comes only from triggered Orion alerts. Re-read this many minutes of AlertHistory before the cursor.
    // Dashboard device state is shown only from an Orion.Nodes.Status snapshot at most this old; older snapshots are purged.
    'orion' => ['alert_history_overlap_minutes' => (int) env('ORION_ALERT_HISTORY_OVERLAP_MINUTES', 60),
        'status_max_age_minutes' => (int) env('ORION_STATUS_MAX_AGE_MINUTES', 15),
        'status_retention_days' => (int) env('ORION_STATUS_RETENTION_DAYS', 7),
        // DOWN/UP activity is shown as current only if Orion.Events was read through at most this many minutes ago.
        'events_max_age_minutes' => (int) env('ORION_EVENTS_MAX_AGE_MINUTES', 15)],
    // Laravel Scheduler (routes/console.php), run every minute by the SialuScheduler Windows service.
    // Each job is off unless enabled in .env; lock_minutes is how long a crashed run blocks the next one.
    'schedule' => [
        'orion_status' => [
            'enabled' => (bool) env('ORION_STATUS_SCHEDULE_ENABLED', false),
            'lock_minutes' => (int) env('ORION_STATUS_SCHEDULE_LOCK_MINUTES', 10),
        ],
        // `sialu:sync orion --persist` (Orion.Events DOWN/UP), existing cursor only.
        'orion_events' => [
            'enabled' => (bool) env('ORION_EVENTS_SCHEDULE_ENABLED', false),
            'lock_minutes' => (int) env('ORION_EVENTS_SCHEDULE_LOCK_MINUTES', 10),
        ],
        // Only `sialu:sync paloalto-threat --persist` (existing cursor, never --since). TRAFFIC and
        // paloalto-watch are deliberately not scheduled. Each run reads at most page_size × max_pages rows.
        'paloalto_threat' => [
            'enabled' => (bool) env('PALOALTO_THREAT_SCHEDULE_ENABLED', false),
            'lock_minutes' => (int) env('PALOALTO_THREAT_SCHEDULE_LOCK_MINUTES', 30),
            'page_size' => (int) env('PALOALTO_THREAT_SCHEDULE_PAGE_SIZE', 500),
            'max_pages' => (int) env('PALOALTO_THREAT_SCHEDULE_MAX_PAGES', 20),
        ],
        // Monthly availability report (docs/disponibilidad-mensual.md): every minute the pending capture requests are
        // run; every 5 minutes the closed month without a validated capture is requested (from start_at, Orion local
        // time UTC-6, on the first day of the following month), with retries spaced out after failures.
        'orion_monthly' => [
            'enabled' => (bool) env('ORION_MONTHLY_CAPTURE_SCHEDULE_ENABLED', false),
            'start_at' => (string) env('ORION_MONTHLY_CAPTURE_START_AT', '02:00'),
        ],
    ],
    // Coverage and freshness of ingestion (read-only views; see App\Services\Coverage).
    // max_lag_minutes: a stream whose last successful query is older than this is shown as delayed.
    // observed_retention_days: retention SEEN in each source, approximate; only used to estimate what
    // probably can no longer be read. The real retention decides the exact boundary. Null = not observed.
    // late_margin_minutes: a continuous read must extend this far past a day to prove it had no events.
    // history_start: start of SIALU's history after a data reset (YYYY-MM-DD or ISO 8601, UTC). Lots, events,
    // cursor positions and gaps before it are ignored; until the first ingestion streams show "aún sin datos".
    'coverage' => [
        'history_start' => env('SIALU_HISTORY_START'),
        'max_lag_minutes' => [
            'orion' => (int) env('ORION_EVENTS_MAX_LAG_MINUTES', 360),
            'orion-alerts' => (int) env('ORION_ALERTS_MAX_LAG_MINUTES', 120),
            'paloalto' => (int) env('PALOALTO_MAX_LAG_MINUTES', 30),
        ],
        'observed_retention_days' => [
            'orion' => env('ORION_EVENTS_OBSERVED_RETENTION_DAYS', 30),
            'orion-alerts' => env('ORION_ALERTS_OBSERVED_RETENTION_DAYS', 3),
            'paloalto-threat' => env('PALOALTO_OBSERVED_RETENTION_DAYS'),
            'paloalto-traffic' => env('PALOALTO_OBSERVED_RETENTION_DAYS'),
        ],
        'late_margin_minutes' => (int) env('INGESTION_LATE_MARGIN_MINUTES', 60),
    ],
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
