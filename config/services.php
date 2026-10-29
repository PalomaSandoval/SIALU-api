<?php

return [
    'orion' => [
        'host' => env('ORION_HOST'), 'port' => env('ORION_PORT', 17778),
        'username' => env('ORION_USERNAME'), 'password' => env('ORION_PASSWORD'),
        'ssl_verify' => env('ORION_SSL_VERIFY', true),
    ],
    'paloalto' => [
        'host' => env('PALOALTO_HOST'), 'port' => env('PALOALTO_PORT', 443),
        'api_key' => env('PALOALTO_API_KEY'), 'ssl_verify' => env('PALOALTO_SSL_VERIFY', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
