import type { AnalyticsLog, AnalyticsCountMap } from '../types/analytics';

export type TimeGrouping = 'hour' | 'day' | 'week' | 'month';

export const EVENT_CATEGORY_LABELS = {
  access: 'Accès',
  actions: 'Actions',
  kingdom: 'Suivi du royaume',
  other: 'Autres',
} as const;

export type AnalyticsEventCategory = keyof typeof EVENT_CATEGORY_LABELS;

export interface TimeBucket {
  key: string;
  label: string;
  counts: AnalyticsCountMap;
}

export interface UsageTrends {
  kingdomEvents: number;
  distinctGames: number;
  setCounts: AnalyticsCountMap;
  cardCounts: AnalyticsCountMap;
  setBuckets: TimeBucket[];
  cardBuckets: TimeBucket[];
}

interface ActivityData {
  kingdom: Record<string, unknown> | null;
}

const cardCategories = [
  'supply',
  'events',
  'landmarks',
  'projects',
  'ways',
  'boons',
  'traits',
  'ally',
  'prophecy',
  'specialSupply',
] as const;

const accessEventTypes = new Set([
  'PAGE_VIEW',
  'RANDOMIZER_VIEW',
  'BOX_VIEW',
  'SET_VIEW',
  'RULEBOOK_VIEW',
  'SEARCH_CARDS_VIEW',
  'CARDS_VIEW',
  'SETTINGS_VIEW',
  'HELP_VIEW',
  'HISTORY_VIEW',
]);

const actionEventTypes = new Set([
  'LOAD_INITIAL_KINGDOM',
  'LOAD_FULL_KINGDOM_FROM_URL',
  'LOAD_PARTIAL_KINGDOM_FROM_URL',
  'RANDOMIZE_EVENTS_AND_LANDMARKS',
  'RANDOMIZE_ALLY',
  'RANDOMIZE_PROPHECY',
  'RANDOMIZE_BOONS',
  'RANDOMIZE_KINGDOM',
  'RANDOMIZE_MULTIPLE_SUPPLY',
  'RANDOMIZE_CREATE_SUPPLY',
  'RANDOMIZE_SINGLE',
  'RANDOMIZE_FULL_KINGDOM',
  'LOAD_INITIAL_RANDOMIZE',
  'RANDOMIZE',
  'NEWS_MODAL_OPEN',
  'MARK_KINGDOM_PLAYED',
  'HISTORY_KINGDOM_REPLAY',
]);

export function categorizeEventType(eventType: string): AnalyticsEventCategory | null {
  if (eventType === 'UPDATE_KINGDOM') return null;
  if (eventType === 'KINGDOM_ACTIVITY') return 'kingdom';
  if (accessEventTypes.has(eventType)) return 'access';
  if (actionEventTypes.has(eventType)) return 'actions';
  return 'other';
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function parseParams(raw: string | null): Record<string, unknown> | null {
  if (!raw) return null;
  try {
    const parsed: unknown = JSON.parse(raw);
    return isRecord(parsed) ? parsed : null;
  } catch {
    return null;
  }
}

function readKingdom(log: AnalyticsLog, params: Record<string, unknown>): Record<string, unknown> | null {
  if (log.eventType === 'KINGDOM_ACTIVITY') {
    return isRecord(params.kingdom) ? params.kingdom : null;
  }

  const legacyKingdom = isRecord(params.kingdom) ? params.kingdom : params;
  const kingdom: Record<string, unknown> = {};
  for (const category of cardCategories) {
    const raw = legacyKingdom[category];
    if (typeof raw === 'string') {
      kingdom[category] = raw.split(',').filter(Boolean);
    } else if (Array.isArray(raw)) {
      kingdom[category] = raw;
    } else if (typeof raw === 'string' && category !== 'specialSupply') {
      kingdom[category] = [raw];
    }
  }
  const specialSupply: Record<string, string[]> = {};
  for (const category of ['bane', 'ferryman', 'riverboat']) {
    const raw = legacyKingdom[category];
    if (typeof raw === 'string' && raw !== '') {
      specialSupply[category] = raw.split(',').filter(Boolean);
    } else if (Array.isArray(raw)) {
      specialSupply[category] = raw.filter((item): item is string => typeof item === 'string');
    }
  }
  if (Object.keys(specialSupply).length > 0) kingdom.specialSupply = specialSupply;
  for (const category of ['ally', 'prophecy'] as const) {
    const raw = legacyKingdom[category];
    if (typeof raw === 'string' && raw.includes(',')) {
      kingdom[category] = raw.split(',').filter(Boolean);
    }
  }
  return Object.keys(kingdom).length > 0 ? kingdom : null;
}

function activityForLog(log: AnalyticsLog): ActivityData | null {
  if (log.eventType !== 'KINGDOM_ACTIVITY' || log.eventOrError !== 'event') return null;
  const params = parseParams(log.params);
  if (!params) return null;

  return {
    kingdom: readKingdom(log, params),
  };
}

function addCount(counts: AnalyticsCountMap, key: string): void {
  counts[key] = (counts[key] ?? 0) + 1;
}

function cardsInKingdom(kingdom: Record<string, unknown>): string[] {
  const cards: string[] = [];
  for (const category of cardCategories) {
    const value = kingdom[category];
    const candidates = Array.isArray(value)
      ? value
      : typeof value === 'string'
        ? [value]
        : isRecord(value)
          ? Object.values(value).flatMap((nested) => Array.isArray(nested) ? nested : [nested])
          : [];
    for (const card of candidates) {
      if (typeof card === 'string' && card !== '') cards.push(card);
    }
  }
  return cards;
}

function setsInCards(cards: string[]): string[] {
  const setIds = new Set<string>();
  for (const cardId of cards) {
    const separatorIndex = cardId.indexOf('_');
    if (separatorIndex > 0) setIds.add(cardId.slice(0, separatorIndex));
  }
  return [...setIds];
}

function normalizedKingdom(kingdom: Record<string, unknown>): string {
  const normalized: Record<string, string[]> = {};
  for (const category of cardCategories) {
    const value = kingdom[category];
    const candidates = Array.isArray(value)
      ? value
      : typeof value === 'string'
        ? [value]
        : isRecord(value)
          ? Object.entries(value).flatMap(([key, nested]) =>
            (Array.isArray(nested) ? nested : [nested])
              .filter((item): item is string => typeof item === 'string')
              .map((item) => `${key}:${item}`),
          )
          : [];
    normalized[category] = candidates
      .filter((item): item is string => typeof item === 'string')
      .sort();
  }
  return JSON.stringify(normalized);
}

function dateForLog(log: AnalyticsLog): Date | null {
  const date = new Date(log.date.replace(' ', 'T'));
  return Number.isNaN(date.getTime()) ? null : date;
}

function bucketForDate(date: Date, grouping: TimeGrouping): { key: string; label: string } {
  if (grouping === 'month') {
    const key = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
    return {
      key,
      label: new Intl.DateTimeFormat('fr-FR', { month: 'short', year: '2-digit' }).format(date),
    };
  }

  if (grouping === 'hour') {
    const key = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}T${String(date.getHours()).padStart(2, '0')}`;
    return {
      key,
      label: `${new Intl.DateTimeFormat('fr-FR', { day: '2-digit', month: '2-digit' }).format(date)} ${String(date.getHours()).padStart(2, '0')}h`,
    };
  }

  if (grouping === 'day') {
    const key = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
    return {
      key,
      label: new Intl.DateTimeFormat('fr-FR', { day: '2-digit', month: '2-digit' }).format(date),
    };
  }

  const monday = new Date(date.getFullYear(), date.getMonth(), date.getDate());
  const daysSinceMonday = (monday.getDay() + 6) % 7;
  monday.setDate(monday.getDate() - daysSinceMonday);
  const key = `${monday.getFullYear()}-${String(monday.getMonth() + 1).padStart(2, '0')}-${String(monday.getDate()).padStart(2, '0')}`;
  return {
    key,
    label: `Sem. ${new Intl.DateTimeFormat('fr-FR', { day: '2-digit', month: '2-digit' }).format(monday)}`,
  };
}

function shiftBucket(key: string, grouping: TimeGrouping, amount: number): { key: string; label: string } {
  if (grouping === 'hour') {
    const date = dateForBucketKey(key, grouping);
    date.setHours(date.getHours() + amount);
    return bucketForDate(date, grouping);
  }
  if (grouping === 'day') {
    const date = dateForBucketKey(key, grouping);
    date.setDate(date.getDate() + amount);
    return bucketForDate(date, grouping);
  }
  if (grouping === 'month') {
    const date = dateForBucketKey(key, grouping);
    date.setMonth(date.getMonth() + amount);
    return bucketForDate(date, grouping);
  }
  const date = dateForBucketKey(key, grouping);
  date.setDate(date.getDate() + amount * 7);
  return bucketForDate(date, grouping);
}

function dateForBucketKey(key: string, grouping: TimeGrouping): Date {
  if (grouping === 'hour') {
    const [date, hour] = key.split('T');
    const [year, month, day] = date.split('-').map(Number);
    return new Date(year, month - 1, day, Number(hour));
  }
  const parts = key.split('-').map(Number);
  return grouping === 'month'
    ? new Date(parts[0], parts[1] - 1, 1)
    : new Date(parts[0], parts[1] - 1, parts[2]);
}

function createBuckets(
  countsByKey: Map<string, AnalyticsCountMap>,
  grouping: TimeGrouping,
): TimeBucket[] {
  const keys = [...countsByKey.keys()].sort();
  if (keys.length === 0) return [];
  const limit = grouping === 'hour' ? 24 : grouping === 'day' ? 30 : 12;
  const lastKey = keys[keys.length - 1];
  const last = bucketForDate(dateForBucketKey(lastKey, grouping), grouping);
  const firstKey = keys[0];
  const recentFirstKey = shiftBucket(last.key, grouping, -limit + 1).key;
  const visibleFirstKey = firstKey > recentFirstKey ? firstKey : recentFirstKey;
  let current = bucketForDate(dateForBucketKey(visibleFirstKey, grouping), grouping);
  const result: TimeBucket[] = [];
  while (current.key <= last.key && result.length < limit) {
    result.push({
      ...current,
      counts: countsByKey.get(current.key) ?? {},
    });
    current = shiftBucket(current.key, grouping, 1);
  }
  return result;
}

function addToTimeSeries(
  series: Map<string, AnalyticsCountMap>,
  date: Date,
  grouping: TimeGrouping,
  label: string,
): void {
  const key = bucketForDate(date, grouping).key;
  const counts = series.get(key) ?? {};
  addCount(counts, label);
  series.set(key, counts);
}

export function buildUsageTrends(logs: AnalyticsLog[], grouping: TimeGrouping): UsageTrends {
  const setCounts: AnalyticsCountMap = {};
  const cardCounts: AnalyticsCountMap = {};
  const setSeries = new Map<string, AnalyticsCountMap>();
  const cardSeries = new Map<string, AnalyticsCountMap>();
  const games = new Set<string>();
  const kingdomLogs = logs.filter((log) =>
    log.eventType === 'KINGDOM_ACTIVITY' && log.eventOrError === 'event',
  );

  for (const log of kingdomLogs) {
    const activity = activityForLog(log);
    if (!activity) continue;
    const date = dateForLog(log);
    const kingdom = activity.kingdom;
    if (!date || !kingdom) continue;
    games.add(normalizedKingdom(kingdom));

    const cards = cardsInKingdom(kingdom);
    for (const setId of setsInCards(cards)) {
      addCount(setCounts, setId);
      addToTimeSeries(setSeries, date, grouping, setId);
    }
    for (const card of cards) {
      addCount(cardCounts, card);
      addToTimeSeries(cardSeries, date, grouping, card);
    }
  }

  return {
    kingdomEvents: kingdomLogs.length,
    distinctGames: games.size,
    setCounts,
    cardCounts,
    setBuckets: createBuckets(setSeries, grouping),
    cardBuckets: createBuckets(cardSeries, grouping),
  };
}

export function buildEventTrends(
  logs: AnalyticsLog[],
  grouping: TimeGrouping,
  getSeriesLabel: (log: AnalyticsLog) => string | null = (log) => log.eventType || 'Inconnu',
): TimeBucket[] {
  const series = new Map<string, AnalyticsCountMap>();
  for (const log of logs) {
    const date = dateForLog(log);
    const label = getSeriesLabel(log);
    if (date && label) addToTimeSeries(series, date, grouping, label);
  }
  return createBuckets(series, grouping);
}
