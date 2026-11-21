export type AnalyticsCountMap = Record<string, number>;
export type AnalyticsValueCounts = Record<string, AnalyticsCountMap>;
export type AnalyticsSettingsCounts = Record<string, AnalyticsValueCounts>;

export interface RecentKingdom {
  date: string;
  sessionId: string | null;
  source: unknown;
  kingdom: unknown;
  configuration: unknown;
}

export interface AnalyticsSummary {
  kingdomEvents: number;
  playAgainEvents: number;
  eventTypes: AnalyticsCountMap;
  sources: AnalyticsCountMap;
  sets: AnalyticsCountMap;
  topSets: AnalyticsCountMap;
  cards: AnalyticsCountMap;
  topCards: AnalyticsCountMap;
  settings: AnalyticsSettingsCounts;
  recentKingdoms: RecentKingdom[];
  pages: AnalyticsCountMap;
  pageLanguages: AnalyticsCountMap;
  viewedSets: AnalyticsCountMap;
  viewedBoxesSets: AnalyticsCountMap;
  interactions: AnalyticsCountMap;
  errors: AnalyticsCountMap;
  randomizationEvents: number;
  randomizationTypes: AnalyticsCountMap;
  randomizationSets: AnalyticsCountMap;
  randomizationConfiguration: AnalyticsValueCounts;
  randomizationAdjustedElements: AnalyticsCountMap;
  randomizationSettings: AnalyticsSettingsCounts;
}

export interface AnalyticsLog {
  id: number;
  date: string;
  receivedAt: string;
  eventOrError: string;
  eventType: string;
  params: string | null;
  sessionId: string | null;
  clientContext: string | null;
}

export interface AnalyticsLogLocation {
  country: string;
  subdivision: string;
  city: string;
  latitude: number | null;
  longitude: number | null;
}

export type LogCategory = '' | 'event' | 'error';
export type LogsPerPage = 10 | 25 | 50 | 100 | 250 | 500 | 1000;

export interface LogsFilters {
  category?: LogCategory;
  eventType?: string;
  sessionId?: string;
  paramsSearch?: string;
  country?: string;
  device?: string;
  browser?: string;
  startDate?: string;
  endDate?: string;
  page?: number;
  perPage?: LogsPerPage;
}

export interface LogsResponse {
  logs: AnalyticsLog[];
  eventTypes: string[];
  countries: string[];
  devices: string[];
  browsers: string[];
  page: number;
  perPage: LogsPerPage;
  totalCount: number;
}

export type MaintenanceAction =
  | { action: 'clear' }
  | { action: 'purge-before'; date: string }
  | { action: 'delete-logs'; ids: number[] };

export interface MaintenanceResponse {
  message: string;
  deletedCount: number | false;
  date?: string;
}
