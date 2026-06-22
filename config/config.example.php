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
    'mail' => [
        'enabled' => false,
        'from' => 'noreply@example.cz',
    ],
];
