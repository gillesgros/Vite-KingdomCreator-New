import type {
  AnalyticsSummary,
  LogsFilters,
  LogsRequestPerPage,
  LogsResponse,
  MaintenanceAction,
  MaintenanceResponse,
} from '../types/analytics';

const API_BASE_URL =
  import.meta.env.VITE_API_URL?.trim() ||
  (import.meta.env.DEV
    ? 'https://suivi.71yeti.fr/server.php'  // pour le dev on peut utiliser server-test.php
    : 'https://suivi.71yeti.fr/server.php');  // pour la prod

export class AnalyticsApiError extends Error {
  constructor(
    message: string,
    public readonly status: number,
  ) {
    super(message);
    this.name = 'AnalyticsApiError';
  }
}

function buildUrl(parameters: Record<string, string | number | undefined>): string {
  const query = new URLSearchParams();
  for (const [key, value] of Object.entries(parameters)) {
    if (value !== undefined && value !== '') {
      query.set(key, String(value));
    }
  }

  const separator = API_BASE_URL.includes('?') ? '&' : '?';
  return `${API_BASE_URL}${separator}${query.toString()}`;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function getErrorMessage(status: number, payload: unknown, rawBody: string): string {
  const serverMessage =
    isRecord(payload) && typeof payload.error === 'string'
      ? payload.error
      : rawBody.trim();

  if (status === 401) {
    return serverMessage
      ? `Jeton du tableau de bord invalide ou manquant : ${serverMessage}`
      : 'Jeton du tableau de bord invalide ou manquant (HTTP 401).';
  }
  if (status === 403) {
    return serverMessage
      ? `Accès au tableau de bord refusé : ${serverMessage}`
      : 'Accès au tableau de bord refusé (HTTP 403).';
  }
  return serverMessage || `Erreur HTTP ${status}.`;
}

async function requestJson<T>(
  url: string,
  token?: string,
  init: RequestInit = {},
): Promise<T> {
  const headers = new Headers(init.headers);
  if (token !== undefined) {
    headers.set('X-Dashboard-Token', token);
  }

  const response = await fetch(url, { ...init, headers });
  const rawBody = await response.text();
  let payload: unknown;

  try {
    payload = rawBody === '' ? null : JSON.parse(rawBody);
  } catch {
    payload = null;
    if (response.ok) {
      throw new AnalyticsApiError(
        'Le serveur a renvoyé une réponse qui n’est pas du JSON valide.',
        response.status,
      );
    }
  }

  if (!response.ok) {
    throw new AnalyticsApiError(
      getErrorMessage(response.status, payload, rawBody),
      response.status,
    );
  }

  return payload as T;
}

function isLogsRequestPerPage(value: number): value is LogsRequestPerPage {
  return [10, 25, 50, 100, 250, 500, 1000, 5000, 10000, 20000]
    .some((size) => size === value);
}

export async function fetchSources(): Promise<string[]> {
  const result: unknown = await requestJson(buildUrl({ listSources: 'true' }));
  if (!Array.isArray(result) || !result.every((source) => typeof source === 'string')) {
    throw new AnalyticsApiError(
      'Le serveur a renvoyé une liste de sources invalide.',
      200,
    );
  }
  return result;
}

export function fetchAnalyticsSummary(
  source: string,
  token: string,
): Promise<AnalyticsSummary> {
  return requestJson(
    buildUrl({ source, analytics: 'summary' }),
    token,
  );
}

export function fetchLogs(
  source: string,
  filters: LogsFilters,
  token: string,
  signal?: AbortSignal,
): Promise<LogsResponse> {
  if (filters.perPage !== undefined && !isLogsRequestPerPage(filters.perPage)) {
    throw new RangeError(
      'perPage doit être 10, 25, 50, 100, 250, 500, 1000, 5000, 10000 ou 20000.',
    );
  }

  return requestJson(
    buildUrl({
      source,
      logs: 'true',
      category: filters.category,
      eventType: filters.eventType,
      sessionId: filters.sessionId,
      paramsSearch: filters.paramsSearch,
      country: filters.country,
      device: filters.device,
      browser: filters.browser,
      startDate: filters.startDate,
      endDate: filters.endDate,
      page: filters.page,
      perPage: filters.perPage,
      afterId: filters.afterId,
      throughId: filters.throughId,
    }),
    token,
    { signal },
  );
}

export function runMaintenanceAction(
  source: string,
  token: string,
  action: MaintenanceAction,
): Promise<MaintenanceResponse> {
  return requestJson(
    buildUrl({ source }),
    token,
    {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(action),
    },
  );
}
