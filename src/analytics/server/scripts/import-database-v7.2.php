<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'database-migration.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, 'PDO SQLite is not available in this PHP CLI.' . PHP_EOL);
    exit(1);
}

$checkStatus = ($argv[1] ?? '') === '--status';
$source = $checkStatus ? ($argv[2] ?? '') : ($argv[1] ?? '');
if (!is_string($source) || !preg_match('/^[A-Za-z0-9._-]{1,253}$/D', $source) ||
    $source === '.' || $source === '..' || substr($source, -3) === '.db') {
    fwrite(STDERR, 'Usage: php import-database-v7.2.php [--status] <source-without-.db>' . PHP_EOL);
    exit(2);
}

$serverDirectory = dirname(__DIR__);
$databaseDirectory = $serverDirectory . DIRECTORY_SEPARATOR . 'databases';
$backupDirectory = dirname($serverDirectory) . DIRECTORY_SEPARATOR .
    basename($serverDirectory) . '-backups';

try {
    if ($checkStatus) {
        $status = analyticsDataMigrationStatus($source, $databaseDirectory, $backupDirectory);
        fwrite(STDOUT, 'migration-status=' . $status['status'] . ';database-version=' . $status['version'] . PHP_EOL);
        exit(0);
    }

    $result = importAnalyticsSourceArchive($source, $databaseDirectory, $backupDirectory);
    if ($result['status'] === 'missing') {
        fwrite(STDOUT, 'No moved archive for ' . $source . '; nothing to import.' . PHP_EOL);
        exit(0);
    }
    if ($result['status'] === 'already-imported') {
        fwrite(STDOUT, 'Archive already imported for ' . $source . ': ' . $result['importedRows'] . ' rows.' . PHP_EOL);
        exit(0);
    }

    fwrite(STDOUT, 'Imported ' . $result['importedRows'] . ' rows for ' . $source . '.' . PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Database import failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
