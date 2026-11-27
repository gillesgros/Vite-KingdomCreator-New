<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import {
  AnalyticsApiError,
  fetchAnalyticsTrafficLocationsPage,
  fetchLogs,
  runMaintenanceAction,
} from '../api/analyticsApi';
import type {
  AnalyticsLog,
  AnalyticsLogLocation,
  AnalyticsSummary,
  AnalyticsTrafficLocation,
  LogCategory,
  LogsFilters,
  LogsPerPage,
  MaintenanceAction,
  TrafficCategoryFilter,
} from '../types/analytics';
import LogsMap from './LogsMap.vue';

const props = defineProps<{
  source: string;
  token: string;
  refreshKey: number;
  analyticsSummary: AnalyticsSummary | null;
  summaryLoading: boolean;
}>();

const emit = defineEmits<{
  'loading-change': [loading: boolean];
  'dashboard-loaded': [source: string];
  'maintenance-complete': [];
  'auth-error': [error: { status: number; message: string }];
}>();

interface LogFilterState extends LogsFilters {
  category: LogCategory;
  eventType: string;
  sessionId: string;
  paramsSearch: string;
  country: string;
  device: string;
  browser: string;
  startDate: string;
  endDate: string;
  page: number;
  perPage: LogsPerPage;
}

interface LogRow {
  log: AnalyticsLog;
  country: string;
  device: string;
  browser: string;
  paramsText: string;
  location: AnalyticsLogLocation;
}

type TrafficLevel = 'categories' | 'regions' | 'countries' | 'subdivisions';
type TrafficSortColumn = 'label' | 'count';
type SortDirection = 'asc' | 'desc';

interface TrafficEntry {
  label: string;
  count: number;
}

interface LogTableColumn {
  label: string;
  className: string;
  minWidth: number;
}

interface ColumnResizeState {
  index: number;
  startX: number;
  startWidth: number;
}

const filterKeys = [
  'category',
  'eventType',
  'sessionId',
  'paramsSearch',
  'country',
  'device',
  'browser',
  'startDate',
  'endDate',
] as const;

const pageSizes: LogsPerPage[] = [10, 25, 50, 100, 250, 500, 1000];
const trafficFilterByLabel: Record<string, TrafficCategoryFilter> = {
  'Trafic Humain Légitime': 'human',
  'Trafic Robot / Suspect (sans Session ID)': 'robot-no-session',
  'Trafic Robot / Suspect (navigateur atypique)': 'robot-browser',
  'Trafic en erreur': 'error',
};
const tableColumns: LogTableColumn[] = [
  { label: 'Sélection', className: 'selection-column', minWidth: 48 },
  { label: 'Date', className: 'date-column', minWidth: 100 },
  { label: 'Catégorie', className: 'category-column', minWidth: 72 },
  { label: 'EventType', className: 'event-type-column', minWidth: 100 },
  { label: 'Session ID', className: 'session-column', minWidth: 120 },
  { label: 'Pays', className: 'country-column', minWidth: 50 },
  { label: 'Appareil', className: 'device-column', minWidth: 60 },
  { label: 'Navigateur', className: 'browser-column', minWidth: 70 },
  { label: 'Paramètres', className: 'params-column', minWidth: 100 },
  { label: 'Détails', className: 'details-column', minWidth: 58 },
];

function createDefaultFilters(): LogFilterState {
  return {
    category: '',
    eventType: '',
    sessionId: '',
    paramsSearch: '',
    country: '',
    device: '',
    browser: '',
    startDate: '',
    endDate: '',
    page: 1,
    perPage: 50,
  };
}

const filters = reactive<LogFilterState>(createDefaultFilters());
const logs = ref<AnalyticsLog[]>([]);
const eventTypes = ref<string[]>([]);
const countries = ref<string[]>([]);
const devices = ref<string[]>([]);
const browsers = ref<string[]>([]);
const totalCount = ref(0);
const loading = ref(false);
const errorMessage = ref<string | null>(null);
const errorStatus = ref<number | null>(null);
const selectedIds = ref<Set<number>>(new Set());
const selectedLog = ref<AnalyticsLog | null>(null);
const detailsDialog = ref<HTMLDialogElement | null>(null);
const columnWidths = ref<number[] | null>(null);
const maintenanceLoading = ref(false);
const maintenanceMessage = ref<string | null>(null);
const maintenanceError = ref<string | null>(null);
const purgeDate = ref('');
const trafficLevel = ref<TrafficLevel>('categories');
const trafficCategory = ref('');
const trafficFilterCategory = ref<TrafficCategoryFilter | ''>('');
const trafficRegion = ref('');
const trafficCountry = ref('');
const trafficSortColumn = ref<TrafficSortColumn>('count');
const trafficSortDirection = ref<SortDirection>('desc');
const loadedMapLocations = ref<AnalyticsTrafficLocation[]>([]);
const mapLocationsApproximate = ref(false);
const mapLocationsLoaded = ref(false);
const mapLoading = ref(false);
const mapPage = ref(0);
const mapEventsLoaded = ref(0);
const mapEventsTotal = ref(0);
const mapErrorMessage = ref<string | null>(null);
let requestVersion = 0;
let mapRequestVersion = 0;
let loadTimer: number | undefined;
let activeRequest: AbortController | undefined;
let activeMapRequest: AbortController | undefined;
let columnResizeState: ColumnResizeState | null = null;

const maximumSelection = 1000;
const maximumMapLocations = 2000;

interface MapLocationCounter {
  key: string;
  location: AnalyticsTrafficLocation;
}

function swapMapCounters(heap: MapLocationCounter[], indexes: Map<string, number>, first: number, second: number): void {
  [heap[first], heap[second]] = [heap[second], heap[first]];
  indexes.set(heap[first].key, first);
  indexes.set(heap[second].key, second);
}

function siftMapCounterUp(heap: MapLocationCounter[], indexes: Map<string, number>, index: number): void {
  while (index > 0) {
    const parent = Math.floor((index - 1) / 2);
    if (heap[parent].location.count <= heap[index].location.count) break;
    swapMapCounters(heap, indexes, parent, index);
    index = parent;
  }
}

function siftMapCounterDown(heap: MapLocationCounter[], indexes: Map<string, number>, index: number): void {
  while (true) {
    const left = index * 2 + 1;
    const right = left + 1;
    let smallest = index;
    if (left < heap.length && heap[left].location.count < heap[smallest].location.count) {
      smallest = left;
    }
    if (right < heap.length && heap[right].location.count < heap[smallest].location.count) {
      smallest = right;
    }
    if (smallest === index) break;
    swapMapCounters(heap, indexes, index, smallest);
    index = smallest;
  }
}

function addMapLocations(
  locations: AnalyticsTrafficLocation[],
  heap: MapLocationCounter[],
  indexes: Map<string, number>,
): { locations: AnalyticsTrafficLocation[]; approximate: boolean } {
  let approximate = false;
  for (const location of locations) {
    const key = JSON.stringify([
      location.category,
      location.country,
      location.subdivision,
      location.city,
      location.latitude,
      location.longitude,
    ]);
    const index = indexes.get(key);
    if (index !== undefined) {
      heap[index].location.count += location.count;
      siftMapCounterDown(heap, indexes, index);
      continue;
    }

    if (heap.length < maximumMapLocations) {
      const newIndex = heap.length;
      heap.push({ key, location: { ...location } });
      indexes.set(key, newIndex);
      siftMapCounterUp(heap, indexes, newIndex);
      continue;
    }

    approximate = true;
    const estimatedCount = heap[0].location.count + location.count;
    indexes.delete(heap[0].key);
    heap[0] = { key, location: { ...location, count: estimatedCount } };
    indexes.set(key, 0);
    siftMapCounterDown(heap, indexes, 0);
  }
  return {
    locations: heap.map(({ location }) => location),
    approximate,
  };
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function parseContext(raw: string | null): Record<string, unknown> {
  if (!raw) return {};
  try {
    const parsed: unknown = JSON.parse(raw);
    return isRecord(parsed) ? parsed : {};
  } catch {
    return {};
  }
}

function trafficBadgeClass(row: LogRow): string {
  const category = getTrafficCategory(row);
  if (category === 'Trafic Humain Légitime') return 'badge-human';
  if (category === 'Trafic en erreur') return 'badge-error';
  return 'badge-bot';
}

function readString(context: Record<string, unknown>, key: string): string {
  const value = context[key];
  return typeof value === 'string' ? value : '';
}

function readCoordinate(
  context: Record<string, unknown>,
  keys: string[],
): number | null {
  for (const key of keys) {
    const value = context[key];
    if (typeof value === 'number' && Number.isFinite(value)) return value;
  }
  return null;
}

function formatJson(raw: string | null): string {
  if (raw === null) return '—';
  try {
    return JSON.stringify(JSON.parse(raw)) ?? raw;
  } catch {
    return raw;
  }
}

function parseJsonValue(raw: string | null): unknown {
  if (raw === null) return null;
  try {
    return JSON.parse(raw);
  } catch {
    return raw;
  }
}

function formatLogDetails(log: AnalyticsLog): string {
  return JSON.stringify({
    ...log,
    params: parseJsonValue(log.params),
    clientContext: parseJsonValue(log.clientContext),
  }, null, 2) ?? '';
}

const rows = computed<LogRow[]>(() =>
  logs.value.map((log) => {
    const context = parseContext(log.clientContext);
    const country = readString(context, 'country') || log.country || '';
    const subdivision = readString(context, 'subdivision');
    const city = readString(context, 'city');
    const browser = log.browser ?? readString(context, 'browser');

    return {
      log,
      country: country || '—',
      device: readString(context, 'device') || log.device || '—',
      browser: browser || '—',
      paramsText: formatJson(log.params),
      location: {
        country,
        subdivision,
        city,
        latitude: readCoordinate(context, ['latitude', 'lat']),
        longitude: readCoordinate(context, ['longitude', 'lng', 'lon']),
      },
    };
  }),
);

const regionByCountry: Record<string, string> = {
  US: 'Amérique du Nord', CA: 'Amérique du Nord', MX: 'Amérique du Nord', GL: 'Amérique du Nord', BM: 'Amérique du Nord',
  CR: 'Amérique Centrale & Caraïbes', PA: 'Amérique Centrale & Caraïbes', GT: 'Amérique Centrale & Caraïbes',
  HN: 'Amérique Centrale & Caraïbes', SV: 'Amérique Centrale & Caraïbes', NI: 'Amérique Centrale & Caraïbes',
  BZ: 'Amérique Centrale & Caraïbes', CU: 'Amérique Centrale & Caraïbes', DO: 'Amérique Centrale & Caraïbes',
  PR: 'Amérique Centrale & Caraïbes', JM: 'Amérique Centrale & Caraïbes', HT: 'Amérique Centrale & Caraïbes',
  TT: 'Amérique Centrale & Caraïbes', GP: 'Amérique Centrale & Caraïbes', MQ: 'Amérique Centrale & Caraïbes',
  BR: 'Amérique du Sud', AR: 'Amérique du Sud', CL: 'Amérique du Sud', CO: 'Amérique du Sud',
  PE: 'Amérique du Sud', VE: 'Amérique du Sud', EC: 'Amérique du Sud', UY: 'Amérique du Sud',
  PY: 'Amérique du Sud', BO: 'Amérique du Sud', GF: 'Amérique du Sud',
  FR: 'Europe de l’Ouest', DE: 'Europe de l’Ouest', GB: 'Europe de l’Ouest', NL: 'Europe de l’Ouest',
  BE: 'Europe de l’Ouest', CH: 'Europe de l’Ouest', AT: 'Europe de l’Ouest', IE: 'Europe de l’Ouest',
  LU: 'Europe de l’Ouest', MC: 'Europe de l’Ouest',
  FI: 'Europe du Nord', SE: 'Europe du Nord', NO: 'Europe du Nord', DK: 'Europe du Nord', AX: 'Europe du Nord',
  IS: 'Europe du Nord', EE: 'Europe du Nord', LV: 'Europe du Nord', LT: 'Europe du Nord',
  IT: 'Europe du Sud', ES: 'Europe du Sud', PT: 'Europe du Sud', GR: 'Europe du Sud',
  HR: 'Europe du Sud', SI: 'Europe du Sud', RS: 'Europe du Sud', BA: 'Europe du Sud',
  MT: 'Europe du Sud', CY: 'Europe du Sud', AL: 'Europe du Sud', MK: 'Europe du Sud', ME: 'Europe du Sud',
  PL: 'Europe de l’Est', CZ: 'Europe de l’Est', SK: 'Europe de l’Est', HU: 'Europe de l’Est',
  RO: 'Europe de l’Est', BG: 'Europe de l’Est', UA: 'Europe de l’Est', BY: 'Europe de l’Est',
  MD: 'Europe de l’Est', RU: 'Europe de l’Est',
  AE: 'Moyen-Orient', SA: 'Moyen-Orient', IL: 'Moyen-Orient', TR: 'Moyen-Orient',
  QA: 'Moyen-Orient', KW: 'Moyen-Orient', BH: 'Moyen-Orient', OM: 'Moyen-Orient',
  JO: 'Moyen-Orient', LB: 'Moyen-Orient', IQ: 'Moyen-Orient', IR: 'Moyen-Orient',
  JP: 'Asie de l’Est', KR: 'Asie de l’Est', CN: 'Asie de l’Est', TW: 'Asie de l’Est',
  HK: 'Asie de l’Est', MO: 'Asie de l’Est', MN: 'Asie de l’Est',
  SG: 'Asie du Sud-Est', TH: 'Asie du Sud-Est', VN: 'Asie du Sud-Est', MY: 'Asie du Sud-Est',
  ID: 'Asie du Sud-Est', PH: 'Asie du Sud-Est', KH: 'Asie du Sud-Est', LA: 'Asie du Sud-Est', MM: 'Asie du Sud-Est',
  IN: 'Asie du Sud & Centrale', PK: 'Asie du Sud & Centrale', BD: 'Asie du Sud & Centrale',
  LK: 'Asie du Sud & Centrale', NP: 'Asie du Sud & Centrale', KZ: 'Asie du Sud & Centrale', UZ: 'Asie du Sud & Centrale',
  ZA: 'Afrique', MA: 'Afrique', DZ: 'Afrique', TN: 'Afrique', EG: 'Afrique',
  NG: 'Afrique', KE: 'Afrique', GH: 'Afrique', SN: 'Afrique', CI: 'Afrique',
  CM: 'Afrique', ET: 'Afrique', TZ: 'Afrique', UG: 'Afrique', MU: 'Afrique', RE: 'Afrique', ZM: 'Afrique', 
  ZW: 'Afrique', MW: 'Afrique', MZ: 'Afrique', AO: 'Afrique', NA: 'Afrique',
  AU: 'Océanie', NZ: 'Océanie', FJ: 'Océanie', NC: 'Océanie', PF: 'Océanie',
};

function getCountryCode(row: LogRow): string {
  return row.location.country && row.location.country !== 'unknown'
    ? row.location.country.toUpperCase()
    : 'unknown';
}

function getRegion(countryCode: string): string {
  if (countryCode === 'unknown') return 'Inconnu';
  return regionByCountry[countryCode] ?? 'Autres territoires';
}

function getTrafficCategory(row: LogRow): string {
  if (row.log.eventOrError === 'error') return 'Trafic en erreur';

  const browser = row.browser === '—' ? '' : row.browser;
  if (!row.log.sessionId) return 'Trafic Robot / Suspect (sans Session ID)';
  if (!browser || /bot|crawl|spider|curl|python|wget/i.test(browser)) {
    return 'Trafic Robot / Suspect (navigateur atypique)';
  }
  return 'Trafic Humain Légitime';
}

const trafficSummary = computed(() => props.analyticsSummary?.traffic);
const mapLocations = computed<AnalyticsTrafficLocation[]>(() => {
  const locations = mapLocationsLoaded.value
    ? loadedMapLocations.value
    : trafficSummary.value?.locations ?? rows.value.map((row) => ({
      ...row.location,
      category: getTrafficCategory(row),
      count: 1,
    }));
  return locations.filter((location) => {
    if (trafficLevel.value === 'categories') return true;
    if (location.category !== trafficCategory.value) return false;
    if (trafficLevel.value === 'regions') return true;
    if (getRegion(location.country.toUpperCase()) !== trafficRegion.value) return false;
    if (trafficLevel.value === 'countries') return true;
    return location.country === trafficCountry.value;
  });
});

const lastPage = computed(() =>
  Math.max(1, Math.ceil(totalCount.value / filters.perPage)),
);
const pageRows = computed(() => rows.value);
const pageSelectionChecked = computed(() =>
  pageRows.value.length > 0 &&
  pageRows.value.every((row) => selectedIds.value.has(row.log.id)),
);
const selectedLogDetails = computed(() =>
  selectedLog.value ? formatLogDetails(selectedLog.value) : '',
);
const tableStyle = computed(() => {
  if (!columnWidths.value) return undefined;
  const width = columnWidths.value.reduce((sum, columnWidth) => sum + columnWidth, 0);
  return { width: `${width}px` };
});

function getColumnWidths(header: HTMLTableCellElement): number[] {
  if (columnWidths.value) return [...columnWidths.value];
  const table = header.closest('table');
  if (!table) return [];

  const widths = Array.from(table.querySelectorAll('thead th'))
    .map((cell) => Math.round(cell.getBoundingClientRect().width));
  columnWidths.value = widths;
  return widths;
}

function startColumnResize(event: PointerEvent, index: number): void {
  if (event.button !== 0 || !(event.currentTarget instanceof HTMLButtonElement)) return;
  const header = event.currentTarget.closest('th');
  if (!(header instanceof HTMLTableCellElement)) return;
  const widths = getColumnWidths(header);
  if (widths.length !== tableColumns.length) return;

  event.preventDefault();
  columnResizeState = {
    index,
    startX: event.clientX,
    startWidth: widths[index],
  };
  window.addEventListener('pointermove', resizeColumn);
  window.addEventListener('pointerup', stopColumnResize);
  window.addEventListener('pointercancel', stopColumnResize);
}

function resizeColumn(event: PointerEvent): void {
  if (!columnResizeState || !columnWidths.value) return;
  const { index, startX, startWidth } = columnResizeState;
  const next = [...columnWidths.value];
  next[index] = Math.max(
    tableColumns[index].minWidth,
    startWidth + event.clientX - startX,
  );
  columnWidths.value = next;
}

function stopColumnResize(): void {
  columnResizeState = null;
  window.removeEventListener('pointermove', resizeColumn);
  window.removeEventListener('pointerup', stopColumnResize);
  window.removeEventListener('pointercancel', stopColumnResize);
}

function handleResizeKeydown(event: KeyboardEvent, index: number): void {
  if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
  if (!(event.currentTarget instanceof HTMLButtonElement)) return;
  const header = event.currentTarget.closest('th');
  if (!(header instanceof HTMLTableCellElement)) return;
  const widths = getColumnWidths(header);
  if (widths.length !== tableColumns.length) return;

  event.preventDefault();
  widths[index] = Math.max(
    tableColumns[index].minWidth,
    widths[index] + (event.key === 'ArrowRight' ? 16 : -16),
  );
  columnWidths.value = widths;
}

const trafficEntries = computed<TrafficEntry[]>(() => {
  const counts = new Map<string, number>();
  const entries = trafficSummary.value?.entries;
  if (entries) {
    for (const entry of entries) {
      if (trafficLevel.value !== 'categories' && entry.category !== trafficCategory.value) continue;
      if (trafficLevel.value === 'regions' || trafficLevel.value === 'countries') {
        const region = getRegion(entry.country.toUpperCase());
        if (trafficLevel.value === 'countries' && region !== trafficRegion.value) continue;
        const label = trafficLevel.value === 'regions' ? region : entry.country;
        counts.set(label, (counts.get(label) ?? 0) + entry.count);
      } else if (trafficLevel.value === 'subdivisions') {
        if (entry.country !== trafficCountry.value) continue;
        const label = entry.subdivision || 'Non renseigné';
        counts.set(label, (counts.get(label) ?? 0) + entry.count);
      } else {
        counts.set(entry.category, (counts.get(entry.category) ?? 0) + entry.count);
      }
    }
  } else {
    for (const row of rows.value) {
      let label: string;
      if (trafficLevel.value === 'categories') {
        label = getTrafficCategory(row);
      } else if (trafficLevel.value === 'regions') {
        label = getRegion(getCountryCode(row));
      } else if (trafficLevel.value === 'countries') {
        label = getCountryCode(row);
      } else {
        label = row.location.subdivision || 'Non renseigné';
      }
      counts.set(label, (counts.get(label) ?? 0) + 1);
    }
  }
  return [...counts.entries()]
    .map(([label, count]) => ({ label, count }))
    .sort((left, right) => {
      const direction = trafficSortDirection.value === 'asc' ? 1 : -1;
      const comparison = trafficSortColumn.value === 'count'
        ? left.count - right.count
        : left.label.localeCompare(right.label, 'fr', { sensitivity: 'base', numeric: true });
      return direction * comparison || left.label.localeCompare(right.label, 'fr', { sensitivity: 'base', numeric: true });
    });
});

function toggleTrafficSort(column: TrafficSortColumn): void {
  if (trafficSortColumn.value === column) {
    trafficSortDirection.value = trafficSortDirection.value === 'asc' ? 'desc' : 'asc';
  } else {
    trafficSortColumn.value = column;
    trafficSortDirection.value = 'asc';
  }
}

function trafficSortIndicator(column: TrafficSortColumn): string {
  if (trafficSortColumn.value !== column) return '↕';
  return trafficSortDirection.value === 'asc' ? '↑' : '↓';
}

const trafficTitle = computed(() => {
  if (trafficLevel.value === 'categories') return 'Catégories de trafic';
  if (trafficLevel.value === 'regions') return `Trafic : ${trafficCategory.value}`;
  if (trafficLevel.value === 'countries') return `Région : ${trafficRegion.value}`;
  return `Pays : ${trafficCountry.value === 'unknown' ? 'Inconnu' : trafficCountry.value}`;
});

const trafficColumnTitle = computed(() => ({
  categories: 'Classification',
  regions: 'Région du monde',
  countries: 'Pays',
  subdivisions: 'Subdivision / État',
}[trafficLevel.value]));

const humanTrafficCount = computed(() =>
  trafficSummary.value?.totals.humanCount ??
    rows.value.filter((row) => getTrafficCategory(row) === 'Trafic Humain Légitime').length,
);
const robotTrafficCount = computed(() =>
  trafficSummary.value?.totals.robotCount ??
    rows.value.filter((row) => getTrafficCategory(row).startsWith('Trafic Robot / Suspect')).length,
);
const errorTrafficCount = computed(() =>
  trafficSummary.value?.totals.errorCount ??
    rows.value.filter((row) => getTrafficCategory(row) === 'Trafic en erreur').length,
);
const distinctSessionCount = computed(() =>
  trafficSummary.value?.totals.distinctSessionCount ??
    new Set(rows.value.map((row) => row.log.sessionId?.trim()).filter(Boolean)).size,
);

function selectTrafficEntry(entry: TrafficEntry): void {
  if (trafficLevel.value === 'categories') {
    const filterCategory = trafficFilterByLabel[entry.label];
    if (!filterCategory) return;
    trafficCategory.value = entry.label;
    trafficFilterCategory.value = filterCategory;
    trafficLevel.value = 'regions';
  } else if (trafficLevel.value === 'regions') {
    trafficRegion.value = entry.label;
    trafficLevel.value = 'countries';
  } else if (trafficLevel.value === 'countries') {
    trafficCountry.value = entry.label;
    trafficLevel.value = 'subdivisions';
  }
}

function goBackTraffic(): void {
  if (trafficLevel.value === 'subdivisions') {
    trafficLevel.value = 'countries';
  } else if (trafficLevel.value === 'countries') {
    trafficLevel.value = 'regions';
  } else if (trafficLevel.value === 'regions') {
    trafficLevel.value = 'categories';
    trafficCategory.value = '';
    trafficFilterCategory.value = '';
  }
}

function scheduleLogsLoad(): void {
  requestVersion++;
  activeRequest?.abort();
  if (loadTimer !== undefined) window.clearTimeout(loadTimer);
  loadTimer = window.setTimeout(() => {
    loadTimer = undefined;
    void loadLogs();
  }, 250);
}

async function loadTrafficLocations(): Promise<void> {
  const currentRequest = ++mapRequestVersion;
  activeMapRequest?.abort();
  const source = props.source;
  const token = props.token;

  loadedMapLocations.value = [];
  mapLocationsApproximate.value = false;
  mapLocationsLoaded.value = Boolean(source);
  mapErrorMessage.value = null;
  mapPage.value = 0;
  mapEventsLoaded.value = 0;
  mapEventsTotal.value = 0;

  if (!source) {
    mapLoading.value = false;
    return;
  }

  const controller = new AbortController();
  activeMapRequest = controller;
  mapLoading.value = true;

  try {
    let page = 1;
    let hasMore = true;
    let snapshotId: number | undefined;
    let cursor: number | undefined;
    let eventsLoaded = 0;
    let eventsTotal = 0;
    const locationHeap: MapLocationCounter[] = [];
    const locationIndexes = new Map<string, number>();
    while (hasMore) {
      const result = await fetchAnalyticsTrafficLocationsPage(
        source,
        page,
        token,
        snapshotId !== undefined && cursor !== undefined
          ? { snapshotId, cursor }
          : undefined,
        controller.signal,
      );
      if (currentRequest !== mapRequestVersion) return;

      if (result.page !== page || (snapshotId !== undefined && result.snapshotId !== snapshotId)) {
        throw new Error('Le serveur a renvoyé une page de localisations incohérente.');
      }
      if (snapshotId === undefined) {
        snapshotId = result.snapshotId;
        eventsTotal = result.totalCount ?? 0;
        mapEventsTotal.value = eventsTotal;
      }
      const accumulatedLocations = addMapLocations(
        result.locations,
        locationHeap,
        locationIndexes,
      );
      loadedMapLocations.value = accumulatedLocations.locations;
      mapLocationsApproximate.value ||= accumulatedLocations.approximate;
      mapPage.value = result.page;
      eventsLoaded += result.pageEventCount;
      mapEventsLoaded.value = eventsLoaded;
      hasMore = result.hasMore;
      if (hasMore && (result.nextCursor === null || (cursor !== undefined && result.nextCursor >= cursor))) {
        throw new Error('Le serveur a renvoyé un curseur de pagination invalide.');
      }
      cursor = result.nextCursor ?? undefined;
      page = result.page + 1;
    }
  } catch (error) {
    if (currentRequest === mapRequestVersion) {
      mapErrorMessage.value = error instanceof Error
        ? error.message
        : 'Impossible de charger toutes les localisations de trafic.';
      if (error instanceof AnalyticsApiError && (error.status === 401 || error.status === 403)) {
        emit('auth-error', { status: error.status, message: mapErrorMessage.value });
      }
    }
  } finally {
    if (currentRequest === mapRequestVersion) {
      mapLoading.value = false;
      activeMapRequest = undefined;
    }
  }
}

async function loadLogs(): Promise<void> {
  const currentRequest = ++requestVersion;
  activeRequest?.abort();
  const controller = new AbortController();
  activeRequest = controller;
  const source = props.source;
  const token = props.token;
  errorMessage.value = null;
  errorStatus.value = null;

  if (!source) {
    logs.value = [];
    totalCount.value = 0;
    eventTypes.value = [];
    countries.value = [];
    devices.value = [];
    browsers.value = [];
    loading.value = false;
    activeRequest = undefined;
    emit('loading-change', false);
    return;
  }

  loading.value = true;
  emit('loading-change', true);
  logs.value = [];
  try {
    const requestFilters: LogsFilters = {
      category: filters.category,
      eventType: filters.eventType,
      sessionId: filters.sessionId,
      paramsSearch: filters.paramsSearch,
      country: filters.country,
      device: filters.device,
      browser: filters.browser,
      startDate: filters.startDate,
      endDate: filters.endDate,
      trafficCategory: trafficFilterCategory.value || undefined,
      page: filters.page,
      perPage: filters.perPage,
    };
    const result = await fetchLogs(source, requestFilters, token, controller.signal);
    if (currentRequest !== requestVersion) return;

    logs.value = result.logs;
    totalCount.value = result.totalCount;
    eventTypes.value = result.eventTypes;
    countries.value = result.countries;
    devices.value = result.devices;
    browsers.value = result.browsers;
    emit('dashboard-loaded', source);
  } catch (error) {
    if (currentRequest === requestVersion) {
      const message = error instanceof Error
        ? error.message
        : 'Impossible de charger les journaux.';
      errorMessage.value = message;
      errorStatus.value = error instanceof AnalyticsApiError ? error.status : null;
      if (errorStatus.value === 401 || errorStatus.value === 403) {
        emit('auth-error', { status: errorStatus.value, message });
      }
    }
  } finally {
    if (currentRequest === requestVersion) {
      loading.value = false;
      activeRequest = undefined;
      emit('loading-change', false);
    }
  }
}

async function performMaintenance(
  action: MaintenanceAction,
  confirmation: string,
): Promise<void> {
  if (maintenanceLoading.value || !props.source || !window.confirm(confirmation)) return;

  maintenanceLoading.value = true;
  maintenanceMessage.value = null;
  maintenanceError.value = null;
  try {
    const result = await runMaintenanceAction(props.source, props.token, action);
    selectedIds.value = new Set();
    logs.value = [];
    totalCount.value = 0;
    maintenanceMessage.value = `${result.message} (${result.deletedCount || 0} ligne(s) supprimée(s)).`;
    emit('maintenance-complete');
  } catch (error) {
    const message = error instanceof Error
      ? error.message
      : 'L’action de maintenance a échoué.';
    maintenanceError.value = message;
    if (error instanceof AnalyticsApiError && (error.status === 401 || error.status === 403)) {
      emit('auth-error', { status: error.status, message });
    }
  } finally {
    maintenanceLoading.value = false;
  }
}

function deleteSelectedLogs(): Promise<void> {
  const ids = [...selectedIds.value];
  if (ids.length === 0 || ids.length > maximumSelection) return Promise.resolve();
  return performMaintenance(
    { action: 'delete-logs', ids },
    `Supprimer définitivement ${ids.length} ligne(s) de la source « ${props.source} » ?`,
  );
}

function clearLogs(): Promise<void> {
  return performMaintenance(
    { action: 'clear' },
    `Supprimer définitivement tous les journaux de la source « ${props.source} » ?`,
  );
}

function purgeLogs(): Promise<void> {
  if (!purgeDate.value) {
    maintenanceError.value = 'Choisissez une date valide avant de purger les journaux.';
    return Promise.resolve();
  }
  const date = purgeDate.value;
  return performMaintenance(
    { action: 'purge-before', date },
    `Supprimer définitivement les journaux de la source « ${props.source} » jusqu’au ${date} inclus ?`,
  );
}

watch(
  () => [props.source, props.token, props.refreshKey] as const,
  (next, previous) => {
    const sourceOrTokenChanged = !previous ||
      next[0] !== previous[0] || next[1] !== previous[1];
    if (sourceOrTokenChanged) {
      selectedIds.value = new Set();
      trafficLevel.value = 'categories';
      trafficCategory.value = '';
      trafficFilterCategory.value = '';
      trafficRegion.value = '';
      trafficCountry.value = '';
      filters.page = 1;
    }
    scheduleLogsLoad();
    void loadTrafficLocations();
  },
  { immediate: true },
);

watch(
  () => [...filterKeys.map((key) => filters[key]), filters.page, filters.perPage],
  (next, previous) => {
    const filtersChanged = previous &&
      filterKeys.some((_, index) => next[index] !== previous[index]);
    const pageSizeChanged = previous && next[filterKeys.length + 1] !== previous[filterKeys.length + 1];
    if (filtersChanged || pageSizeChanged) {
      selectedIds.value = new Set();
      trafficLevel.value = 'categories';
      trafficCategory.value = '';
      trafficFilterCategory.value = '';
      trafficRegion.value = '';
      trafficCountry.value = '';
    }
    if ((filtersChanged || pageSizeChanged) && filters.page !== 1) {
      filters.page = 1;
      return;
    }
    scheduleLogsLoad();
  },
);

watch(trafficFilterCategory, () => {
  filters.page = 1;
  selectedIds.value = new Set();
  logs.value = [];
  totalCount.value = 0;
  scheduleLogsLoad();
});

function resetFilters(): void {
  Object.assign(filters, createDefaultFilters());
}

function updatePageSize(event: Event): void {
  if (!(event.target instanceof HTMLSelectElement)) return;
  const value = Number(event.target.value);
  const pageSize = pageSizes.find((size) => size === value);
  if (pageSize !== undefined) {
    filters.perPage = pageSize;
  }
}

function setPage(page: number): void {
  if (page >= 1 && page <= lastPage.value) {
    filters.page = page;
  }
}

function toggleSelection(id: number, event: Event): void {
  if (!(event.target instanceof HTMLInputElement)) return;
  if (event.target.checked && !selectedIds.value.has(id) &&
      selectedIds.value.size >= maximumSelection) {
    event.target.checked = false;
    maintenanceError.value = `La sélection est limitée à ${maximumSelection} lignes.`;
    return;
  }
  const next = new Set(selectedIds.value);
  if (event.target.checked) {
    next.add(id);
  } else {
    next.delete(id);
  }
  selectedIds.value = next;
  maintenanceError.value = null;
}

function togglePageSelection(event: Event): void {
  if (!(event.target instanceof HTMLInputElement)) return;
  const next = new Set(selectedIds.value);
  const pageIds = pageRows.value.map((row) => row.log.id);

  if (event.target.checked) {
    const newIds = pageIds.filter((id) => !next.has(id));
    if (next.size + newIds.length > maximumSelection) {
      event.target.checked = false;
      maintenanceError.value = `La sélection est limitée à ${maximumSelection} lignes.`;
      return;
    }
    newIds.forEach((id) => next.add(id));
  } else {
    pageIds.forEach((id) => next.delete(id));
  }

  selectedIds.value = next;
  maintenanceError.value = null;
}

function showLogDetails(log: AnalyticsLog): void {
  selectedLog.value = log;
  detailsDialog.value?.showModal();
}

function closeLogDetails(): void {
  detailsDialog.value?.close();
}

onBeforeUnmount(() => {
  if (loadTimer !== undefined) {
    window.clearTimeout(loadTimer);
  }
  requestVersion++;
  activeRequest?.abort();
  mapRequestVersion++;
  activeMapRequest?.abort();
  detailsDialog.value?.close();
  stopColumnResize();
  emit('loading-change', false);
});
</script>

<template>
  <section v-if="source" class="activity-panel panel" aria-label="Carte et catégories de trafic">
    <p class="muted">
      Navigation : Catégorie de trafic → Région du monde → Pays → Subdivision.
      La carte et les indicateurs agrègent toute la source si le résumé serveur est
      disponible ; sinon, ils portent sur la page chargée. Les journaux du tableau
      sont filtrés et paginés côté serveur, y compris selon la catégorie de trafic sélectionnée.
    </p>

    <p v-if="errorMessage && errorStatus !== 401 && errorStatus !== 403" class="error" role="alert">
      <strong v-if="errorStatus === 401">Jeton invalide ou manquant.</strong>
      <strong v-else-if="errorStatus === 403">Accès refusé.</strong>
      <strong v-else-if="errorStatus === 503">Configuration serveur requise.</strong>
      <strong v-else>Erreur de chargement des journaux.</strong>
      {{ errorMessage }}
    </p>
    <p v-if="mapErrorMessage" class="error" role="alert">
      Erreur lors du chargement progressif de la carte : {{ mapErrorMessage }}
    </p>
    <div
      v-if="loading || props.summaryLoading || mapLoading"
      class="loading-status"
      role="status"
      aria-live="polite"
    >
      <span class="loading-indicator" aria-hidden="true"></span>
      <div class="loading-messages">
        <strong v-if="props.summaryLoading">
          Chargement de la synthèse globale : statistiques d’utilisation, tendances et agrégats de trafic…
        </strong>
        <strong v-if="loading">
          {{ trafficCategory
            ? `Chargement de « ${trafficCategory} » — page ${filters.page}…`
            : `Chargement des événements et erreurs — page ${filters.page}…` }}
        </strong>
        <strong v-if="mapLoading">
          Chargement du tableau de bord : carte — page {{ mapPage || 1 }},
          {{ mapEventsLoaded.toLocaleString('fr-FR') }}
          <template v-if="mapEventsTotal">
            / {{ mapEventsTotal.toLocaleString('fr-FR') }}
          </template>
          journaux traités, {{ loadedMapLocations.length.toLocaleString('fr-FR') }} points affichés…
        </strong>
      </div>
    </div>

    <div class="metrics">
      <div class="metric">
        {{ trafficSummary ? 'Requêtes de la source' : 'Requêtes sur la page' }}
        <strong>{{ (trafficSummary?.totals.totalCount ?? totalCount).toLocaleString('fr-FR') }}</strong>
      </div>
      <div class="metric human-metric">
        {{ trafficSummary ? 'Trafic Humain Légitime' : 'Trafic Humain Légitime (page)' }}
        <strong>{{ humanTrafficCount.toLocaleString('fr-FR') }}</strong>
      </div>
      <div class="metric bot-metric">
        {{ trafficSummary ? 'Trafic Robots / Suspects' : 'Trafic Robots / Suspects (page)' }}
        <strong>{{ robotTrafficCount.toLocaleString('fr-FR') }}</strong>
      </div>
      <div class="metric error-metric">
        {{ trafficSummary ? 'Erreurs' : 'Erreurs (page)' }}
        <strong>{{ errorTrafficCount.toLocaleString('fr-FR') }}</strong>
      </div>
      <div class="metric">
        {{ trafficSummary ? 'Sessions distinctes' : 'Sessions distinctes (page)' }}
        <strong>{{ distinctSessionCount.toLocaleString('fr-FR') }}</strong>
      </div>
    </div>

    <div class="analytics-grid">
      <article class="analytics-box">
        <h3>Carte interactive (selon la sélection de trafic)</h3>
        <LogsMap
          :locations="mapLocations"
          :approximate="mapLocationsApproximate"
          :subdivision-mode="trafficLevel === 'subdivisions'"
        />
      </article>

      <article class="analytics-box">
        <div class="breadcrumb">
          <strong>{{ trafficTitle }}</strong>
          <button
            v-if="trafficLevel !== 'categories'"
            class="primary"
            type="button"
            @click="goBackTraffic"
          >
            ← Retour
          </button>
        </div>
        <div class="table-wrap hierarchy-wrap">
          <table class="interactive-table">
            <thead>
              <tr>
                <th :aria-sort="trafficSortColumn === 'label' ? (trafficSortDirection === 'asc' ? 'ascending' : 'descending') : 'none'">
                  <button class="traffic-sort-button" type="button" @click="toggleTrafficSort('label')">
                    {{ trafficColumnTitle }}
                    <span aria-hidden="true">{{ trafficSortIndicator('label') }}</span>
                  </button>
                </th>
                <th
                  class="count-cell"
                  :aria-sort="trafficSortColumn === 'count' ? (trafficSortDirection === 'asc' ? 'ascending' : 'descending') : 'none'"
                >
                  <button class="traffic-sort-button" type="button" @click="toggleTrafficSort('count')">
                    Requêtes
                    <span aria-hidden="true">{{ trafficSortIndicator('count') }}</span>
                  </button>
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="trafficEntries.length === 0">
                <td colspan="2" class="muted">Aucune donnée pour cette sélection.</td>
              </tr>
              <tr v-for="entry in trafficEntries" :key="entry.label">
                <td>
                  <button
                    class="table-link"
                    type="button"
                    :disabled="trafficLevel === 'subdivisions'"
                    @click="selectTrafficEntry(entry)"
                  >
                    {{ entry.label }}<span v-if="trafficLevel !== 'subdivisions'"> ▸</span>
                  </button>
                </td>
                <td class="count-cell">{{ entry.count.toLocaleString('fr-FR') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </article>
    </div>

    <details class="filters-disclosure">
      <summary>Filtres</summary>
      <section class="filters-panel" aria-label="Filtres des journaux">
        <div class="date-filter-group">
          <label>
            Date/heure de début
            <input v-model="filters.startDate" type="datetime-local" step="60">
          </label>
          <label>
            Date/heure de fin
            <input v-model="filters.endDate" type="datetime-local" step="60">
          </label>
        </div>
        <button class="filter-reset" type="button" @click="resetFilters">
          Réinitialiser les filtres
        </button>
        <label class="filter-category">
          Catégorie
          <select v-model="filters.category">
            <option value="">Toutes les catégories</option>
            <option value="event">Événement</option>
            <option value="error">Erreur</option>
          </select>
        </label>
        <label class="filter-event-type">
          EventType
          <select v-model="filters.eventType">
            <option value="">Toutes les actions</option>
            <option v-if="filters.eventType && !eventTypes.includes(filters.eventType)" :value="filters.eventType">
              {{ filters.eventType }}
            </option>
            <option v-for="eventType in eventTypes" :key="eventType" :value="eventType">
              {{ eventType }}
            </option>
          </select>
        </label>
        <label class="filter-session">
          Session ID
          <input v-model.trim="filters.sessionId" maxlength="64" placeholder="Fragment de session, joker * / ?">
        </label>
        <label class="filter-country">
          Pays
          <select v-model="filters.country">
            <option value="">Tous les pays</option>
            <option v-if="filters.country && !countries.includes(filters.country)" :value="filters.country">
              {{ filters.country }}
            </option>
            <option v-for="country in countries" :key="country" :value="country">{{ country }}</option>
          </select>
        </label>
        <label class="filter-device">
          Appareil
          <select v-model="filters.device">
            <option value="">Tous les appareils</option>
            <option v-if="filters.device && !devices.includes(filters.device)" :value="filters.device">
              {{ filters.device }}
            </option>
            <option v-for="device in devices" :key="device" :value="device">{{ device }}</option>
          </select>
        </label>
        <label class="filter-browser">
          Navigateur
          <select v-model="filters.browser">
            <option value="">Tous les navigateurs</option>
            <option v-if="filters.browser && !browsers.includes(filters.browser)" :value="filters.browser">
              {{ filters.browser }}
            </option>
            <option v-for="browser in browsers" :key="browser" :value="browser">{{ browser }}</option>
          </select>
        </label>
        <label class="filter-params">
          Contenu des paramètres
          <input v-model.trim="filters.paramsSearch" maxlength="500" placeholder="Texte à retrouver dans le JSON">
        </label>
      </section>
    </details>



    <section class="logs-box analytics-box">
      <div class="logs-heading">
        <h3>
          Événements et erreurs
          <span v-if="trafficCategory">— {{ trafficCategory }}</span>
          ({{ totalCount.toLocaleString('fr-FR') }} résultat(s))
        </h3>
        <span v-if="selectedIds.size" class="muted">{{ selectedIds.size }} ligne(s) sélectionnée(s)</span>
      </div>
      <div class="table-wrap logs-table-wrap">
        <table
          class="logs-table"
          :class="{ 'columns-resized': columnWidths !== null }"
          :style="tableStyle"
        >
          <colgroup>
            <col
              v-for="(column, index) in tableColumns"
              :key="column.label"
              :class="column.className"
              :style="columnWidths ? { width: `${columnWidths[index]}px` } : undefined"
            >
          </colgroup>
          <thead>
            <tr>
              <th
                v-for="(column, index) in tableColumns"
                :key="column.label"
                scope="col"
                :class="{ 'resizable-header': true }"
              >
                <input
                  v-if="index === 0"
                  type="checkbox"
                  :checked="pageSelectionChecked"
                  aria-label="Sélectionner toutes les lignes de cette page"
                  @change="togglePageSelection"
                >
                <span v-else>{{ column.label }}</span>
                <button
                  class="column-resizer"
                  type="button"
                  :aria-label="`Redimensionner la colonne ${column.label}`"
                  @pointerdown.stop="startColumnResize($event, index)"
                  @keydown="handleResizeKeydown($event, index)"
                ></button>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!loading && !errorMessage && rows.length === 0">
              <td colspan="10" class="muted empty">Aucun événement pour cette sélection.</td>
            </tr>
            <tr v-for="row in pageRows" :key="row.log.id">
              <td>
                <input
                  type="checkbox"
                  :disabled="maintenanceLoading"
                  :checked="selectedIds.has(row.log.id)"
                  :aria-label="`Sélectionner la ligne ${row.log.id}`"
                  @change="toggleSelection(row.log.id, $event)"
                >
              </td>
              <td class="date-cell" :title="row.log.date">{{ row.log.date }}</td>
              <td>
                <span class="badge" :class="trafficBadgeClass(row)">
                  {{ row.log.eventOrError }}
                </span>
              </td>
              <td><strong>{{ row.log.eventType }}</strong></td>
              <td :title="row.log.sessionId || '—'">{{ row.log.sessionId || '—' }}</td>
              <td>{{ row.country }}</td>
              <td>{{ row.device }}</td>
              <td>{{ row.browser }}</td>
              <td class="params-cell"><pre>{{ row.paramsText }}</pre></td>
              <td class="details-cell">
                <button
                  class="details-button"
                  type="button"
                  :aria-label="`Afficher les détails complets de la ligne ${row.log.id}`"
                  @click="showLogDetails(row.log)"
                >
                  +
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <nav class="pagination" aria-label="Pagination des journaux">
        <span>{{ totalCount.toLocaleString('fr-FR') }} résultat(s) — page {{ filters.page }} sur {{ lastPage }}</span>
        <label>
          Lignes par page
          <select :value="filters.perPage" @change="updatePageSize">
            <option v-for="size in pageSizes" :key="size" :value="size">{{ size }}</option>
          </select>
        </label>
        <button
          type="button"
          :disabled="loading || filters.page <= 1"
          @click="setPage(filters.page - 1)"
        >
          Précédent
        </button>
        <button
          type="button"
          :disabled="loading || filters.page >= lastPage"
          @click="setPage(filters.page + 1)"
        >
          Suivant
        </button>
      </nav>
    </section>

    <dialog
      ref="detailsDialog"
      class="log-details-dialog"
      aria-labelledby="log-details-title"
      @close="selectedLog = null"
      @click.self="closeLogDetails"
    >
      <header class="dialog-heading">
        <h2 id="log-details-title">Détails complets de la ligne</h2>
        <button type="button" @click="closeLogDetails">Fermer</button>
      </header>
      <pre>{{ selectedLogDetails }}</pre>
    </dialog>

    <details class="maintenance-disclosure">
      <summary>Maintenance / Administration</summary>
      <section class="maintenance-panel" aria-label="Maintenance des journaux">
        <div class="maintenance-heading">
          <p>Les suppressions sont définitives et nécessitent le jeton administrateur.</p>
          <button
            class="danger"
            type="button"
            :disabled="maintenanceLoading || selectedIds.size === 0"
            @click="deleteSelectedLogs"
          >
            Supprimer la sélection ({{ selectedIds.size }})
          </button>
        </div>
        <form class="purge-form" @submit.prevent="purgeLogs">
          <label for="purge-date">Supprimer les journaux jusqu’à cette date incluse</label>
          <input id="purge-date" v-model="purgeDate" type="date" required>
          <button class="danger" type="submit" :disabled="maintenanceLoading || !purgeDate">Purger par date</button>
        </form>
        <button class="danger" type="button" :disabled="maintenanceLoading || !source" @click="clearLogs">
          Vider tous les journaux de cette source
        </button>
        <p v-if="maintenanceLoading" class="muted" role="status">Action de maintenance en cours…</p>
        <p v-if="maintenanceMessage" class="success" role="status">{{ maintenanceMessage }}</p>
        <p v-if="maintenanceError" class="error" role="alert">{{ maintenanceError }}</p>
      </section>
    </details>
  </section>
</template>

<style scoped>
.activity-panel {
  /*margin-top: 1rem;*/
  padding: 1rem;
  border: 1px solid #d8dde5;
  border-radius: .5rem;
  background: #fff;
}

h2 {
  margin: 0 0 0rem;
  font-size: 1.25rem;
}

h3 {
  margin: 0 0 .6rem;
  font-size: 1rem;
}

.muted {
  color: #667085;
  margin-top: 0;
}

.metrics {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 1rem;
  margin-bottom: 1rem;
}

.metric {
  padding: .8rem 1rem;
  border: 1px solid #e0e4ea;
  border-radius: .4rem;
  background: #fff;
  color: #596579;
}

.metric strong {
  display: block;
  color: #172235;
  font-size: 1.65rem;
}

.human-metric {
  border-left: 4px solid #16703c;
}

.human-metric strong {
  color: #16703c;
}

.bot-metric {
  border-left: 4px solid #b42318;
}

.bot-metric strong {
  color: #b45309;
}

.error-metric {
  border-left: 4px solid #b42318;
}

.error-metric strong {
  color: #b42318;
}

.analytics-grid {
  display: grid;
  grid-template-columns: 2fr 1fr;
  gap: 1rem;
  margin-top: 1rem;
}

.analytics-box {
  min-width: 0;
  padding: .9rem;
  border: 1px solid #e0e4ea;
  border-radius: .4rem;
  background: #fff;
}

.filters-disclosure,
.maintenance-disclosure {
  margin: .75rem 0;
  padding: .65rem .8rem;
  border: 1px solid #d8dde5;
  border-radius: .4rem;
  background: #f7f8fa;
}

.filters-disclosure > summary,
.maintenance-disclosure > summary {
  color: #284f7c;
  cursor: pointer;
  font-weight: 600;
}

.filters-panel {
  position: relative;
  display: flex;
  flex-wrap: wrap;
  align-items: end;
  gap: .75rem;
  margin-top: .8rem;
}

.filters-panel > label {
  min-width: 0;
}

.filters-panel > .filter-category {
  flex: 0 1 200px;
}

.filters-panel > .filter-event-type {
  flex: 0 1 180px;
}

.filters-panel > .filter-country {
  flex: 0 1 140px;
}

.filters-panel > .filter-device {
  flex: 0 1 180px;
}

.filters-panel > .filter-browser {
  flex: 0 1 180px;
}

.filters-panel > .filter-session {
  flex: 0 1 240px;
}

.filters-panel > .filter-params {
  flex: 0 1 300px;
}

.date-filter-group {
  display: grid;
  flex: 0 1 220px;
  gap: .75rem;
}

.filter-reset {
  position: absolute;
  top: 1.75rem;
  right: 0;
  z-index: 1;
}

.date-filter-group label,
.filters-panel > label,
.purge-form label {
  display: grid;
  align-content: start;
  gap: .25rem;
  font-weight: 600;
}

input:not([type="checkbox"]),
select {
  width: 100%;
  min-width: 0;
  min-height: 2.4rem;
  padding: .4rem .65rem;
  border: 1px solid #c5ccd6;
  border-radius: .35rem;
  background: #fff;
  color: #202735;
  font: inherit;
}

input:focus,
select:focus {
  border-color: #284f7c;
  outline: 2px solid rgb(40 79 124 / 15%);
}

button {
  min-height: 2.4rem;
  padding: .4rem .65rem;
  border: 1px solid #c5ccd6;
  border-radius: .35rem;
  background: #f7f8fa;
  color: #202735;
  font: inherit;
}

button:hover:not(:disabled) {
  background: #e9edf2;
}

button.primary {
  border-color: #284f7c;
  background: #284f7c;
  color: #fff;
}

button.primary:hover:not(:disabled) {
  background: #1c3e64;
}

.error {
  color: #b42318;
}

.success {
  color: #16703c;
}

.analytics-grid .table-wrap {
  max-height: 550px;
  overflow: auto;
}

table {
  width: 100%;
  border-collapse: collapse;
}

th,
td {
  padding: .6rem .7rem;
  border-bottom: 1px solid #e3e7ed;
  text-align: left;
  vertical-align: top;
}

th {
  position: sticky;
  top: 0;
  z-index: 1;
  background: #f7f8fa;
  white-space: nowrap;
}

.count-cell {
  width: 7rem;
  text-align: right;
  font-variant-numeric: tabular-nums;
}

.traffic-sort-button {
  display: inline-flex;
  align-items: center;
  gap: .3rem;
  padding: 0;
  border: 0;
  background: transparent;
  color: inherit;
  font: inherit;
  cursor: pointer;
}

.traffic-sort-button:hover {
  color: #284f7c;
}

.traffic-sort-button:focus-visible {
  outline: 2px solid #284f7c;
  outline-offset: 2px;
}

.count-cell .traffic-sort-button {
  width: 100%;
  justify-content: flex-end;
}

.interactive-table tbody tr:hover,
.logs-table tbody tr:hover {
  background: #f1f5f9;
}

.table-link {
  min-height: auto;
  padding: 0;
  border: 0;
  background: transparent;
  color: inherit;
  text-align: left;
}

.table-link:hover:not(:disabled) {
  background: transparent;
  color: #284f7c;
  text-decoration: underline;
}

.table-link:disabled {
  color: #202735;
  cursor: default;
}

.breadcrumb {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: .5rem;
  margin-bottom: .75rem;
  font-size: .95rem;
}

.breadcrumb button {
  min-height: auto;
  padding: .2rem .6rem;
  font-size: .85rem;
}

.hierarchy-wrap {
  max-height: 360px;
  overflow: auto;
}

.maintenance-disclosure {
  margin: .75rem 0;
}

.maintenance-panel {
  display: grid;
  gap: .75rem;
  margin-top: .75rem;
  padding: .8rem;
  border: 1px solid #e0e4ea;
  border-radius: .4rem;
  background: #fff;
}

.maintenance-heading,
.purge-form {
  display: flex;
  align-items: end;
  flex-wrap: wrap;
  gap: .75rem;
}

.maintenance-heading {
  justify-content: space-between;
}

.maintenance-heading p {
  margin: 0;
  color: #667085;
}

.loading-status {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  align-items: center;
  gap: .35rem .65rem;
  margin: .75rem 0;
  padding: .7rem .9rem;
  border: 1px solid #a9c5e8;
  border-left: 5px solid #284f7c;
  border-radius: .45rem;
  background: #eff6ff;
  color: #1e3a5f;
}

.loading-messages {
  display: grid;
  min-width: 0;
  gap: .35rem;
}

.loading-indicator {
  width: .8rem;
  height: .8rem;
  border: 2px solid #b9cee8;
  border-top-color: #284f7c;
  border-radius: 50%;
  animation: loading-spin .8s linear infinite;
}

@keyframes loading-spin {
  to { transform: rotate(360deg); }
}

@media (prefers-reduced-motion: reduce) {
  .loading-indicator {
    animation: none;
  }
}

.danger {
  border-color: #d7a29e;
  color: #a1261c;
}

.logs-box {
  margin-top: 1rem;
}

.logs-heading {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: .75rem;
}

.logs-heading h3 {
  margin: 0 0 .6rem;
}

.logs-table-wrap {
  position: relative;
  isolation: isolate;
  max-height: 600px;
  overflow: auto;
}

.logs-table thead {
  position: sticky;
  top: 0;
  z-index: 10;
}

.logs-table thead th {
  position: sticky !important;
  top: 0;
  z-index: 11;
  background: #f7f8fa;
}

.logs-table {
  min-width: 1150px;
  table-layout: fixed;
  font-size: .88rem;
  border-collapse: separate;
  border-spacing: 0;
}

.logs-table.columns-resized {
  min-width: 0;
}

.selection-column { width: 5%; }
.date-column { width: 12%; }
.category-column { width: 6%; }
.event-type-column { width: 14%; }
.session-column { width: 18%; }
.country-column { width: 5%; }
.device-column { width: 6%; }
.browser-column { width: 7%; }
.params-column { width: 22%; }
.details-column { width: 5%; }

.logs-table th,
.logs-table td {
  padding: .6rem .7rem;
}

.logs-table th.resizable-header {
  position: relative;
  padding-right: 1rem;
}

.column-resizer {
  position: absolute;
  top: 0;
  right: -3px;
  bottom: 0;
  z-index: 2;
  width: 8px;
  min-height: 0;
  padding: 0;
  border: 0;
  background: transparent;
  cursor: col-resize;
  touch-action: none;
}

.column-resizer::after {
  position: absolute;
  top: 25%;
  bottom: 25%;
  left: 3px;
  width: 1px;
  background: #aab4c2;
  content: "";
}

.column-resizer:hover::after,
.column-resizer:focus-visible::after {
  width: 2px;
  background: #284f7c;
}

.logs-table td:not(.params-cell):not(.details-cell) {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.logs-table th:first-child,
.logs-table td:first-child,
.details-cell {
  text-align: center;
}

.badge {
  display: inline-block;
  padding: .15rem .45rem;
  border-radius: .25rem;
  font-size: .8rem;
  font-weight: 600;
}

.badge-human {
  background: #dcfce7;
  color: #166534;
}

.badge-bot {
  background: #ffedd5;
  color: #9a3412;
}

.badge-error {
  background: #fee2e2;
  color: #991b1b;
}

.params-cell {
  min-width: 0;
}

.params-cell pre {
  max-height: 5rem;
  overflow: auto;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}

.details-button {
  min-width: 30px;
  min-height: 30px;
  padding: .15rem .5rem;
  border: 1px solid #c5ccd6;
  border-radius: .35rem;
  background: #fff;
  color: #202735;
  font-size: 1rem;
  line-height: 1;
}

.details-button:hover {
  border-color: #284f7c;
  background: #f0f5fb;
}

.log-details-dialog {
  width: min(780px, calc(100vw - 2rem));
  max-height: 80vh;
  padding: 1rem;
  border: 1px solid #d8dde5;
  border-radius: .5rem;
  box-shadow: 0 16px 48px rgb(15 23 42 / 25%);
}

.log-details-dialog::backdrop {
  background: rgb(15 23 42 / 50%);
}

.dialog-heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: .75rem;
}

.dialog-heading h2 {
  margin: 0;
  font-size: 1.15rem;
}

.log-details-dialog > pre {
  max-height: calc(80vh - 5rem);
  overflow: auto;
  padding: .75rem;
  border-radius: .35rem;
  background: #f7f8fa;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}

pre {
  max-height: 10rem;
  overflow: auto;
  margin: 0;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
  font: .8rem/1.4 ui-monospace, monospace;
}

.empty {
  text-align: center;
}

.pagination {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  flex-wrap: wrap;
  gap: .5rem;
  margin-top: .75rem;
}

.pagination span {
  margin-right: auto;
  color: #667085;
}

.pagination label {
  display: flex;
  align-items: center;
  gap: .45rem;
  color: #667085;
  white-space: nowrap;
}

.pagination select {
  width: auto;
  min-width: 5rem;
}

@media (max-width: 900px) {
  .analytics-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 520px) {
  .filter-reset {
    position: static;
    align-self: flex-end;
    margin-left: auto;
  }

  .logs-heading {
    align-items: flex-start;
    flex-direction: column;
  }
}
</style>
