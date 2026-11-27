<?php
require_once __DIR__ . '/database-migration.php';

$requestStartedAt = microtime(true);
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Dashboard-Token');
header('Access-Control-Expose-Headers: Server-Timing');
header('X-Content-Type-Options: nosniff');

function analyticsSetAllowedMethods($methods) {
    header('Access-Control-Allow-Methods: ' . $methods);
}

function requestAcceptsGzip() {
    $headerValue = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
    if (!is_string($headerValue)) {
        return false;
    }

    $qualities = [];
    foreach (explode(',', $headerValue) as $encodingValue) {
        $parts = explode(';', $encodingValue);
        $encoding = strtolower(trim(array_shift($parts)));
        if ($encoding === '') {
            continue;
        }

        $quality = 1.0;
        foreach ($parts as $parameter) {
            if (preg_match('/^\s*q\s*=\s*(.+)\s*$/i', $parameter, $matches)) {
                $quality = is_numeric($matches[1]) ? (float)$matches[1] : 0.0;
                break;
            }
        }
        $qualities[$encoding] = $quality >= 0 && $quality <= 1 ? $quality : 0.0;
    }

    $gzipQuality = array_key_exists('gzip', $qualities)
        ? $qualities['gzip']
        : ($qualities['*'] ?? 0.0);
    return $gzipQuality > 0;
}

function respond($status, $data, $serverTimings = []) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    $serializationStartedAt = microtime(true);
    $body = json_encode($data);
    if ($serverTimings) {
        $serverTimings['json_encode'] = (microtime(true) - $serializationStartedAt) * 1000;
    }

    if (strlen($body) >= 1024) {
        header('Vary: Accept-Encoding');
        if (requestAcceptsGzip()) {
            if (function_exists('gzencode')) {
                $compressionStartedAt = microtime(true);
                $compressedBody = gzencode($body, 6);
                if ($compressedBody !== false) {
                    $body = $compressedBody;
                    header('Content-Encoding: gzip');
                    if ($serverTimings) {
                        $serverTimings['gzip'] = (microtime(true) - $compressionStartedAt) * 1000;
                    }
                } else {
                    error_log('Analytics response gzip compression failed.');
                }
            } else {
                error_log('Analytics response gzip compression unavailable: gzencode is missing.');
            }
        }
    }

    if ($serverTimings) {
        $metrics = [];
        foreach ($serverTimings as $name => $duration) {
            $metricName = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$name);
            if ($metricName !== '' && is_numeric($duration)) {
                $metrics[] = $metricName . ';dur=' . number_format(max(0, (float)$duration), 2, '.', '');
            }
        }
        if ($metrics) {
            header('Server-Timing: ' . implode(', ', $metrics));
        }
    }
    echo $body;
    exit;
}

function parseAnalyticsDateTimeFilter($value, $label) {
    if ($value === '') {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value);
    $dateErrors = DateTimeImmutable::getLastErrors();
    if (!$date ||
        ($dateErrors !== false && ($dateErrors['warning_count'] || $dateErrors['error_count'])) ||
        $date->format('Y-m-d\TH:i') !== $value) {
        respond(400, ['error' => 'Le filtre ' . $label . ' doit respecter le format AAAA-MM-JJ HH:MM.']);
    }

    return $date;
}

function parseClientTimestamp($value) {
    if (!is_string($value) ||
        !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $value)) {
        return false;
    }

    $date = DateTimeImmutable::createFromFormat(
        '!Y-m-d\TH:i:s.v\Z',
        $value,
        new DateTimeZone('UTC')
    );
    $dateErrors = DateTimeImmutable::getLastErrors();
    if (!$date ||
        ($dateErrors !== false && ($dateErrors['warning_count'] || $dateErrors['error_count'])) ||
        $date->format('Y-m-d\TH:i:s.v\Z') !== $value) {
        return false;
    }

    return $date->setTimezone(new DateTimeZone(date_default_timezone_get()))
        ->format('Y-m-d H:i:s.u');
}

function requireDashboardAuth($adminToken) {
    $providedToken = $_SERVER['HTTP_X_DASHBOARD_TOKEN'] ?? '';
    if (!$adminToken || !$providedToken || !hash_equals($adminToken, $providedToken)) {
        respond($adminToken ? 401 : 503, [
            'error' => $adminToken
                ? 'Jeton du tableau de bord invalide.'
                : 'Le serveur doit configurer ANALYTICS_DASHBOARD_TOKEN.',
        ]);
    }
}

function getRequestIp() {
    $remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!is_string($remoteAddress) ||
        filter_var($remoteAddress, FILTER_VALIDATE_IP) === false) {
        return null;
    }

    return $remoteAddress;
}

function getRequestGeoData() {
    $remoteAddress = getRequestIp();
    if ($remoteAddress === null) {
        return ['country' => 'unknown', 'subdivision' => null];
    }

    $geoIpDirectory = dirname(__DIR__, 2) . '/geoip-reader';
    $autoloadPath = $geoIpDirectory . '/vendor/autoload.php';
    $cityDbPath = $geoIpDirectory . '/GeoLite2-City.mmdb';
    $countryDbPath = $geoIpDirectory . '/GeoLite2-Country.mmdb';

    $useCity = is_readable($cityDbPath);
    $databasePath = $useCity ? $cityDbPath : $countryDbPath;

    if (!is_readable($autoloadPath) || !is_readable($databasePath)) {
        error_log('GeoIP lookup unavailable: reader autoload or database is missing.');
        respond(500, ['error' => 'La base GeoIP ou son lecteur est inaccessible.']);
    }

    require_once $autoloadPath;
    static $reader = null;

    try {
        if ($reader === null) {
            $reader = new \GeoIp2\Database\Reader($databasePath);
        }
        if ($useCity) {
            $record = $reader->city($remoteAddress);
            $countryCode = $record->country->isoCode;
            $subdivision = $record->mostSpecificSubdivision->name;
        } else {
            $countryCode = $reader->country($remoteAddress)->country->isoCode;
            $subdivision = null;
        }
    } catch (\GeoIp2\Exception\AddressNotFoundException $e) {
        return ['country' => 'unknown', 'subdivision' => null];
    } catch (\Exception $e) {
        error_log('GeoIP lookup failed: ' . $e->getMessage());
        respond(500, ['error' => 'La recherche GeoIP a échoué.']);
    }

    $validCountry = is_string($countryCode) && preg_match('/^[A-Z]{2}$/', $countryCode)
        ? $countryCode
        : 'unknown';

    return [
        'country' => $validCountry,
        'subdivision' => is_string($subdivision) && $subdivision !== '' ? $subdivision : null,
    ];
}

function decodeLegacyKingdom($serializedKingdom) {
    if (!is_array($serializedKingdom)) {
        return null;
    }

    $kingdom = [];
    foreach ([
        'supply', 'events', 'landmarks', 'projects', 'ways',
        'boons', 'traits', 'ally', 'prophecy',
    ] as $category) {
        $value = $serializedKingdom[$category] ?? '';
        $kingdom[$category] = is_string($value) && $value !== ''
            ? explode(',', $value)
            : [];
    }
    $kingdom['specialSupply'] = [];
    foreach (['bane', 'ferryman', 'riverboat'] as $category) {
        $value = $serializedKingdom[$category] ?? '';
        if (is_string($value) && $value !== '') {
            $kingdom['specialSupply'][$category] = explode(',', $value);
        }
    }
    return $kingdom;
}

function analyticsEnsureDatabaseDirectory() {
    $databaseDirectory = __DIR__ . '/databases';
    if (!is_dir($databaseDirectory) &&
        !mkdir($databaseDirectory, 0755, true) &&
        !is_dir($databaseDirectory)) {
        respond(500, ['error' => 'Impossible de créer le répertoire des bases de données.']);
    }

    return $databaseDirectory;
}

function analyticsListSources() {
    $databaseDirectory = analyticsEnsureDatabaseDirectory();
    $sources = [];
    foreach (scandir($databaseDirectory) as $filename) {
        if (pathinfo($filename, PATHINFO_EXTENSION) === 'db' &&
            preg_match('/^[A-Za-z0-9._-]+\.db$/', $filename) &&
            is_file($databaseDirectory . '/' . $filename)) {
            $sources[] = pathinfo($filename, PATHINFO_FILENAME);
        }
    }

    return $sources;
}

function analyticsSourceFromRequest() {
    $source = $_GET['source'] ?? '';
    if (!is_string($source) ||
        !preg_match('/^[A-Za-z0-9._-]{1,253}$/', $source) ||
        $source === '.' ||
        $source === '..') {
        respond(400, ['error' => 'Le paramètre "source" est invalide ou absent.']);
    }

    return $source;
}

function analyticsDashboardToken() {
    $adminToken = getenv('ANALYTICS_DASHBOARD_TOKEN');
    if (!$adminToken && is_file(__DIR__ . '/server-config.php')) {
        $serverConfiguration = require __DIR__ . '/server-config.php';
        $adminToken = is_array($serverConfiguration)
            ? ($serverConfiguration['dashboardToken'] ?? '')
            : '';
    }

    return $adminToken;
}

function analyticsOpenDatabase($source) {
    $databaseDirectory = analyticsEnsureDatabaseDirectory();
    $databaseSetupStartedAt = microtime(true);
    $metadataMigrationDurationMs = 0;
    try {
        $db = new PDO('sqlite:' . $databaseDirectory . '/' . $source . '.db');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA busy_timeout = 30000');
        $metadataMigrationDurationMs = initializeAnalyticsDatabase($db);
        $databaseSetupDurationMs = (microtime(true) - $databaseSetupStartedAt) * 1000;
    } catch (Throwable $e) {
        error_log('Analytics database connection failed: ' . $e->getMessage());
        respond(500, ['error' => 'Erreur de connexion à la base de données.']);
    }

    return [$db, $databaseSetupDurationMs, $metadataMigrationDurationMs];
}
