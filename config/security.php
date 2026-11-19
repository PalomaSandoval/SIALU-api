<?php

return [
    'login' => [
        'throttle_enabled' => env('LOGIN_THROTTLE_ENABLED', true),
        'max_attempts' => (int) env('LOGIN_MAX_ATTEMPTS', 5),
        'decay_seconds' => (int) env('LOGIN_DECAY_SECONDS', 300),
    ],
];
