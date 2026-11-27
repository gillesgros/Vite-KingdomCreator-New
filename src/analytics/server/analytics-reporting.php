<?php

function incrementAnalyticsCount(&$counts, $label) {
    if (is_bool($label)) {
        $label = $label ? 'Oui' : 'Non';
    } elseif (is_int($label) || is_float($label)) {
        $label = (string)$label;
    }

    if (!is_string($label) || $label === '') {
        return;
    }
    $counts[$label] = ($counts[$label] ?? 0) + 1;
}

function analyticsBucket($date, $grouping) {
    $value = new DateTimeImmutable($date);
    if ($grouping === 'hour') {
        $start = $value->setTime((int)$value->format('H'), 0, 0);
        $label = $start->format('d/m H') . 'h';
    } elseif ($grouping === 'day') {
        $start = $value->setTime(0, 0, 0);
        $label = $start->format('d/m');
    } elseif ($grouping === 'week') {
        $start = $value->modify('monday this week')->setTime(0, 0, 0);
        $label = 'Sem. ' . $start->format('d/m');
    } else {
        $start = $value->setDate((int)$value->format('Y'), (int)$value->format('m'), 1)
            ->setTime(0, 0, 0);
        $label = $start->format('m/Y');
    }

    return [
        'key' => $start->format(
            $grouping === 'hour'
                ? 'Y-m-d\TH'
                : ($grouping === 'month' ? 'Y-m' : 'Y-m-d')
        ),
        'label' => $label,
        'start' => $start,
    ];
}

function analyticsTrendWindowStart($latestDate, $grouping) {
    $bucket = analyticsBucket($latestDate, $grouping);
    $interval = [
        'hour' => '-23 hours',
        'day' => '-29 days',
        'week' => '-11 weeks',
        'month' => '-11 months',
    ][$grouping];

    return $bucket['start']->modify($interval);
}

function incrementAnalyticsTrend(&$counts, $date, $grouping, $windowStart, $label) {
    try {
        $bucket = analyticsBucket($date, $grouping);
    } catch (Exception $e) {
        return;
    }
    if ($bucket['start'] < $windowStart) {
        return;
    }
    if (!isset($counts[$grouping][$bucket['key']])) {
        $counts[$grouping][$bucket['key']] = [];
    }
    incrementAnalyticsCount($counts[$grouping][$bucket['key']], $label);
}

function buildAnalyticsTrendBuckets($counts, $grouping, $latestDate) {
    if (!is_string($latestDate) || $latestDate === '') {
        return [];
    }

    $latest = analyticsBucket($latestDate, $grouping);
    $current = analyticsTrendWindowStart($latestDate, $grouping);
    $interval = [
        'hour' => '+1 hour',
        'day' => '+1 day',
        'week' => '+1 week',
        'month' => '+1 month',
    ][$grouping];
    $limit = ['hour' => 24, 'day' => 30, 'week' => 12, 'month' => 12][$grouping];
    $buckets = [];
    while ($current <= $latest['start'] && count($buckets) < $limit) {
        $bucket = analyticsBucket($current->format('Y-m-d H:i:s'), $grouping);
        $buckets[] = [
            'key' => $bucket['key'],
            'label' => $bucket['label'],
            'counts' => $counts[$grouping][$bucket['key']] ?? [],
        ];
        $current = $current->modify($interval);
    }

    return $buckets;
}

function analyticsKingdomCards($kingdom) {
    if (!is_array($kingdom)) {
        return [];
    }

    $cards = [];
    foreach (['supply', 'events', 'landmarks', 'projects', 'ways', 'boons', 'traits', 'ally', 'prophecy', 'specialSupply'] as $category) {
        $value = $kingdom[$category] ?? null;
        if (is_array($value)) {
            $values = array_is_list($value)
                ? $value
                : array_merge(...array_map(
                    static function ($nested) {
                        return is_array($nested) ? array_values($nested) : [$nested];
                    },
                    array_values($value)
                ));
            foreach ($values as $cardId) {
                if (is_string($cardId) && $cardId !== '') {
                    $cards[] = $cardId;
                }
            }
        } elseif (is_string($value) && $value !== '') {
            $cards[] = $value;
        }
    }

    return $cards;
}

function analyticsKingdomSignature($kingdom) {
    $normalized = [];
    foreach (['supply', 'events', 'landmarks', 'projects', 'ways', 'boons', 'traits', 'ally', 'prophecy', 'specialSupply'] as $category) {
        $value = $kingdom[$category] ?? null;
        if (is_array($value) && !array_is_list($value)) {
            $values = [];
            foreach ($value as $key => $nested) {
                $nestedValues = is_array($nested) ? $nested : [$nested];
                foreach ($nestedValues as $item) {
                    if (is_string($item)) {
                        $values[] = (string)$key . ':' . $item;
                    }
                }
            }
        } elseif (is_array($value)) {
            $values = array_values(array_filter($value, 'is_string'));
        } elseif (is_string($value)) {
            $values = [$value];
        } else {
            $values = [];
        }
        sort($values, SORT_STRING);
        $normalized[$category] = $values;
    }

    return hash('sha256', json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function analyticsTrafficCategoryExpression() {
    $context = "CASE WHEN json_valid(clientContext) THEN clientContext ELSE '{}' END";
    $browser = "COALESCE(browser, json_extract($context, '$.browser'), '')";
    return "CASE " .
        "WHEN eventOrError = 'error' THEN 'Trafic en erreur' " .
        "WHEN COALESCE(sessionId, '') = '' THEN 'Trafic Robot / Suspect (sans Session ID)' " .
        "WHEN $browser = '' OR LOWER($browser) LIKE '%bot%' OR LOWER($browser) LIKE '%crawl%' " .
        "OR LOWER($browser) LIKE '%spider%' OR LOWER($browser) LIKE '%curl%' " .
        "OR LOWER($browser) LIKE '%python%' OR LOWER($browser) LIKE '%wget%' " .
        "THEN 'Trafic Robot / Suspect (navigateur atypique)' " .
        "ELSE 'Trafic Humain Légitime' END";
}

function analyticsTrafficLocationsPage(PDO $db, $page, $snapshotId, $cursor, $perPage) {
    $totalCount = null;
    if ($snapshotId === null) {
        $snapshotId = (int)$db->query('SELECT COALESCE(MAX(id), 0) FROM RequestLog')->fetchColumn();
        $countStmt = $db->prepare('SELECT COUNT(*) FROM RequestLog WHERE id <= :snapshotId');
        $countStmt->bindValue(':snapshotId', $snapshotId, PDO::PARAM_INT);
        $countStmt->execute();
        $totalCount = (int)$countStmt->fetchColumn();
    }

    $context = "CASE WHEN json_valid(clientContext) THEN clientContext ELSE '{}' END";
    $country = "COALESCE(NULLIF(country, ''), NULLIF(json_extract($context, '$.country'), ''), 'unknown')";
    $subdivision = "CASE WHEN json_type($context, '$.subdivision') = 'text' THEN json_extract($context, '$.subdivision') ELSE '' END";
    $trafficCategory = analyticsTrafficCategoryExpression();
    $city = "CASE WHEN json_type($context, '$.city') = 'text' THEN json_extract($context, '$.city') ELSE '' END";
    $latitude = "CASE WHEN typeof(COALESCE(json_extract($context, '$.latitude'), json_extract($context, '$.lat'))) IN ('integer', 'real') " .
        "THEN CAST(COALESCE(json_extract($context, '$.latitude'), json_extract($context, '$.lat')) AS REAL) END";
    $longitude = "CASE WHEN typeof(COALESCE(json_extract($context, '$.longitude'), json_extract($context, '$.lng'), json_extract($context, '$.lon'))) IN ('integer', 'real') " .
        "THEN CAST(COALESCE(json_extract($context, '$.longitude'), json_extract($context, '$.lng'), json_extract($context, '$.lon')) AS REAL) END";
    $where = 'id <= :snapshotId';
    if ($cursor !== null) {
        $where .= ' AND id < :cursor';
    }
    $stmt = $db->prepare(
        "WITH page_rows AS (" .
        "SELECT id, eventOrError, sessionId, clientContext, browser, country FROM RequestLog " .
        "WHERE $where ORDER BY id DESC LIMIT :limit" .
        "), page_stats AS (SELECT COUNT(*) AS rowCount FROM page_rows) " .
        "SELECT $trafficCategory AS category, $country AS country, $subdivision AS subdivision, " .
        "$city AS city, $latitude AS latitude, $longitude AS longitude, COUNT(*) AS count, " .
        "MIN(id) AS oldestId, page_stats.rowCount AS rowCount " .
        "FROM page_rows CROSS JOIN page_stats " .
        "GROUP BY category, country, subdivision, city, latitude, longitude, page_stats.rowCount " .
        "ORDER BY oldestId DESC"
    );
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':snapshotId', $snapshotId, PDO::PARAM_INT);
    if ($cursor !== null) {
        $stmt->bindValue(':cursor', $cursor, PDO::PARAM_INT);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $locations = [];
    $nextCursor = null;
    $pageEventCount = isset($rows[0]['rowCount']) ? (int)$rows[0]['rowCount'] : 0;
    foreach ($rows as $row) {
        $locations[] = [
            'category' => $row['category'],
            'country' => $row['country'],
            'subdivision' => $row['subdivision'],
            'city' => $row['city'],
            'latitude' => $row['latitude'] === null ? null : (float)$row['latitude'],
            'longitude' => $row['longitude'] === null ? null : (float)$row['longitude'],
            'count' => (int)$row['count'],
        ];
        $oldestId = (int)$row['oldestId'];
        $nextCursor = $nextCursor === null ? $oldestId : min($nextCursor, $oldestId);
    }
    $hasMore = false;
    if ($nextCursor !== null) {
        $moreStmt = $db->prepare(
            'SELECT EXISTS(SELECT 1 FROM RequestLog WHERE id <= :snapshotId AND id < :cursor)'
        );
        $moreStmt->bindValue(':snapshotId', $snapshotId, PDO::PARAM_INT);
        $moreStmt->bindValue(':cursor', $nextCursor, PDO::PARAM_INT);
        $moreStmt->execute();
        $hasMore = (bool)$moreStmt->fetchColumn();
    }

    return [
        'locations' => $locations,
        'page' => $page,
        'perPage' => $perPage,
        'pageEventCount' => $pageEventCount,
        'hasMore' => $hasMore,
        'snapshotId' => $snapshotId,
        'nextCursor' => $nextCursor,
        'totalCount' => $totalCount,
    ];
}

function analyticsTrafficTopLocations(PDO $db) {
    $context = "CASE WHEN json_valid(clientContext) THEN clientContext ELSE '{}' END";
    $country = "COALESCE(NULLIF(country, ''), NULLIF(json_extract($context, '$.country'), ''), 'unknown')";
    $subdivision = "CASE WHEN json_type($context, '$.subdivision') = 'text' THEN json_extract($context, '$.subdivision') ELSE '' END";
    $trafficCategory = analyticsTrafficCategoryExpression();
    $locationQuery = $db->query(
        "SELECT category, country, subdivision, city, latitude, longitude, COUNT(*) AS count FROM (" .
        "SELECT $trafficCategory AS category, $country AS country, $subdivision AS subdivision, " .
        "CASE WHEN json_type($context, '$.city') = 'text' THEN json_extract($context, '$.city') ELSE '' END AS city, " .
        "CASE WHEN typeof(COALESCE(json_extract($context, '$.latitude'), json_extract($context, '$.lat'))) IN ('integer', 'real') " .
        "THEN CAST(COALESCE(json_extract($context, '$.latitude'), json_extract($context, '$.lat')) AS REAL) END AS latitude, " .
        "CASE WHEN typeof(COALESCE(json_extract($context, '$.longitude'), json_extract($context, '$.lng'), json_extract($context, '$.lon'))) IN ('integer', 'real') " .
        "THEN CAST(COALESCE(json_extract($context, '$.longitude'), json_extract($context, '$.lng'), json_extract($context, '$.lon')) AS REAL) END AS longitude " .
        "FROM RequestLog) GROUP BY category, country, subdivision, city, latitude, longitude " .
        "ORDER BY count DESC LIMIT 2000"
    );
    $locations = [];
    while ($row = $locationQuery->fetch(PDO::FETCH_ASSOC)) {
        $locations[] = [
            'category' => $row['category'],
            'country' => $row['country'],
            'subdivision' => $row['subdivision'],
            'city' => $row['city'],
            'latitude' => $row['latitude'] === null ? null : (float)$row['latitude'],
            'longitude' => $row['longitude'] === null ? null : (float)$row['longitude'],
            'count' => (int)$row['count'],
        ];
    }

    return $locations;
}

function analyticsTrafficSummary(PDO $db, $includeLocations = true) {
    $context = "CASE WHEN json_valid(clientContext) THEN clientContext ELSE '{}' END";
    $country = "COALESCE(NULLIF(country, ''), NULLIF(json_extract($context, '$.country'), ''), 'unknown')";
    $subdivision = "CASE WHEN json_type($context, '$.subdivision') = 'text' THEN json_extract($context, '$.subdivision') ELSE '' END";
    $trafficCategory = analyticsTrafficCategoryExpression();
    $summary = [
        'totals' => [
            'totalCount' => 0,
            'humanCount' => 0,
            'robotCount' => 0,
            'errorCount' => 0,
            'distinctSessionCount' => (int)$db->query(
                "SELECT COUNT(DISTINCT NULLIF(TRIM(sessionId), '')) FROM RequestLog"
            )->fetchColumn(),
        ],
        'entries' => [],
    ];
    if ($includeLocations) {
        $summary['locations'] = analyticsTrafficTopLocations($db);
    }
    $entryQuery = $db->query(
        "SELECT $trafficCategory AS category, $country AS country, $subdivision AS subdivision, COUNT(*) AS count " .
        "FROM RequestLog GROUP BY category, country, subdivision"
    );
    while ($row = $entryQuery->fetch(PDO::FETCH_ASSOC)) {
        $count = (int)$row['count'];
        $summary['entries'][] = [
            'category' => $row['category'],
            'country' => $row['country'],
            'subdivision' => $row['subdivision'],
            'count' => $count,
        ];
        $summary['totals']['totalCount'] += $count;
        if ($row['category'] === 'Trafic Humain Légitime') {
            $summary['totals']['humanCount'] += $count;
        } elseif ($row['category'] === 'Trafic en erreur') {
            $summary['totals']['errorCount'] += $count;
        } else {
            $summary['totals']['robotCount'] += $count;
        }
    }

    return $summary;
}
