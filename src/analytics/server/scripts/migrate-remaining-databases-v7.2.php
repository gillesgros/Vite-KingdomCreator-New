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

$serverDirectory = dirname(__DIR__);
$databaseDirectory = $serverDirectory . DIRECTORY_SEPARATOR . 'databases';
$backupDirectory = dirname($serverDirectory) . DIRECTORY_SEPARATOR .
    basename($serverDirectory) . '-backups';
$primarySource = 'dominionrandomizer.com';

try {
    if (!is_dir($databaseDirectory) || !is_readable($databaseDirectory) || !is_writable($databaseDirectory)) {
        throw new RuntimeException('Database directory is missing or not readable/writable.');
    }

    $filenames = scandir($databaseDirectory);
    if ($filenames === false) {
        throw new RuntimeException('Could not list database directory.');
    }

    $sources = [];
    foreach ($filenames as $filename) {
        if (preg_match('/^([A-Za-z0-9._-]+)\.db$/D', $filename, $matches) &&
            is_file($databaseDirectory . DIRECTORY_SEPARATOR . $filename) &&
            $matches[1] !== $primarySource) {
            $sources[$matches[1]] = true;
        }
    }

    if (is_dir($backupDirectory)) {
        $backupNames = scandir($backupDirectory);
        if ($backupNames === false) {
            throw new RuntimeException('Could not list private backup directory.');
        }
        foreach ($backupNames as $filename) {
            if (preg_match('/^(.+)-v7\.2-moved\.db$/D', $filename, $matches) &&
                $matches[1] !== $primarySource) {
                $sources[$matches[1]] = true;
            }
        }
    }

    ksort($sources, SORT_STRING);
    foreach (array_keys($sources) as $source) {
        $prepared = prepareAnalyticsSourceDatabase($source, $databaseDirectory, $backupDirectory);
        if ($prepared['status'] === 'missing') {
            fwrite(STDOUT, 'Skipped missing source ' . $source . '.' . PHP_EOL);
            continue;
        }
        $imported = importAnalyticsSourceArchive($source, $databaseDirectory, $backupDirectory);
        fwrite(
            STDOUT,
            $source . ': ' . $imported['status'] . ', ' . $imported['importedRows'] . ' archived rows.' . PHP_EOL
        );
    }

    fwrite(STDOUT, 'Remaining database migration phase completed.' . PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Remaining database migration failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
