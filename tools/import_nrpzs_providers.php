<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Forbidden\n";
    exit(1);
}

$csvPath = $argv[1] ?? null;
if (!$csvPath || !is_file($csvPath)) {
    fwrite(STDERR, "Usage: php tools/import_nrpzs_providers.php path/to/export.csv\n");
    exit(1);
}

require __DIR__ . '/../app/bootstrap.php';

function open_csv(string $csvPath)
{
    $handle = fopen($csvPath, 'rb');
    if (!$handle) {
        fwrite(STDERR, "Cannot open CSV file.\n");
        exit(1);
    }
    return $handle;
}

function read_csv_header($handle): array
{
    $header = fgetcsv($handle, 0, ';', '"');
    if (!$header) {
        fwrite(STDERR, "CSV header not found.\n");
        exit(1);
    }
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    return $header;
}

function duplicate_source_ids(string $csvPath): array
{
    $handle = open_csv($csvPath);
    $header = read_csv_header($handle);
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

$duplicateSourceIds = duplicate_source_ids($csvPath);
if ($duplicateSourceIds) {
    delete_base_provider_source_ids($duplicateSourceIds);
    fwrite(STDOUT, 'duplicate_source_ids=' . count($duplicateSourceIds) . "\n");
}

$handle = open_csv($csvPath);
$header = read_csv_header($handle);

$imported = 0;
$read = 0;
$batchSize = 500;
db()->beginTransaction();
try {
    while (($values = fgetcsv($handle, 0, ';', '"')) !== false) {
        $read++;
        if (count($values) !== count($header)) {
            continue;
        }
        $row = array_combine($header, $values);
        if ($row && in_array(provider_base_source_id($row), $duplicateSourceIds, true)) {
            $row['__duplicate_source_id'] = true;
        }
        if ($row && import_nrpzs_provider_row($row)) {
            $imported++;
        }
        if ($read % $batchSize === 0) {
            db()->commit();
            db()->beginTransaction();
            fwrite(STDOUT, "read={$read} imported={$imported}\n");
        }
    }
    db()->commit();
} catch (Throwable $e) {
    db()->rollBack();
    throw $e;
} finally {
    fclose($handle);
}

fwrite(STDOUT, "done read={$read} imported={$imported}\n");
