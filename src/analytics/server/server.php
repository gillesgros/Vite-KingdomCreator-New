<?php
require_once __DIR__ . '/analytics-common.php';

analyticsSetAllowedMethods('POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST, OPTIONS');
    respond(405, ['error' => 'Cette entrée accepte uniquement la collecte des événements.']);
}

$source = analyticsSourceFromRequest();
list($db) = analyticsOpenDatabase($source);
$rawInput = file_get_contents('php://input');
if (strlen($rawInput) > 65536) {
    respond(413, ['error' => 'La requête dépasse la taille maximale autorisée.']);
}
$input = json_decode($rawInput, true);
if (is_array($input) && isset($input['action'])) {
    respond(405, ['error' => 'Les opérations du tableau de bord utilisent dashboard.php.']);
}
if (!is_array($input) ||
    !isset($input['eventOrError'], $input['eventType']) ||
    !array_key_exists('params', $input) ||
    !is_string($input['eventOrError']) ||
    !is_string($input['eventType']) ||
    (isset($input['sessionId']) &&
        (!is_string($input['sessionId']) ||
            strlen($input['sessionId']) > 64 ||
            !preg_match('/^[A-Fa-f0-9-]+$/', $input['sessionId']))) ||
    strlen($input['eventOrError']) > 32 ||
    strlen($input['eventType']) > 80) {
    respond(400, ['error' => 'Données d’événement invalides.']);
}

$clientDate = null;
if (array_key_exists('clientTimestamp', $input)) {
    $clientDate = parseClientTimestamp($input['clientTimestamp']);
    if ($clientDate === false) {
        respond(400, ['error' => 'L’horodatage client est invalide.']);
    }
}

$paramsData = $input['params'];
if (!is_array($paramsData)) {
    $paramsData = ['data' => $paramsData];
}
$clientContext = $input['clientContext'] ?? [];
if (!is_array($clientContext)) {
    respond(400, ['error' => 'Le contexte client est invalide.']);
}
if (!array_key_exists('clientContext', $input)) {
    foreach (['browser', 'device'] as $contextKey) {
        if (isset($paramsData[$contextKey])) {
            $clientContext[$contextKey] = $paramsData[$contextKey];
        }
    }
}
foreach (['browser', 'device'] as $contextKey) {
    if (isset($clientContext[$contextKey]) &&
        (!is_string($clientContext[$contextKey]) || strlen($clientContext[$contextKey]) > 80)) {
        respond(400, ['error' => 'Le contexte client contient une valeur invalide.']);
    }
    unset($paramsData[$contextKey]);
}

unset($clientContext['country'], $clientContext['subdivision'], $clientContext['ip']);
unset($paramsData['country'], $paramsData['subdivision'], $paramsData['ip']);
$geoData = getRequestGeoData();
$clientContext['ip'] = getRequestIp();
$clientContext['country'] = $geoData['country'];
//if ($geoData['subdivision'] !== null && in_array($geoData['country'], ['US', 'CA'], true)) {
if ($geoData['subdivision'] !== null) {

$clientContext['subdivision'] = $geoData['subdivision'];
}

$params = json_encode($paramsData);
if ($params === false) {
    respond(400, ['error' => 'Les paramètres ne peuvent pas être encodés en JSON.']);
}
$clientContextJson = json_encode($clientContext);
if ($clientContextJson === false || strlen($clientContextJson) > 8192) {
    respond(400, ['error' => 'Le contexte client ne peut pas être encodé ou dépasse la taille maximale.']);
}

try {
    $stmt = $db->prepare(
        'INSERT INTO RequestLog (date, eventOrError, eventType, params, sessionId, clientContext, clientDate, country, device, browser) ' .
        'VALUES (:date, :eventOrError, :eventType, :params, :sessionId, :clientContext, :clientDate, :country, :device, :browser)'
    );
    $stmt->execute([
        ':date' => (new DateTimeImmutable())->format('Y-m-d H:i:s.u'),
        ':eventOrError' => $input['eventOrError'],
        ':eventType' => $input['eventType'],
        ':params' => $params,
        ':sessionId' => $input['sessionId'] ?? null,
        ':clientContext' => $clientContextJson,
        ':clientDate' => $clientDate,
        ':country' => $clientContext['country'] ?? null,
        ':device' => $clientContext['device'] ?? null,
        ':browser' => $clientContext['browser'] ?? null,
    ]);
    respond(200, ['message' => 'Requête enregistrée.']);
} catch (Exception $e) {
    error_log('Analytics event insert failed: ' . $e->getMessage());
    respond(500, ['error' => 'Erreur lors de l’enregistrement des données.']);
}
