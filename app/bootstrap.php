<?php

declare(strict_types=1);

$configPath = __DIR__ . '/../config/config.php';
if (!is_file($configPath)) {
    $configPath = __DIR__ . '/../config/config.example.php';
}

$config = require $configPath;
date_default_timezone_set($config['app']['timezone'] ?? 'Europe/Prague');

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_name($config['app']['session_name'] ?? 'zdravi_deti_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

try {
    $pdo = new PDO(
        $config['db']['dsn'],
        $config['db']['user'],
        $config['db']['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    if (strpos((string)$config['db']['dsn'], 'sqlite:') === 0) {
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>Aplikace není nakonfigurovaná</h1>';
    echo '<p>Zkontrolujte soubor <code>config/config.php</code> a databázové připojení.</p>';
    exit;
}

require __DIR__ . '/helpers.php';
require __DIR__ . '/repositories.php';
ensure_runtime_schema();
require __DIR__ . '/views.php';
require __DIR__ . '/routes.php';
