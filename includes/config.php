<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// This file only exposes configuration values. It does not create a database connection.
return [
    'app' => [
        'env' => app_env('APP_ENV', 'local'),
        'url' => rtrim((string) app_env('APP_URL', 'http://localhost/shagun-ladies-tailor'), '/'),
    ],
    'database' => [
        'host' => app_env('DB_HOST', '127.0.0.1'),
        'port' => (int) app_env('DB_PORT', '3306'),
        'name' => app_env('DB_NAME', 'shagun_ladies_tailor'),
        'user' => app_env('DB_USER', 'root'),
        'password' => app_env('DB_PASSWORD', ''),
    ],
    'services' => [
        'google' => [
            'client_id' => app_env('GOOGLE_CLIENT_ID', ''),
            'client_secret' => app_env('GOOGLE_CLIENT_SECRET', ''),
        ],
        'payment' => [
            'key' => app_env('PAYMENT_KEY', ''),
            'secret' => app_env('PAYMENT_SECRET', ''),
        ],
        'whatsapp' => [
            'token' => app_env('WHATSAPP_TOKEN', ''),
        ],
    ],
];
