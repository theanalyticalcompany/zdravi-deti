<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$dbPath = $root . '/var/local.sqlite';
$schemaPath = $root . '/database/schema.sqlite.sql';
$seedCandidates = [
    $root . '/database/seed/nrpzs_providers.csv',
    $root . '/var/nrpzs/export-2026-06.csv',
];

if (!is_dir($root . '/var')) {
    mkdir($root . '/var', 0777, true);
}

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec(file_get_contents($schemaPath));

echo "SQLite database created: {$dbPath}\n";

$config = [
    'app' => [
        'name' => 'Zdraví dětí',
        'base_url' => 'http://127.0.0.1:8080',
        'timezone' => 'Europe/Prague',
    ],
    'db' => [
        'dsn' => 'sqlite:' . $dbPath,
        'user' => null,
        'password' => null,
    ],
    'mail' => [
        'enabled' => false,
        'transport' => 'log',
        'from' => 'noreply@example.test',
    ],
    'google' => [
        'client_id' => '',
        'client_secret' => '',
        'redirect_uri' => '',
    ],
];
date_default_timezone_set($config['app']['timezone']);
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = $_SERVER['HTTP_USER_AGENT'] ?? 'init-sqlite';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$_SESSION = [];

require $root . '/app/helpers.php';
require $root . '/app/repositories.php';
ensure_runtime_schema();

$seedPath = null;
foreach ($seedCandidates as $candidate) {
    if (is_file($candidate)) {
        $seedPath = $candidate;
        break;
    }
}

if ($seedPath === null) {
    echo "NRPZS seed CSV not found. Put it in database/seed/nrpzs_providers.csv or var/nrpzs/export-2026-06.csv and run this script again to include doctors.\n";
    exit(0);
}

$duplicateIds = nrpzs_duplicate_source_ids($seedPath);
delete_base_provider_source_ids($duplicateIds);
$imported = import_nrpzs_csv($seedPath, $duplicateIds);
echo "NRPZS providers imported: {$imported}\n";

function nrpzs_csv_header($handle): array
{
    $header = fgetcsv($handle, 0, ';', '"');
    if (!$header) {
        throw new RuntimeException('CSV header not found.');
    }
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    return $header;
}

function nrpzs_duplicate_source_ids(string $csvPath): array
{
    $handle = fopen($csvPath, 'rb');
    if (!$handle) {
        throw new RuntimeException('Cannot open NRPZS CSV.');
    }
    $header = nrpzs_csv_header($handle);
    $counts = [];
    while (($values = fgetcsv($handle, 0, ';', '"')) !== false) {
        if (count($values) !== count($header)) {
            continue;
        }
        $row = array_combine($header, $values);
        $sourceId = $row ? provider_base_source_id($row) : null;
        if ($sourceId) {
            $counts[$sourceId] = ($counts[$sourceId] ?? 0) + 1;
        }
    }
    fclose($handle);
    return array_map('strval', array_keys(array_filter($counts, fn($count) => $count > 1)));
}

function import_nrpzs_csv(string $csvPath, array $duplicateIds): int
{
    $handle = fopen($csvPath, 'rb');
    if (!$handle) {
        throw new RuntimeException('Cannot open NRPZS CSV.');
    }
    $header = nrpzs_csv_header($handle);
    $imported = 0;
    $read = 0;
    db()->beginTransaction();
    try {
        while (($values = fgetcsv($handle, 0, ';', '"')) !== false) {
            if (count($values) !== count($header)) {
                continue;
            }
            $row = array_combine($header, $values);
            if ($row && in_array(provider_base_source_id($row), $duplicateIds, true)) {
                $row['__duplicate_source_id'] = true;
            }
            if ($row && import_nrpzs_provider_row($row)) {
                $imported++;
            }
            $read++;
            if ($read % 1000 === 0) {
                db()->commit();
                db()->beginTransaction();
            }
        }
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    } finally {
        fclose($handle);
    }
    return $imported;
}
