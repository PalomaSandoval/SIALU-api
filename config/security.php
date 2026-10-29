<?php

return [
    'login' => [
        'throttle_enabled' => env('LOGIN_THROTTLE_ENABLED', true),
        'max_attempts' => (int) env('LOGIN_MAX_ATTEMPTS', 5),
        'decay_seconds' => (int) env('LOGIN_DECAY_SECONDS', 300),
    ],
    'ldap_password_encryption' => [
        'cipher' => env('LDAP_PASSWORD_ENCRYPTION_CIPHER', 'aes-256-gcm'),
        'key_base64' => env('LDAP_PASSWORD_ENCRYPTION_KEY_BASE64'),
    ],
];
