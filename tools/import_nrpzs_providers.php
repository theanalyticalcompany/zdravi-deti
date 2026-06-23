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

$handle = fopen($csvPath, 'rb');
if (!$handle) {
    fwrite(STDERR, "Cannot open CSV file.\n");
    exit(1);
}

$header = fgetcsv($handle, 0, ';', '"');
if (!$header) {
    fwrite(STDERR, "CSV header not found.\n");
    exit(1);
}
$header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);

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
