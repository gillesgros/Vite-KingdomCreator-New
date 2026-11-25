<?php

if (!defined('ANALYTICS_DATA_MIGRATION_VERSION')) {
    define('ANALYTICS_DATA_MIGRATION_VERSION', 1);
}

function initializeAnalyticsDatabase(PDO $db)
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS RequestLog (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            date TEXT NOT NULL,
            eventOrError TEXT NOT NULL,
            eventType TEXT NOT NULL,
            params TEXT,
            sessionId TEXT,
            clientContext TEXT,
            clientDate TEXT,
            country TEXT,
            device TEXT,
            browser TEXT
        )
    ");

    $columns = $db->query('PRAGMA table_info(RequestLog)')->fetchAll(PDO::FETCH_COLUMN, 1);
    $requiredColumns = ['sessionId', 'clientContext', 'clientDate', 'country', 'device', 'browser'];
    $needsSchemaMigration = count(array_diff($requiredColumns, $columns)) > 0;
    $metadataMigrationDurationMs = 0;

    if ($needsSchemaMigration) {
        $migrationStartedAt = microtime(true);
        $db->exec('BEGIN IMMEDIATE');
        try {
            $columns = $db->query('PRAGMA table_info(RequestLog)')->fetchAll(PDO::FETCH_COLUMN, 1);
            $needsScalarMetadataBackfill =
                !in_array('country', $columns, true) ||
                !in_array('device', $columns, true) ||
                !in_array('browser', $columns, true);

            foreach ($requiredColumns as $column) {
                if (!in_array($column, $columns, true)) {
                    $db->exec('ALTER TABLE RequestLog ADD COLUMN ' . $column . ' TEXT');
                }
            }

            if ($needsScalarMetadataBackfill) {
                $db->exec(
                    "UPDATE RequestLog SET " .
                    "country = COALESCE(country, json_extract(CASE WHEN json_valid(clientContext) THEN clientContext ELSE '{}' END, '$.country')), " .
                    "device = COALESCE(device, json_extract(CASE WHEN json_valid(clientContext) THEN clientContext ELSE '{}' END, '$.device')), " .
                    "browser = COALESCE(browser, json_extract(CASE WHEN json_valid(clientContext) THEN clientContext ELSE '{}' END, '$.browser')) " .
                    "WHERE country IS NULL OR device IS NULL OR browser IS NULL"
                );
            }
            $db->exec('COMMIT');
        } catch (Throwable $migrationException) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable $rollbackException) {
                error_log('Analytics database migration rollback failed: ' . $rollbackException->getMessage());
            }
            throw $migrationException;
        }
        $metadataMigrationDurationMs = (microtime(true) - $migrationStartedAt) * 1000;
    }

    $db->exec('CREATE INDEX IF NOT EXISTS idx_RequestLog_eventType ON RequestLog(eventType)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_RequestLog_country ON RequestLog(country)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_RequestLog_device ON RequestLog(device)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_RequestLog_browser ON RequestLog(browser)');
    $db->exec(
        'CREATE INDEX IF NOT EXISTS idx_RequestLog_effectiveDate ' .
        'ON RequestLog(COALESCE(clientDate, date) DESC, id DESC)'
    );
    $db->exec('CREATE INDEX IF NOT EXISTS idx_RequestLog_category ON RequestLog(eventOrError)');

    return $metadataMigrationDurationMs;
}

function analyticsAssertIntegrity(PDO $db, $label, $schema = 'main')
{
    $result = $db->query('PRAGMA ' . $schema . '.integrity_check')->fetchColumn();
    if ($result !== 'ok') {
        throw new RuntimeException($label . ' integrity_check failed: ' . (string)$result);
    }
}

function analyticsRequestLogColumns(PDO $db)
{
    return $db->query('PRAGMA table_info(RequestLog)')->fetchAll(PDO::FETCH_COLUMN, 1);
}

function analyticsArchiveStats(PDO $db, $schema = 'main')
{
    $columns = $schema === 'main'
        ? analyticsRequestLogColumns($db)
        : $db->query('PRAGMA ' . $schema . '.table_info(RequestLog)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!$columns) {
        return ['rowCount' => 0, 'maxId' => 0];
    }
    if (!in_array('id', $columns, true)) {
        throw new RuntimeException('RequestLog is missing its id column.');
    }

    $stats = $db->query(
        'SELECT COUNT(*) AS rowCount, COALESCE(MAX(id), 0) AS maxId FROM ' . $schema . '.RequestLog'
    )->fetch(PDO::FETCH_ASSOC);
    return ['rowCount' => (int)$stats['rowCount'], 'maxId' => (int)$stats['maxId']];
}

function analyticsSetRequestLogSequence(PDO $db, $minimumId)
{
    $currentMaxId = (int)$db->query(
        'SELECT COALESCE(MAX(id), 0) FROM RequestLog'
    )->fetchColumn();
    $hasSequenceTable = $db->query(
        "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'sqlite_sequence'"
    )->fetchColumn();
    if ($hasSequenceTable === false) {
        return;
    }

    $sequence = $db->query(
        "SELECT seq FROM sqlite_sequence WHERE name = 'RequestLog'"
    )->fetchColumn();
    $requiredSequence = max((int)$minimumId, $currentMaxId, $sequence === false ? 0 : (int)$sequence);

    if ($requiredSequence === 0) {
        return;
    }
    if ($sequence === false) {
        $statement = $db->prepare('INSERT INTO sqlite_sequence (name, seq) VALUES (:name, :sequence)');
        $statement->execute([':name' => 'RequestLog', ':sequence' => $requiredSequence]);
    } elseif ((int)$sequence < $requiredSequence) {
        $statement = $db->prepare(
            'UPDATE sqlite_sequence SET seq = :sequence WHERE name = :name'
        );
        $statement->execute([':name' => 'RequestLog', ':sequence' => $requiredSequence]);
    }
}

function analyticsDataMigrationStatus($source, $databaseDirectory, $backupDirectory)
{
    if (!preg_match('/^[A-Za-z0-9._-]{1,253}$/D', $source) || $source === '.' || $source === '..') {
        throw new InvalidArgumentException('Invalid analytics source name.');
    }

    $databasePath = $databaseDirectory . DIRECTORY_SEPARATOR . $source . '.db';
    $archivePath = $backupDirectory . DIRECTORY_SEPARATOR . $source . '-v7.2-moved.db';
    if (is_link($databasePath) || is_link($archivePath)) {
        throw new RuntimeException('Refusing to inspect a symbolic-link database path.');
    }
    if (!is_file($databasePath)) {
        return [
            'status' => is_file($archivePath) ? 'pending' : 'not-needed',
            'version' => 0,
        ];
    }

    $db = new PDO('sqlite:' . $databasePath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $version = (int)$db->query('PRAGMA user_version')->fetchColumn();
    if ($version >= ANALYTICS_DATA_MIGRATION_VERSION) {
        return ['status' => 'complete', 'version' => $version];
    }

    $manifestExists = $db->query(
        "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'AnalyticsMigrationManifest'"
    )->fetchColumn();
    if ($manifestExists !== false) {
        $statement = $db->prepare(
            'SELECT 1 FROM AnalyticsMigrationManifest WHERE archiveName = :archiveName'
        );
        $statement->execute([':archiveName' => basename($archivePath)]);
        if ($statement->fetchColumn() !== false) {
            analyticsMarkDataMigrationComplete($db);
            return [
                'status' => 'complete',
                'version' => (int)$db->query('PRAGMA user_version')->fetchColumn(),
            ];
        }
    }

    return ['status' => 'pending', 'version' => $version];
}

function analyticsMarkDataMigrationComplete(PDO $db)
{
    $version = (int)$db->query('PRAGMA user_version')->fetchColumn();
    if ($version < ANALYTICS_DATA_MIGRATION_VERSION) {
        $db->exec('PRAGMA user_version = ' . ANALYTICS_DATA_MIGRATION_VERSION);
    }
}

function prepareAnalyticsSourceDatabase($source, $databaseDirectory, $backupDirectory)
{
    if (!preg_match('/^[A-Za-z0-9._-]{1,253}$/D', $source) || $source === '.' || $source === '..') {
        throw new InvalidArgumentException('Invalid analytics source name.');
    }
    if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
        throw new RuntimeException('Could not create the private backup directory.');
    }
    if (is_link($backupDirectory) || !chmod($backupDirectory, 0700) || !is_writable($backupDirectory)) {
        throw new RuntimeException('The private backup directory is unsafe or not writable.');
    }

    $databasePath = $databaseDirectory . DIRECTORY_SEPARATOR . $source . '.db';
    $archivePath = $backupDirectory . DIRECTORY_SEPARATOR . $source . '-v7.2-moved.db';
    if (is_link($databasePath) || is_link($archivePath)) {
        throw new RuntimeException('Refusing to migrate a symbolic-link database path.');
    }
    if (!file_exists($archivePath) && !is_file($databasePath)) {
        return ['status' => 'missing', 'archiveRows' => 0];
    }

    if (!file_exists($archivePath)) {
        $sourceDb = new PDO('sqlite:' . $databasePath);
        $sourceDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $sourceDb->exec('PRAGMA busy_timeout = 30000');

        $columns = analyticsRequestLogColumns($sourceDb);
        $maxId = 0;
        if ($columns) {
            if (!in_array('id', $columns, true)) {
                throw new RuntimeException('RequestLog is missing its id column for ' . $source . '.');
            }
            $maxId = (int)$sourceDb->query(
                'SELECT COALESCE(MAX(id), 0) FROM RequestLog'
            )->fetchColumn();
        }

        $checkpoint = $sourceDb->query('PRAGMA wal_checkpoint(TRUNCATE)')->fetch(PDO::FETCH_ASSOC);
        if ($checkpoint && isset($checkpoint['busy']) && (int)$checkpoint['busy'] !== 0) {
            throw new RuntimeException('SQLite is busy; cannot safely move ' . $source . '.db.');
        }
        $journalMode = strtolower((string)$sourceDb->query('PRAGMA journal_mode = DELETE')->fetchColumn());
        if ($journalMode !== 'delete') {
            throw new RuntimeException('Could not checkpoint SQLite before moving ' . $source . '.db.');
        }
        unset($sourceDb);

        foreach ([$databasePath . '-wal', $databasePath . '-shm', $databasePath . '-journal'] as $sidecarPath) {
            if (file_exists($sidecarPath)) {
                throw new RuntimeException('SQLite sidecar remains; refusing to move ' . $source . '.db.');
            }
        }
        if (!rename($databasePath, $archivePath)) {
            throw new RuntimeException('Could not move ' . $source . '.db to its private archive.');
        }
        if (!chmod($archivePath, 0600)) {
            throw new RuntimeException('Could not restrict permissions on the moved database archive.');
        }
    }

    $archive = new PDO('sqlite:' . $archivePath);
    $archive->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $archiveStats = analyticsArchiveStats($archive);
    analyticsAssertIntegrity($archive, 'Moved database archive');
    unset($archive);

    $database = new PDO('sqlite:' . $databasePath);
    $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $database->exec('PRAGMA busy_timeout = 30000');
    initializeAnalyticsDatabase($database);
    analyticsSetRequestLogSequence($database, $archiveStats['maxId']);
    analyticsAssertIntegrity($database, 'New active database');

    return [
        'status' => file_exists($archivePath) ? 'prepared' : 'missing',
        'archiveRows' => $archiveStats['rowCount'],
        'archivePath' => $archivePath,
        'databasePath' => $databasePath,
    ];
}

function importAnalyticsSourceArchive($source, $databaseDirectory, $backupDirectory)
{
    if (!preg_match('/^[A-Za-z0-9._-]{1,253}$/D', $source) || $source === '.' || $source === '..') {
        throw new InvalidArgumentException('Invalid analytics source name.');
    }

    $databasePath = $databaseDirectory . DIRECTORY_SEPARATOR . $source . '.db';
    $archivePath = $backupDirectory . DIRECTORY_SEPARATOR . $source . '-v7.2-moved.db';
    if (is_link($databasePath) || is_link($archivePath)) {
        throw new RuntimeException('Refusing to import a symbolic-link database path.');
    }
    if (!is_file($archivePath)) {
        return ['status' => 'missing', 'importedRows' => 0];
    }
    if (!is_file($databasePath)) {
        prepareAnalyticsSourceDatabase($source, $databaseDirectory, $backupDirectory);
    }

    $db = new PDO('sqlite:' . $databasePath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA busy_timeout = 30000');
    initializeAnalyticsDatabase($db);
    $db->exec('ATTACH DATABASE ' . $db->quote($archivePath) . ' AS migration_archive');

    $transactionStarted = false;
    try {
        $archiveColumns = $db->query(
            'PRAGMA migration_archive.table_info(RequestLog)'
        )->fetchAll(PDO::FETCH_COLUMN, 1);
        if ($archiveColumns) {
            foreach (['id', 'date', 'eventOrError', 'eventType', 'params'] as $requiredColumn) {
                if (!in_array($requiredColumn, $archiveColumns, true)) {
                    throw new RuntimeException(
                        'Archive for ' . $source . ' is missing RequestLog column ' . $requiredColumn . '.'
                    );
                }
            }
        }
        analyticsAssertIntegrity($db, 'Moved database archive', 'migration_archive');
        $archiveStats = analyticsArchiveStats($db, 'migration_archive');
        $archiveName = basename($archivePath);

        $db->exec('BEGIN IMMEDIATE');
        $transactionStarted = true;
        $db->exec(
            'CREATE TABLE IF NOT EXISTS AnalyticsMigrationManifest (' .
            'archiveName TEXT PRIMARY KEY, importedRows INTEGER NOT NULL, importedAt TEXT NOT NULL)'
        );

        $manifest = $db->prepare(
            'SELECT importedRows FROM AnalyticsMigrationManifest WHERE archiveName = :archiveName'
        );
        $manifest->execute([':archiveName' => $archiveName]);
        $previousImport = $manifest->fetchColumn();
        if ($previousImport !== false) {
            analyticsMarkDataMigrationComplete($db);
            $db->exec('COMMIT');
            $transactionStarted = false;
            $db->exec('DETACH DATABASE migration_archive');
            analyticsAssertIntegrity($db, 'Active database');
            return ['status' => 'already-imported', 'importedRows' => (int)$previousImport];
        }

        $beforeCount = (int)$db->query('SELECT COUNT(*) FROM RequestLog')->fetchColumn();
        analyticsSetRequestLogSequence($db, $archiveStats['maxId']);
        if ($archiveStats['rowCount'] > 0) {
            $contextExpression = in_array('clientContext', $archiveColumns, true)
                ? 'legacy.clientContext'
                : 'NULL';
            $metadataExpression = function ($column, $jsonKey) use ($archiveColumns, $contextExpression) {
                $fallback = "json_extract(CASE WHEN json_valid(" . $contextExpression .
                    ") THEN " . $contextExpression . " ELSE '{}' END, '$." . $jsonKey . "')";
                return in_array($column, $archiveColumns, true)
                    ? 'COALESCE(legacy.' . $column . ', ' . $fallback . ')'
                    : $fallback;
            };
            $optionalColumn = function ($column) use ($archiveColumns) {
                return in_array($column, $archiveColumns, true) ? 'legacy.' . $column : 'NULL';
            };

            $insertSql =
                'INSERT INTO RequestLog (id, date, eventOrError, eventType, params, sessionId, clientContext, clientDate, country, device, browser) ' .
                'SELECT CASE WHEN EXISTS (SELECT 1 FROM RequestLog AS targetRow WHERE targetRow.id = legacy.id) ' .
                'THEN NULL ELSE legacy.id END, ' .
                'legacy.date, legacy.eventOrError, legacy.eventType, legacy.params, ' .
                $optionalColumn('sessionId') . ', ' .
                $optionalColumn('clientContext') . ', ' .
                $optionalColumn('clientDate') . ', ' .
                $metadataExpression('country', 'country') . ', ' .
                $metadataExpression('device', 'device') . ', ' .
                $metadataExpression('browser', 'browser') .
                ' FROM migration_archive.RequestLog AS legacy ORDER BY legacy.id ASC';
            $db->exec($insertSql);
            $insertedRows = (int)$db->query('SELECT changes()')->fetchColumn();
            if ($insertedRows !== $archiveStats['rowCount']) {
                throw new RuntimeException('Not all archived rows were imported for ' . $source . '.');
            }
        } else {
            $insertedRows = 0;
        }

        $afterCount = (int)$db->query('SELECT COUNT(*) FROM RequestLog')->fetchColumn();
        if ($afterCount - $beforeCount !== $archiveStats['rowCount']) {
            throw new RuntimeException('Active row count verification failed for ' . $source . '.');
        }

        $manifest = $db->prepare(
            'INSERT INTO AnalyticsMigrationManifest (archiveName, importedRows, importedAt) ' .
            'VALUES (:archiveName, :importedRows, :importedAt)'
        );
        $manifest->execute([
            ':archiveName' => $archiveName,
            ':importedRows' => $insertedRows,
            ':importedAt' => gmdate('c'),
        ]);
        analyticsMarkDataMigrationComplete($db);
        $db->exec('COMMIT');
        $transactionStarted = false;
        $db->exec('DETACH DATABASE migration_archive');
        analyticsAssertIntegrity($db, 'Active database');

        return ['status' => 'imported', 'importedRows' => $insertedRows];
    } catch (Throwable $exception) {
        if ($transactionStarted) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable $rollbackException) {
                error_log('Analytics archive import rollback failed: ' . $rollbackException->getMessage());
            }
        }
        try {
            $db->exec('DETACH DATABASE migration_archive');
        } catch (Throwable $detachException) {
            error_log('Analytics archive detach failed: ' . $detachException->getMessage());
        }
        throw $exception;
    }
}
