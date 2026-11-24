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

$source = $argv[1] ?? '';
if (!is_string($source) || !preg_match('/^[A-Za-z0-9._-]{1,253}$/D', $source) ||
    $source === '.' || $source === '..' || substr($source, -3) === '.db') {
    fwrite(STDERR, 'Usage: php prepare-database-v7.2.php <source-without-.db>' . PHP_EOL);
    exit(2);
}

$serverDirectory = dirname(__DIR__);
$databaseDirectory = $serverDirectory . DIRECTORY_SEPARATOR . 'databases';
$backupDirectory = dirname($serverDirectory) . DIRECTORY_SEPARATOR .
    basename($serverDirectory) . '-backups';

try {
    if (!is_dir($databaseDirectory) || !is_readable($databaseDirectory) || !is_writable($databaseDirectory)) {
        throw new RuntimeException('Database directory is missing or not readable/writable.');
    }

    $result = prepareAnalyticsSourceDatabase($source, $databaseDirectory, $backupDirectory);
    if ($result['status'] === 'missing') {
        fwrite(STDOUT, 'No existing database or archive for ' . $source . '; server.php will create it on first use.' . PHP_EOL);
        exit(0);
    }

    fwrite(STDOUT, 'Prepared ' . $source . ': ' . $result['archiveRows'] . ' archived rows.' . PHP_EOL);
    fwrite(STDOUT, 'Moved archive: ' . $result['archivePath'] . PHP_EOL);
    fwrite(STDOUT, 'Active database: ' . $result['databasePath'] . PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Database preparation failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
