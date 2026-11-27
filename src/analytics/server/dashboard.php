<?php
require_once __DIR__ . '/analytics-common.php';
require_once __DIR__ . '/analytics-reporting.php';

analyticsSetAllowedMethods('GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST, OPTIONS');
    respond(405, ['error' => 'Méthode HTTP non autorisée.']);
}

if ($method === 'GET' && isset($_GET['listSources'])) {
    respond(200, analyticsListSources());
}

$source = analyticsSourceFromRequest();
$adminToken = analyticsDashboardToken();
if ($method === 'GET') {
    requireDashboardAuth($adminToken);
} else {
    $rawInput = file_get_contents('php://input');
    if (strlen($rawInput) > 65536) {
        respond(413, ['error' => 'La requête dépasse la taille maximale autorisée.']);
    }
    $input = json_decode($rawInput, true);
    if (!is_array($input) || !isset($input['action'])) {
        respond(400, ['error' => 'Une action de maintenance valide est requise.']);
    }
    requireDashboardAuth($adminToken);
}

list($db, $databaseSetupDurationMs, $metadataMigrationDurationMs) = analyticsOpenDatabase($source);

if (isset($_GET['trafficLocations']) && $_GET['trafficLocations'] === 'true') {
    $pageInput = $_GET['page'] ?? '1';
    $page = is_string($pageInput)
        ? filter_var($pageInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]])
        : false;
    if ($page === false) {
        respond(400, ['error' => 'La page des localisations est invalide.']);
    }

    $snapshotId = null;
    $cursor = null;
    if ($page > 1) {
        $snapshotInput = $_GET['snapshotId'] ?? null;
        $cursorInput = $_GET['cursor'] ?? null;
        $snapshotId = is_string($snapshotInput)
            ? filter_var($snapshotInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
        $cursor = is_string($cursorInput)
            ? filter_var($cursorInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
        if ($snapshotId === false || $cursor === false) {
            respond(400, ['error' => 'Le curseur de chargement des localisations est invalide.']);
        }
    }

    try {
        respond(200, analyticsTrafficLocationsPage($db, $page, $snapshotId, $cursor, 1000));
    } catch (Exception $e) {
        error_log('Analytics traffic map retrieval failed: ' . $e->getMessage());
        respond(500, ['error' => 'Erreur lors du chargement des localisations de trafic.']);
    }
}

if ($method === 'POST') {
    try {
        if ($input['action'] === 'clear') {
            $deletedCount = $db->exec('DELETE FROM RequestLog');
            respond(200, [
                'message' => 'Toutes les données de cette base ont été supprimées.',
                'deletedCount' => $deletedCount,
            ]);
        }

        if ($input['action'] === 'purge-before') {
            $cutoffDate = $input['date'] ?? '';
            $date = is_string($cutoffDate)
                ? DateTimeImmutable::createFromFormat('!Y-m-d', $cutoffDate)
                : false;
            $dateErrors = DateTimeImmutable::getLastErrors();
            if (!$date ||
                ($dateErrors !== false && ($dateErrors['warning_count'] || $dateErrors['error_count'])) ||
                $date->format('Y-m-d') !== $cutoffDate) {
                respond(400, ['error' => 'La date doit respecter le format AAAA-MM-JJ.']);
            }

            $endExclusive = $date->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
            $stmt = $db->prepare('DELETE FROM RequestLog WHERE COALESCE(clientDate, date) < :endExclusive');
            $stmt->execute([':endExclusive' => $endExclusive]);
            respond(200, [
                'message' => 'Les données jusqu’à cette date incluse ont été supprimées.',
                'deletedCount' => $stmt->rowCount(),
                'date' => $cutoffDate,
            ]);
        }

        if ($input['action'] === 'delete-logs') {
            $ids = $input['ids'] ?? null;
            if (!is_array($ids) || count($ids) < 1 || count($ids) > 1000) {
                respond(400, ['error' => 'Sélectionnez entre 1 et 1000 lignes à supprimer.']);
            }
            foreach ($ids as $id) {
                if (!is_int($id) || $id < 1) {
                    respond(400, ['error' => 'La sélection contient un identifiant invalide.']);
                }
            }
            $ids = array_values(array_unique($ids));

            $deletedCount = 0;
            foreach (array_chunk($ids, 500) as $idBatch) {
                $placeholders = implode(',', array_fill(0, count($idBatch), '?'));
                $stmt = $db->prepare("DELETE FROM RequestLog WHERE id IN ($placeholders)");
                $stmt->execute($idBatch);
                $deletedCount += $stmt->rowCount();
            }
            respond(200, [
                'message' => 'Les lignes sélectionnées ont été supprimées.',
                'deletedCount' => $deletedCount,
            ]);
        }

        respond(400, ['error' => 'Action de maintenance inconnue.']);
    } catch (Exception $e) {
        error_log('Analytics data maintenance failed: ' . $e->getMessage());
        respond(500, ['error' => 'Erreur lors de la suppression des données.']);
    }
}

if (isset($_GET['analytics']) && $_GET['analytics'] === 'summary') {
    try {
        $summary = [
            'kingdomEvents' => 0,
            'playAgainEvents' => 0,
            'eventTypes' => [],
            'sources' => [],
            'sets' => [],
            'cards' => [],
            'settings' => [],
            'recentKingdoms' => [],
            'pages' => [],
            'pageLanguages' => [],
            'viewedSets' => [],
            'viewedBoxesSets' => [],
            'interactions' => [],
            'errors' => [],
            'randomizationEvents' => 0,
            'randomizationTypes' => [],
            'randomizationSets' => [],
            'randomizationConfiguration' => [],
            'randomizationAdjustedElements' => [],
            'randomizationSettings' => [],
            'eventTrends' => [],
            'activityUsage' => [
                'kingdomEvents' => 0,
                'distinctGames' => 0,
                'setCounts' => [],
                'cardCounts' => [],
                'trends' => [],
            ],
        ];
        $latestDate = $db->query(
            'SELECT COALESCE(clientDate, date) FROM RequestLog ORDER BY COALESCE(clientDate, date) DESC, id DESC LIMIT 1'
        )->fetchColumn();
        $trendGroupings = ['hour', 'day', 'week', 'month'];
        $trendWindowStarts = [];
        foreach ($trendGroupings as $grouping) {
            $trendWindowStarts[$grouping] = is_string($latestDate) && $latestDate !== ''
                ? analyticsTrendWindowStart($latestDate, $grouping)
                : null;
        }
        $eventTrendCounts = ['events' => [], 'errors' => []];
        $activityTrendCounts = ['week' => [], 'month' => []];
        $eventTypeRows = $db->query(
            'SELECT eventType, COUNT(*) AS count FROM RequestLog GROUP BY eventType ORDER BY count DESC'
        );
        while ($row = $eventTypeRows->fetch(PDO::FETCH_ASSOC)) {
            $summary['eventTypes'][$row['eventType']] = (int)$row['count'];
        }

        $trackedRows = $db->query(
            "SELECT eventType, eventOrError, params, COALESCE(clientDate, date) AS date FROM RequestLog WHERE eventType <> 'KINGDOM_ACTIVITY'"
        );
        $pageEventNames = [
            'RANDOMIZER_VIEW' => 'randomizer',
            'RULEBOOK_VIEW' => 'rulebooks',
            'SEARCH_CARDS_VIEW' => 'searchcards',
            'CARDS_VIEW' => 'cards',
            'SETTINGS_VIEW' => 'settings',
            'HELP_VIEW' => 'help',
            'HISTORY_VIEW' => 'history',
        ];
        while ($row = $trackedRows->fetch(PDO::FETCH_ASSOC)) {
            $eventType = $row['eventType'];
            if (in_array($row['eventOrError'], ['event', 'error'], true)) {
                foreach ($trendGroupings as $grouping) {
                    if ($trendWindowStarts[$grouping] === null) {
                        continue;
                    }
                    $series = $row['eventOrError'] === 'error' ? 'errors' : 'events';
                    incrementAnalyticsTrend(
                        $eventTrendCounts[$series],
                        $row['date'],
                        $grouping,
                        $trendWindowStarts[$grouping],
                        $eventType
                    );
                }
            }
            $data = json_decode($row['params'], true);
            if (!is_array($data)) {
                $data = [];
            }

            if ($row['eventOrError'] === 'error') {
                incrementAnalyticsCount($summary['errors'], $eventType);
            }
            if ($row['eventOrError'] !== 'event') {
                continue;
            }

            if (isset($pageEventNames[$eventType])) {
                incrementAnalyticsCount($summary['pages'], $pageEventNames[$eventType]);
                if ($eventType === 'RULEBOOK_VIEW' && is_string($data['language'] ?? null)) {
                    incrementAnalyticsCount(
                        $summary['pageLanguages'],
                        'rulebooks — ' . $data['language']
                    );
                }
            }

            if ($eventType === 'PAGE_VIEW') {
                if (is_string($data['page'] ?? null) &&
                    !in_array($data['page'], ['boxes', 'sets'], true)) {
                    incrementAnalyticsCount($summary['pages'], $data['page']);
                }
                if (is_string($data['language'] ?? null)) {
                    incrementAnalyticsCount(
                        $summary['pageLanguages'],
                        (is_string($data['page'] ?? null) ? $data['page'] : 'page inconnue') .
                            ' — ' . $data['language']
                    );
                }
                if (($data['page'] ?? null) === 'sets' && is_string($data['setId'] ?? null)) {
                    incrementAnalyticsCount($summary['viewedSets'], $data['setId']);
                }
                if (($data['page'] ?? null) === 'boxes' && is_string($data['setId'] ?? null)) {
                    incrementAnalyticsCount($summary['viewedBoxesSets'], $data['setId']);
                }
            }
            if (in_array($eventType, ['BOX_VIEW', 'SET_VIEW'], true) &&
                is_string($data['setId'] ?? null)) {
                $target = $eventType === 'BOX_VIEW' ? 'viewedBoxesSets' : 'viewedSets';
                incrementAnalyticsCount($summary[$target], $data['setId']);
            }

            if (in_array($eventType, [
                'NEWS_MODAL_OPEN',
                'MARK_KINGDOM_PLAYED',
                'HISTORY_KINGDOM_REPLAY',
            ], true)) {
                incrementAnalyticsCount($summary['interactions'], $eventType);
            }

            $isRandomization = substr($eventType, 0, 9) === 'RANDOMIZE' ||
                $eventType === 'LOAD_INITIAL_RANDOMIZE';
            if (!$isRandomization) {
                continue;
            }

            $summary['randomizationEvents']++;
            incrementAnalyticsCount($summary['randomizationTypes'], $eventType);
            $selectedSets = $data['selectedSets'] ?? [];
            if (is_array($selectedSets)) {
                foreach ($selectedSets as $setId) {
                    incrementAnalyticsCount($summary['randomizationSets'], $setId);
                }
            }
            foreach (['configurationAdjusted', 'configurationSimplified', 'googleDriveConnected'] as $name) {
                if (array_key_exists($name, $data)) {
                    incrementAnalyticsCount($summary['randomizationConfiguration'][$name], $data[$name]);
                }
            }
            $adjustedElements = $data['adjustedElements'] ?? [];
            if (is_array($adjustedElements)) {
                foreach ($adjustedElements as $element) {
                    incrementAnalyticsCount($summary['randomizationAdjustedElements'], $element);
                }
            }
            foreach ([
                'randomizerSettings' => $data['randomizerSettings'] ?? [],
                'applicationSettings' => $data['applicationSettings'] ?? [],
            ] as $group => $settings) {
                if (!is_array($settings)) {
                    continue;
                }
                foreach ($settings as $name => $value) {
                    if (is_array($value) || is_object($value) || $value === null) {
                        continue;
                    }
                    incrementAnalyticsCount(
                        $summary['randomizationSettings'][$group][$name],
                        $value
                    );
                }
            }
        }

        $db->exec('PRAGMA temp_store = FILE');
        $db->exec('CREATE TEMP TABLE AnalyticsDistinctKingdoms (signature TEXT PRIMARY KEY)');
        $distinctKingdomInsert = $db->prepare(
            'INSERT OR IGNORE INTO AnalyticsDistinctKingdoms (signature) VALUES (:signature)'
        );
        $stmt = $db->query(
            "SELECT params, eventOrError, COALESCE(clientDate, date) AS date FROM RequestLog WHERE eventType = 'KINGDOM_ACTIVITY'"
        );

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (in_array($row['eventOrError'], ['event', 'error'], true)) {
                foreach ($trendGroupings as $grouping) {
                    if ($trendWindowStarts[$grouping] === null) {
                        continue;
                    }
                    $series = $row['eventOrError'] === 'error' ? 'errors' : 'events';
                    incrementAnalyticsTrend(
                        $eventTrendCounts[$series],
                        $row['date'],
                        $grouping,
                        $trendWindowStarts[$grouping],
                        'KINGDOM_ACTIVITY'
                    );
                }
            }
            $activity = json_decode($row['params'], true);
            if (!is_array($activity)) {
                continue;
            }
            $summary['kingdomEvents']++;
            $sourceName = is_string($activity['source'] ?? null) ? $activity['source'] : 'unknown';
            $summary['sources'][$sourceName] = ($summary['sources'][$sourceName] ?? 0) + 1;
            if ($sourceName === 'play_again') {
                $summary['playAgainEvents']++;
            }

            $configuration = $activity['configuration'] ?? [];
            foreach (($configuration['selectedSets'] ?? []) as $setId) {
                if (is_string($setId)) {
                    $summary['sets'][$setId] = ($summary['sets'][$setId] ?? 0) + 1;
                }
            }
            foreach (['randomizerSettings', 'appSettings'] as $group) {
                foreach (($configuration[$group] ?? []) as $key => $value) {
                    if (is_array($value) || is_object($value) || $value === null) {
                        continue;
                    }
                    $label = is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;
                    if (!isset($summary['settings'][$group][$key])) {
                        $summary['settings'][$group][$key] = [];
                    }
                    $summary['settings'][$group][$key][$label] =
                        ($summary['settings'][$group][$key][$label] ?? 0) + 1;
                }
            }

            $kingdom = $activity['kingdom'] ?? [];
            if ($row['eventOrError'] === 'event') {
                $activityCards = analyticsKingdomCards($kingdom);
                $activitySets = [];
                foreach ($activityCards as $cardId) {
                    incrementAnalyticsCount($summary['activityUsage']['cardCounts'], $cardId);
                    $separator = strpos($cardId, '_');
                    if ($separator !== false && $separator > 0) {
                        $activitySets[substr($cardId, 0, $separator)] = true;
                    }
                }
                foreach (array_keys($activitySets) as $setId) {
                    incrementAnalyticsCount($summary['activityUsage']['setCounts'], $setId);
                }

                if (is_array($kingdom) && is_string($row['date'])) {
                    try {
                        $activityDate = new DateTimeImmutable($row['date']);
                        $distinctKingdomInsert->execute([
                            ':signature' => analyticsKingdomSignature($kingdom),
                        ]);
                        foreach (['week', 'month'] as $grouping) {
                            if ($trendWindowStarts[$grouping] === null ||
                                $activityDate < $trendWindowStarts[$grouping]) {
                                continue;
                            }
                            foreach (array_keys($activitySets) as $setId) {
                                incrementAnalyticsTrend(
                                    $activityTrendCounts[$grouping]['sets'],
                                    $row['date'],
                                    $grouping,
                                    $trendWindowStarts[$grouping],
                                    $setId
                                );
                            }
                            foreach ($activityCards as $cardId) {
                                incrementAnalyticsTrend(
                                    $activityTrendCounts[$grouping]['cards'],
                                    $row['date'],
                                    $grouping,
                                    $trendWindowStarts[$grouping],
                                    $cardId
                                );
                            }
                        }
                    } catch (Exception $e) {
                        error_log('Analytics activity date parsing failed: ' . $e->getMessage());
                    }
                }
            }

            foreach (['supply', 'events', 'landmarks', 'projects', 'ways', 'boons', 'traits'] as $category) {
                foreach (($kingdom[$category] ?? []) as $cardId) {
                    if (is_string($cardId)) {
                        $summary['cards'][$cardId] = ($summary['cards'][$cardId] ?? 0) + 1;
                    }
                }
            }
            foreach (['ally', 'prophecy'] as $category) {
                $cardId = $kingdom[$category] ?? null;
                if (is_string($cardId)) {
                    $summary['cards'][$cardId] = ($summary['cards'][$cardId] ?? 0) + 1;
                }
            }
            foreach (($kingdom['specialSupply'] ?? []) as $specialCards) {
                $specialCardIds = is_array($specialCards) ? $specialCards : [$specialCards];
                foreach ($specialCardIds as $cardId) {
                    if (is_string($cardId)) {
                        $summary['cards'][$cardId] = ($summary['cards'][$cardId] ?? 0) + 1;
                    }
                }
            }
        }
        $summary['activityUsage']['kingdomEvents'] = (int)$db->query(
            "SELECT COUNT(*) FROM RequestLog WHERE eventType = 'KINGDOM_ACTIVITY' AND eventOrError = 'event'"
        )->fetchColumn();
        $summary['activityUsage']['distinctGames'] = (int)$db->query(
            'SELECT COUNT(*) FROM AnalyticsDistinctKingdoms'
        )->fetchColumn();
        $db->exec('DROP TABLE AnalyticsDistinctKingdoms');
        foreach (['week', 'month'] as $grouping) {
            $summary['activityUsage']['trends'][$grouping] = [
                'setBuckets' => buildAnalyticsTrendBuckets(
                    $activityTrendCounts[$grouping]['sets'] ?? [],
                    $grouping,
                    $latestDate
                ),
                'cardBuckets' => buildAnalyticsTrendBuckets(
                    $activityTrendCounts[$grouping]['cards'] ?? [],
                    $grouping,
                    $latestDate
                ),
            ];
        }
        foreach ($trendGroupings as $grouping) {
            $summary['eventTrends'][$grouping] = [
                'events' => buildAnalyticsTrendBuckets(
                    $eventTrendCounts['events'],
                    $grouping,
                    $latestDate
                ),
                'errors' => buildAnalyticsTrendBuckets(
                    $eventTrendCounts['errors'],
                    $grouping,
                    $latestDate
                ),
            ];
        }

        $activityEventCount = $summary['kingdomEvents'];
        $updateEventCount = (int)$db->query(
            "SELECT COUNT(*) FROM RequestLog WHERE eventType = 'UPDATE_KINGDOM'"
        )->fetchColumn();
        if ($updateEventCount > 0) {
            $summary['kingdomEvents'] = max($activityEventCount, $updateEventCount);
            if ($updateEventCount >= $activityEventCount) {
                $summary['cards'] = [];
                if ($updateEventCount > $activityEventCount) {
                    $summary['sources']['Origine inconnue'] =
                        ($summary['sources']['Origine inconnue'] ?? 0) +
                        $updateEventCount - $activityEventCount;
                }

                $kingdomRows = $db->query(
                    "SELECT params FROM RequestLog WHERE eventType = 'UPDATE_KINGDOM'"
                );
                while ($row = $kingdomRows->fetch(PDO::FETCH_ASSOC)) {
                    $kingdom = decodeLegacyKingdom(json_decode($row['params'], true));
                    if ($kingdom === null) {
                        continue;
                    }
                    foreach ([
                        'supply', 'events', 'landmarks', 'projects', 'ways',
                        'boons', 'traits', 'ally', 'prophecy',
                    ] as $category) {
                        foreach ($kingdom[$category] as $cardId) {
                            incrementAnalyticsCount($summary['cards'], $cardId);
                        }
                    }
                    foreach ($kingdom['specialSupply'] as $specialCards) {
                        foreach ($specialCards as $cardId) {
                            incrementAnalyticsCount($summary['cards'], $cardId);
                        }
                    }
                }
            }
        }

        arsort($summary['sets']);
        arsort($summary['cards']);
        arsort($summary['pages']);
        arsort($summary['pageLanguages']);
        arsort($summary['viewedSets']);
        arsort($summary['interactions']);
        arsort($summary['errors']);
        arsort($summary['randomizationTypes']);
        arsort($summary['randomizationSets']);
        arsort($summary['randomizationAdjustedElements']);
        $summary['topSets'] = array_slice($summary['sets'], 0, 45, true);
        $summary['topCards'] = array_slice($summary['cards'], 0, 500, true);

        $recent = $db->query(
            "SELECT COALESCE(clientDate, date) AS date, date AS receivedAt, params, sessionId FROM RequestLog WHERE eventType = 'KINGDOM_ACTIVITY' ORDER BY COALESCE(clientDate, date) DESC, id DESC LIMIT 50"
        );
        while ($row = $recent->fetch(PDO::FETCH_ASSOC)) {
            $activity = json_decode($row['params'], true);
            if (is_array($activity)) {
                $summary['recentKingdoms'][] = [
                    'date' => $row['date'],
                    'sessionId' => $row['sessionId'],
                    'source' => $activity['source'] ?? 'unknown',
                    'kingdom' => $activity['kingdom'] ?? [],
                    'configuration' => $activity['configuration'] ?? [],
                ];
            }
        }
        if (!$summary['recentKingdoms']) {
            $recentUpdates = $db->query(
                "SELECT COALESCE(clientDate, date) AS date, date AS receivedAt, params, sessionId FROM RequestLog WHERE eventType = 'UPDATE_KINGDOM' ORDER BY COALESCE(clientDate, date) DESC, id DESC LIMIT 50"
            );
            while ($row = $recentUpdates->fetch(PDO::FETCH_ASSOC)) {
                $kingdom = decodeLegacyKingdom(json_decode($row['params'], true));
                if ($kingdom !== null) {
                    $summary['recentKingdoms'][] = [
                        'date' => $row['date'],
                        'sessionId' => $row['sessionId'],
                        'source' => 'unknown',
                        'kingdom' => $kingdom,
                        'configuration' => [],
                    ];
                }
            }
        }
        $summary['traffic'] = analyticsTrafficSummary($db, false);
        respond(200, $summary);
    } catch (Exception $e) {
        respond(500, ['error' => 'Erreur lors de la préparation des statistiques.']);
    }
}

if (isset($_GET['logs']) && $_GET['logs'] === 'true') {
    $category = $_GET['category'] ?? '';
    if (!is_string($category) || !in_array($category, ['', 'event', 'error'], true)) {
        respond(400, ['error' => 'Le filtre Catégorie est invalide.']);
    }
    $trafficCategory = $_GET['trafficCategory'] ?? '';
    $trafficCategoryLabels = [
        'human' => 'Trafic Humain Légitime',
        'robot-no-session' => 'Trafic Robot / Suspect (sans Session ID)',
        'robot-browser' => 'Trafic Robot / Suspect (navigateur atypique)',
        'error' => 'Trafic en erreur',
    ];
    if (!is_string($trafficCategory)) {
        respond(400, ['error' => 'Le filtre Catégorie de trafic est invalide.']);
    }
    $legacyTrafficCategories = array_flip($trafficCategoryLabels);
    if (isset($legacyTrafficCategories[$trafficCategory])) {
        $trafficCategory = $legacyTrafficCategories[$trafficCategory];
    }
    if ($trafficCategory !== '' && !isset($trafficCategoryLabels[$trafficCategory])) {
        respond(400, ['error' => 'Le filtre Catégorie de trafic est invalide.']);
    }
    $eventType = $_GET['eventType'] ?? '';
    if (!is_string($eventType) || strlen($eventType) > 80) {
        respond(400, ['error' => 'Le filtre EventType est invalide.']);
    }
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = $_GET['perPage'] ?? '25';
    if (!is_string($perPage) || !in_array($perPage, ['10','25', '50', '100', '250', '500', '1000'], true)) {
        respond(400, ['error' => 'Le nombre de lignes par page est invalide.']);
    }
    $perPage = (int)$perPage;
    $sessionId = $_GET['sessionId'] ?? '';
    if (!is_string($sessionId) || strlen($sessionId) > 64 ||
        ($sessionId !== '' && !preg_match('/^[A-Fa-f0-9*?-]+$/', $sessionId))) {
        respond(400, ['error' => 'L’identifiant de session est invalide.']);
    }
    $paramsSearch = $_GET['paramsSearch'] ?? '';
    if (!is_string($paramsSearch) || strlen($paramsSearch) > 500) {
        respond(400, ['error' => 'Le filtre sur les paramètres est invalide.']);
    }
    $filters = [];
    $params = [];
    if ($category !== '') {
        $filters[] = 'eventOrError = :category';
        $params[':category'] = $category;
    }
    if ($trafficCategory !== '') {
        $filters[] = '(' . analyticsTrafficCategoryExpression() . ') = :trafficCategory';
        $params[':trafficCategory'] = $trafficCategoryLabels[$trafficCategory];
    }
    if ($eventType !== '') {
        $filters[] = 'eventType = :eventType';
        $params[':eventType'] = $eventType;
    }
    if ($sessionId !== '') {
        $filters[] = 'sessionId LIKE :sessionId';
        $params[':sessionId'] = '%' . strtr($sessionId, ['*' => '%', '?' => '_']) . '%';
    }
    if ($paramsSearch !== '') {
        $filters[] = "instr(COALESCE(params, ''), :paramsSearch) > 0";
        $params[':paramsSearch'] = $paramsSearch;
    }

    $country = $_GET['country'] ?? '';
    if (!is_string($country) ||
        ($country !== '' && !preg_match('/^(?:[A-Za-z]{2}|unknown)$/i', $country))) {
        respond(400, ['error' => 'Le filtre pays est invalide.']);
    }
    if (strcasecmp($country, 'unknown') === 0) {
        $country = 'unknown';
    } elseif ($country !== '') {
        $country = strtoupper($country);
    }
    if ($country !== '') {
        $filters[] = 'country = :country';
        $params[':country'] = $country;
    }

    $device = $_GET['device'] ?? '';
    if (!is_string($device) || strlen($device) > 80) {
        respond(400, ['error' => 'Le filtre appareil est invalide.']);
    }
    if ($device !== '') {
        $filters[] = 'device = :device';
        $params[':device'] = $device;
    }

    $browser = $_GET['browser'] ?? '';
    if (!is_string($browser) || strlen($browser) > 80) {
        respond(400, ['error' => 'Le filtre navigateur est invalide.']);
    }
    if ($browser !== '') {
        $filters[] = 'browser = :browser';
        $params[':browser'] = $browser;
    }

    $startDate = $_GET['startDate'] ?? '';
    $endDate = $_GET['endDate'] ?? '';
    if (!is_string($startDate) || !is_string($endDate)) {
        respond(400, ['error' => 'Les filtres date/heure sont invalides.']);
    }
    $startDate = parseAnalyticsDateTimeFilter($startDate, 'de début');
    $endDate = parseAnalyticsDateTimeFilter($endDate, 'de fin');
    if ($startDate !== null && $endDate !== null && $startDate > $endDate) {
        respond(400, ['error' => 'La date/heure de début doit précéder la date/heure de fin.']);
    }
    if ($startDate !== null) {
        $filters[] = 'COALESCE(clientDate, date) >= :startDate';
        $params[':startDate'] = $startDate->format('Y-m-d H:i:s.u');
    }
    if ($endDate !== null) {
        $filters[] = 'COALESCE(clientDate, date) < :endDate';
        $params[':endDate'] = $endDate->modify('+1 minute')->format('Y-m-d H:i:s.u');
    }
    $where = $filters ? ' WHERE ' . implode(' AND ', $filters) : '';

    try {
        $serverTimings = [
            'php_prepare' => max(
                0,
                (microtime(true) - $requestStartedAt) * 1000 - $databaseSetupDurationMs
            ),
            'db_init' => $databaseSetupDurationMs,
            'metadata_migration' => $metadataMigrationDurationMs,
        ];

        $queryStartedAt = microtime(true);
        $eventTypes = $db->query(
            'SELECT DISTINCT eventType FROM RequestLog ORDER BY eventType'
        )->fetchAll(PDO::FETCH_COLUMN);
        $serverTimings['event_types'] = (microtime(true) - $queryStartedAt) * 1000;

        $queryStartedAt = microtime(true);
        $countries = $db->query(
            "SELECT DISTINCT country FROM RequestLog WHERE country IS NOT NULL AND country <> '' ORDER BY country"
        )->fetchAll(PDO::FETCH_COLUMN);
        $serverTimings['countries'] = (microtime(true) - $queryStartedAt) * 1000;

        $queryStartedAt = microtime(true);
        $devices = $db->query(
            "SELECT DISTINCT device FROM RequestLog WHERE device IS NOT NULL AND device <> '' ORDER BY device"
        )->fetchAll(PDO::FETCH_COLUMN);
        $serverTimings['devices'] = (microtime(true) - $queryStartedAt) * 1000;

        $queryStartedAt = microtime(true);
        $browsers = $db->query(
            "SELECT DISTINCT browser FROM RequestLog WHERE browser IS NOT NULL AND browser <> '' ORDER BY browser"
        )->fetchAll(PDO::FETCH_COLUMN);
        $serverTimings['browsers'] = (microtime(true) - $queryStartedAt) * 1000;

        $queryStartedAt = microtime(true);
        $countStmt = $db->prepare('SELECT COUNT(*) FROM RequestLog' . $where);
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();
        $totalCount = (int)$countStmt->fetchColumn();
        $page = min($page, max(1, (int)ceil($totalCount / $perPage)));
        $serverTimings['count'] = (microtime(true) - $queryStartedAt) * 1000;

        $queryStartedAt = microtime(true);
        $stmt = $db->prepare(
            'SELECT id, COALESCE(clientDate, date) AS date, date AS receivedAt, eventOrError, eventType, params, sessionId, clientContext, country, device, browser FROM RequestLog' . $where .
            ' ORDER BY COALESCE(clientDate, date) DESC, id DESC LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();
        $pageLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $serverTimings['page_query'] = (microtime(true) - $queryStartedAt) * 1000;
        $serverTimings['php_before_response'] = (microtime(true) - $requestStartedAt) * 1000;
        respond(200, [
            'logs' => $pageLogs,
            'eventTypes' => $eventTypes,
            'countries' => $countries,
            'devices' => $devices,
            'browsers' => $browsers,
            'page' => $page,
            'perPage' => $perPage,
            'totalCount' => $totalCount,
        ], $serverTimings);
    } catch (Exception $e) {
        error_log('Analytics logs retrieval failed: ' . $e->getMessage());
        if (isset($serverTimings)) {
            $serverTimings['php_before_response'] = (microtime(true) - $requestStartedAt) * 1000;
        }
        respond(500, ['error' => 'Erreur lors de la récupération des journaux.'], $serverTimings ?? []);
    }
}

$startDate = $_GET['startDate'] ?? null;
$endDate = $_GET['endDate'] ?? null;
$search = $_GET['search'] ?? null;
$eventOrError = $_GET['type'] ?? null;
$eventType = $_GET['eventType'] ?? null;
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$where = [];
$params = [];

if ($startDate) {
    $where[] = 'COALESCE(clientDate, date) >= :startDate';
    $params[':startDate'] = str_replace('T', ' ', $startDate);
}
if ($endDate) {
    $where[] = 'COALESCE(clientDate, date) <= :endDate';
    $params[':endDate'] = str_replace('T', ' ', $endDate);
}
if ($search) {
    $where[] = 'params LIKE :search';
    $params[':search'] = '%' . $search . '%';
}
if ($eventOrError) {
    $where[] = 'eventOrError = :eventOrError';
    $params[':eventOrError'] = $eventOrError;
}
if ($eventType) {
    $where[] = 'eventType = :eventType';
    $params[':eventType'] = $eventType;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

try {
    $countStmt = $db->prepare('SELECT COUNT(*) FROM RequestLog' . $whereSql);
    $countStmt->execute($params);
    $totalCount = (int)$countStmt->fetchColumn();

    if (isset($_GET['download']) && $_GET['download'] === 'csv') {
        $stmt = $db->prepare(
            'SELECT COALESCE(clientDate, date) AS date, date AS receivedAt, eventOrError, eventType, params, sessionId, clientContext FROM RequestLog' . $whereSql .
            ' ORDER BY COALESCE(clientDate, date) DESC, id DESC'
        );
        $stmt->execute($params);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="logs.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Date client', 'Date réception serveur', 'Type', 'Event', 'Session ID', 'Paramètres', 'Contexte client']);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [
                $row['date'],
                $row['receivedAt'],
                $row['eventOrError'],
                $row['eventType'],
                $row['sessionId'],
                $row['params'],
                $row['clientContext'],
            ]);
        }
        fclose($output);
        exit;
    }

    $stmt = $db->prepare(
        'SELECT COALESCE(clientDate, date) AS date, date AS receivedAt, eventOrError, eventType, params, sessionId, clientContext FROM RequestLog' . $whereSql .
        ' ORDER BY COALESCE(clientDate, date) DESC, id DESC LIMIT :limit OFFSET :offset'
    );
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
    $stmt->execute();
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) {
        respond(200, ['logs' => $logs, 'page' => $page, 'perPage' => $perPage, 'totalCount' => $totalCount]);
    }

    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="fr"><meta charset="utf-8"><meta name="viewport" content="width=device-width">';
    echo '<title>Logs de suivi</title><style>body{font:16px Arial;margin:2rem;color:#222}form{display:flex;gap:.7rem;flex-wrap:wrap}table{width:100%;border-collapse:collapse;margin-top:1rem}th,td{border:1px solid #ddd;padding:.6rem;text-align:left;vertical-align:top}pre{white-space:pre-wrap;overflow-wrap:anywhere;margin:0}</style>';
    echo '<h1>Logs de suivi</h1><form method="get"><input type="hidden" name="source" value="' . htmlspecialchars($source, ENT_QUOTES, 'UTF-8') . '">';
    foreach (['startDate' => 'Date de début', 'endDate' => 'Date de fin', 'search' => 'Recherche', 'type' => 'Type', 'eventType' => 'EventType'] as $name => $label) {
        $value = htmlspecialchars((string)($_GET[$name] ?? ''), ENT_QUOTES, 'UTF-8');
        echo '<label>' . $label . ' <input name="' . $name . '" value="' . $value . '"></label>';
    }
    echo '<button>Filtrer</button><button name="download" value="csv">CSV</button></form><table><thead><tr><th>Date</th><th>Type</th><th>Événement</th><th>Session ID</th><th>Pays</th><th>Appareil</th><th>Paramètres</th></tr></thead><tbody>';
    foreach ($logs as $log) {
        $context = json_decode($log['clientContext'] ?? '', true);
        $context = is_array($context) ? $context : [];
        echo '<tr><td>' . htmlspecialchars($log['date'], ENT_QUOTES, 'UTF-8') . '</td><td>' .
            htmlspecialchars($log['eventOrError'], ENT_QUOTES, 'UTF-8') . '</td><td>' .
            htmlspecialchars($log['eventType'], ENT_QUOTES, 'UTF-8') . '</td><td>' .
            htmlspecialchars($log['sessionId'] ?? '', ENT_QUOTES, 'UTF-8') . '</td><td>' .
            htmlspecialchars($context['country'] ?? '—', ENT_QUOTES, 'UTF-8') . '</td><td>' .
            htmlspecialchars($context['device'] ?? '—', ENT_QUOTES, 'UTF-8') . '</td><td><pre>' .
            htmlspecialchars($log['params'], ENT_QUOTES, 'UTF-8') . '</pre></td></tr>';
    }
    echo '</tbody></table><p>Page ' . $page . ' sur ' . max(1, (int)ceil($totalCount / $perPage)) . '</p></html>';
} catch (Exception $e) {
    respond(500, ['error' => 'Erreur lors de la récupération des journaux.']);
}
