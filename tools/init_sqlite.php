<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$dbPath = $root . '/var/local.sqlite';
$schemaPath = $root . '/database/schema.sqlite.sql';

if (!is_dir($root . '/var')) {
    mkdir($root . '/var', 0777, true);
}

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec(file_get_contents($schemaPath));

echo "SQLite database created: {$dbPath}\n";
