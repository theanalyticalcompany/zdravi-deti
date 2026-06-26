<?php

return [
    'app' => [
        'name' => 'Zdraví dětí',
        'base_url' => 'https://example.cz',
        'session_name' => 'zdravi_deti_session',
        'timezone' => 'Europe/Prague',
    ],
    'db' => [
        'dsn' => 'mysql:host=localhost;dbname=DB_NAME;charset=utf8mb4',
        'user' => 'DB_USER',
        'password' => 'DB_PASSWORD',
    ],
    'google' => [
        'client_id' => '',
        'client_secret' => '',
        'redirect_uri' => 'https://example.cz/?r=google_callback',
    ],
    'documents' => [
        'encrypt_uploads' => false,
        'encryption_key' => '', // base64:32-byte-key, keep only in config/config.php
    ],
    'mail' => [
        'enabled' => false,
        'transport' => 'mail', // log/mail/smtp/api
        'from' => 'noreply@example.cz',
        'smtp' => [
            'host' => '',
            'port' => 587,
            'username' => '',
            'password' => '',
            'encryption' => 'tls', // tls/ssl/none
        ],
        'api' => [
            'url' => '',
            'token' => '',
        ],
    ],
];
