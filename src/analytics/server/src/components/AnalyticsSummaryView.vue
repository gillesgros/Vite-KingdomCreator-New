<template>
  <section class="summary-view panel" aria-label="Synthèse — statistiques d’utilisation des extensions">
    <header class="section-header">
      <div>
        <p class="muted">
          Fréquence d’utilisation des différentes extensions (Sets) dans les configurations et randomisations.
        </p>
      </div>
      <p v-if="source" class="source-label">Source : <strong>{{ source }}</strong></p>
    </header>

    <p v-if="loading" class="state-message" role="status">
      Chargement des statistiques…
    </p>

    <div v-if="errorMessage && errorStatus !== 401 && errorStatus !== 403" class="error-panel" role="alert">
      <strong v-if="errorStatus === 401">Jeton invalide ou manquant</strong>
      <strong v-else-if="errorStatus === 403">Accès refusé</strong>
      <strong v-else-if="errorStatus === 503">Configuration serveur requise</strong>
      <strong v-else>Erreur de chargement</strong>
      <p>{{ errorMessage }}</p>
      <p v-if="errorStatus === 401 || errorStatus === 403">
        Vérifiez ou remplacez le jeton dans la barre de contrôle, puis relancez le chargement.
      </p>
      <p v-else-if="errorStatus === 503">
        Le serveur doit définir <code>ANALYTICS_DASHBOARD_TOKEN</code> avant de permettre la lecture des statistiques.
      </p>
    </div>

    <p v-else-if="!source && !loading" class="state-message">
      Sélectionnez une source pour afficher ses statistiques.
    </p>

    <p v-else-if="!summary && !loading && errorStatus !== 401 && errorStatus !== 403" class="state-message">
      Aucune statistique à afficher pour cette source.
    </p>

    <template v-if="summary">
      <div class="extension-grid">
        <CountTable title="Extensions présentes dans les royaumes" :counts="topKingdomSets" />
        <CountTable title="Extensions utilisées lors du « Randomize »" :counts="summary.sets" />
      </div>
      <UsageTrends
        :usage="summary.activityUsage"
      />

      <details class="other-statistics">
        <summary>Autres statistiques détaillées</summary>
        <div class="kpi-grid">
          <article class="kpi-card">
            <span>Royaumes enregistrés</span>
            <strong>{{ summary.kingdomEvents.toLocaleString('fr-FR') }}</strong>
          </article>
          <article class="kpi-card">
            <span>Reprises (« Play Again »)</span>
            <strong>{{ summary.playAgainEvents.toLocaleString('fr-FR') }}</strong>
          </article>
          <article class="kpi-card">
            <span>Événements de randomisation</span>
            <strong>{{ summary.randomizationEvents.toLocaleString('fr-FR') }}</strong>
          </article>
        </div>

        <div class="summary-grid">
          <CountTable
            title="Cartes et addons présents dans les royaumes (top 20)"
            :counts="topKingdomCards"
          />
          <CountTable
            v-for="table in countTables"
            :key="table.key"
            :title="table.title"
            :counts="table.counts"
          />
        </div>

        <div class="nested-grid">
          <SettingsBreakdown title="Réglages observés sur les royaumes" :counts="summary.settings" />
          <SettingsBreakdown title="Configuration des randomisations" :counts="summary.randomizationConfiguration" />
          <SettingsBreakdown title="Réglages des randomisations" :counts="summary.randomizationSettings" />
        </div>

        <section class="recent-panel">
          <h3>Royaumes récents</h3>
          <p v-if="recentKingdoms.length === 0" class="empty">
            Aucun royaume récent enregistré.
          </p>
          <div v-else class="recent-list">
            <details
              v-for="kingdom in recentKingdoms"
              :key="kingdom.key"
              class="recent-item"
            >
              <summary>
                <span>{{ kingdom.date }}</span>
                <span>Origine : {{ kingdom.source }}</span>
                <span>Session : {{ kingdom.sessionId }}</span>
              </summary>
              <div class="recent-details">
                <div>
                  <strong>Royaume</strong>
                  <pre>{{ kingdom.kingdom }}</pre>
                </div>
                <div>
                  <strong>Configuration</strong>
                  <pre>{{ kingdom.configuration }}</pre>
                </div>
              </div>
            </details>
          </div>
        </section>
      </details>
    </template>
  </section>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import {
  AnalyticsApiError,
  fetchAnalyticsSummary,
} from '../api/analyticsApi';
import type {
  AnalyticsCountMap,
  AnalyticsSummary,
} from '../types/analytics';
import CountTable from './CountTable.vue';
import SettingsBreakdown from './SettingsBreakdown.vue';
import UsageTrends from './UsageTrends.vue';

const props = defineProps<{
  source: string;
  token: string;
  refreshKey: number;
}>();

const emit = defineEmits<{
  'loading-change': [loading: boolean];
  'auth-error': [error: { status: number; message: string }];
  'summary-change': [summary: AnalyticsSummary | null];
}>();

const summary = ref<AnalyticsSummary | null>(null);
const loading = ref(false);
const errorMessage = ref<string | null>(null);
const errorStatus = ref<number | null>(null);
let requestVersion = 0;
let displayedSource = '';

interface RecentKingdomDisplay {
  key: string;
  date: string;
  sessionId: string;
  source: string;
  kingdom: string;
  configuration: string;
}

const recentKingdoms = computed<RecentKingdomDisplay[]>(() =>
  (summary.value?.recentKingdoms ?? []).map((kingdom, index) => ({
    key: `${kingdom.date}-${kingdom.sessionId ?? index}`,
    date: kingdom.date,
    sessionId: kingdom.sessionId ?? '—',
    source: sourceLabel(kingdom.source),
    kingdom: formatJson(kingdom.kingdom),
    configuration: formatJson(kingdom.configuration),
  })),
);

const topKingdomSets = computed(() => topCounts(summary.value?.activityUsage?.setCounts ?? {}, 45));
const topKingdomCards = computed(() => topCounts(summary.value?.activityUsage?.cardCounts ?? {}, 20));

function topCounts(counts: AnalyticsCountMap, limit: number): AnalyticsCountMap {
  return Object.fromEntries(
    Object.entries(counts)
      .sort(([leftLabel, leftCount], [rightLabel, rightCount]) =>
        rightCount - leftCount || leftLabel.localeCompare(rightLabel),
      )
      .slice(0, limit),
  );
}

const countTables = computed(() => {
  if (!summary.value) return [];
  return [
    { key: 'activity-sources', title: 'Origine des royaumes', counts: summary.value.sources },
    { key: 'randomization-sets', title: 'Sets utilisés lors du « Randomize »', counts: summary.value.sets },
    { key: 'event-types', title: 'Actions (EventType)', counts: summary.value.eventTypes },
    { key: 'pages', title: 'Pages consultées', counts: summary.value.pages },
    { key: 'page-languages', title: 'Langues des règles', counts: summary.value.pageLanguages },
    { key: 'viewed-sets', title: 'Sets consultés dans Sets', counts: summary.value.viewedSets },
    { key: 'viewed-boxes-sets', title: 'Sets consultés dans Boxes', counts: summary.value.viewedBoxesSets },
    { key: 'randomization-types', title: 'Types de randomisation', counts: summary.value.randomizationTypes },
    { key: 'randomization-adjustments', title: 'Réglages ajustés', counts: summary.value.randomizationAdjustedElements },
    { key: 'interactions', title: 'Interactions', counts: summary.value.interactions },
    { key: 'errors', title: 'Erreurs enregistrées', counts: summary.value.errors },
  ] satisfies Array<{ key: string; title: string; counts: AnalyticsCountMap }>;
});

function formatJson(value: unknown): string {
  return JSON.stringify(value, null, 2) ?? String(value);
}

function sourceLabel(value: unknown): string {
  return typeof value === 'string' ? value : JSON.stringify(value) ?? 'unknown';
}

async function loadSummary(): Promise<void> {
  const currentRequest = ++requestVersion;
  errorMessage.value = null;
  errorStatus.value = null;

  if (!props.source) {
    summary.value = null;
    emit('summary-change', null);
    displayedSource = '';
    loading.value = false;
    emit('loading-change', false);
    return;
  }

  if (displayedSource !== props.source) {
    summary.value = null;
    emit('summary-change', null);
  }
  loading.value = true;
  emit('loading-change', true);

  try {
    const result = await fetchAnalyticsSummary(props.source, props.token);
    if (currentRequest === requestVersion) {
      summary.value = result;
      emit('summary-change', result);
      displayedSource = props.source;
    }
  } catch (error) {
    if (currentRequest === requestVersion) {
      summary.value = null;
      emit('summary-change', null);
      const message = error instanceof Error
        ? error.message
        : 'Impossible de charger les statistiques.';
      errorMessage.value = message;
      errorStatus.value = error instanceof AnalyticsApiError ? error.status : null;
      if (errorStatus.value === 401 || errorStatus.value === 403) {
        emit('auth-error', { status: errorStatus.value, message });
      }
    }
  } finally {
    if (currentRequest === requestVersion) {
      loading.value = false;
      emit('loading-change', false);
    }
  }
}

watch(
  () => [props.source, props.token, props.refreshKey] as const,
  () => {
    void loadSummary();
  },
  { immediate: true },
);

onBeforeUnmount(() => {
  requestVersion++;
  emit('loading-change', false);
});
</script>

<style scoped>
.summary-view {
  /*margin-top: 1rem;*/
  padding: 1rem;
  border: 1px solid #d8dde5;
  border-radius: .5rem;
  background: #fff;
}

.section-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1rem;
}

.section-header h2 {
  margin: 0 0 .6rem;
  font-size: 1.25rem;
}

.source-label {
  margin: 0;
  color: #667085;
  font-size: .9rem;
}

.muted {
  margin: 0;
  color: #667085;
}

.state-message,
.error-panel {
  padding: 1rem 1.1rem;
  border: 1px solid #d8dde5;
  border-radius: 12px;
  background: #fff;
  color: #667085;
}

.state-message {
  margin: 0;
}

.error-panel {
  border-color: #f1b8b5;
  background: #fff7f6;
  color: #912018;
}

.error-panel p {
  margin: .4rem 0 0;
}

.kpi-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 1rem;
  margin-bottom: 1rem;
}

.extension-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 1rem;
}

.other-statistics {
  margin-top: 1rem;
  padding: .65rem .8rem;
  border: 1px solid #d8dde5;
  border-radius: .4rem;
  background: #f7f8fa;
}

.other-statistics > summary {
  color: #284f7c;
  cursor: pointer;
  font-weight: 600;
}

.other-statistics > .kpi-grid,
.other-statistics > .summary-grid,
.other-statistics > .nested-grid,
.other-statistics > .recent-panel {
  margin-top: 1rem;
}

.kpi-card {
  display: flex;
  flex-direction: column;
  gap: .6rem;
  padding: 1.1rem;
  border: 1px solid #dfe6f0;
  border-radius: 12px;
  background: linear-gradient(135deg, #fff, #f8faff);
}

.kpi-card span {
  color: #52627a;
  font-size: .88rem;
}

.kpi-card strong {
  color: #183c78;
  font-size: 1.8rem;
  font-variant-numeric: tabular-nums;
}

.summary-grid,
.nested-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 1rem;
  margin-bottom: 1rem;
}

.recent-panel {
  padding: 1rem;
  border: 1px solid #e1e8f1;
  border-radius: 12px;
  background: #fff;
}

.recent-panel h3 {
  margin: 0 0 .8rem;
}

.recent-list {
  display: grid;
  gap: .55rem;
}

.recent-item {
  border: 1px solid #e1e8f1;
  border-radius: 9px;
}

.recent-item summary {
  display: flex;
  flex-wrap: wrap;
  gap: .4rem 1rem;
  padding: .75rem;
  color: #34445c;
  cursor: pointer;
  font-size: .85rem;
}

.recent-item summary span:first-child {
  font-weight: 700;
}

.recent-details {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: .8rem;
  padding: 0 .75rem .75rem;
}

.recent-details pre {
  max-height: 320px;
  margin: .4rem 0 0;
  padding: .75rem;
  overflow: auto;
  border-radius: 7px;
  background: #f5f7fb;
  font-size: .78rem;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}

.empty {
  margin: 0;
  color: #64748b;
  font-size: .88rem;
}

@media (max-width: 800px) {
  .kpi-grid {
    grid-template-columns: 1fr;
  }

  .extension-grid,
  .summary-grid,
  .nested-grid,
  .recent-details {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 560px) {
  .section-header {
    align-items: flex-start;
    flex-direction: column;
  }
}
</style>
