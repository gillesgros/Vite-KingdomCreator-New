<template>
  <section class="events-view panel" aria-label="Événements observés dans le temps">
    <header class="events-heading">
      <div>
        <h2>Événements observés dans le temps</h2>
        <p>Évolution des accès, des actions et du suivi des royaumes.</p>
      </div>
    </header>

    <p v-if="loading" class="loading-note" role="status">
      Chargement progressif des journaux — {{ logs.length.toLocaleString('fr-FR') }} ligne(s) reçue(s).
    </p>
    <div class="event-kpis">
      <article>
        <span>Événements chargés</span>
        <strong>{{ logs.length.toLocaleString('fr-FR') }}</strong>
      </article>
      <article>
        <span>Types observés</span>
        <strong>{{ eventTypes.length.toLocaleString('fr-FR') }}</strong>
      </article>
      <article>
        <span>Erreurs</span>
        <strong>{{ errorCount.toLocaleString('fr-FR') }}</strong>
      </article>
    </div>

    <EventTrendsPanel :logs="logs" />

    <section class="event-table panel">
      <h3>Événements observés</h3>
      <p v-if="eventRows.length === 0" class="empty">Aucun événement chargé.</p>
      <div v-else class="table-wrap">
        <table>
          <thead><tr><th>Type</th><th>Événements</th><th>Erreurs</th></tr></thead>
          <tbody>
            <tr v-for="row in eventRows" :key="row.type">
              <td>{{ row.type }}</td>
              <td class="count">{{ row.count.toLocaleString('fr-FR') }}</td>
              <td class="count">{{ row.errors.toLocaleString('fr-FR') }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </section>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import type { AnalyticsLog } from '../types/analytics';
import EventTrendsPanel from './EventTrendsPanel.vue';

const props = defineProps<{ logs: AnalyticsLog[]; loading: boolean }>();
const eventTypes = computed(() => [...new Set(props.logs.map((log) => log.eventType || 'Inconnu'))].sort());
const errorCount = computed(() =>
  props.logs.filter((log) => log.eventOrError === 'error').length,
);
const eventRows = computed(() => {
  const rows = new Map<string, { type: string; count: number; errors: number }>();
  for (const log of props.logs) {
    const type = log.eventType || 'Inconnu';
    const row = rows.get(type) ?? { type, count: 0, errors: 0 };
    row.count++;
    if (log.eventOrError === 'error') row.errors++;
    rows.set(type, row);
  }
  return [...rows.values()].sort((left, right) =>
    right.count - left.count || left.type.localeCompare(right.type),
  );
});
</script>

<style scoped>
.events-view {
  display: grid;
  gap: 1rem;
}

.events-heading {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 1rem;
}

.events-heading h2,
.events-heading p {
  margin: 0;
}

.events-heading p {
  color: #64748b;
  font-size: .88rem;
}

.event-kpis {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: .75rem;
}

.event-kpis article {
  display: grid;
  gap: .25rem;
  padding: .8rem 1rem;
  border: 1px solid #dfe6f0;
  border-radius: .5rem;
  background: #fff;
}

.event-kpis span {
  color: #52627a;
  font-size: .85rem;
}

.event-kpis strong {
  color: #183c78;
  font-size: 1.5rem;
  font-variant-numeric: tabular-nums;
}

.event-table {
  padding: 1rem;
  border: 1px solid #e1e8f1;
  border-radius: 12px;
  background: #fff;
}

.event-table h3 {
  margin: 0 0 .75rem;
}

.table-wrap {
  max-height: 400px;
  overflow: auto;
}

table {
  width: 100%;
  border-collapse: collapse;
}

th,
td {
  padding: .5rem;
  border-bottom: 1px solid #edf1f6;
  text-align: left;
  overflow-wrap: anywhere;
}

th {
  position: sticky;
  top: 0;
  background: #fff;
  color: #64748b;
  font-size: .75rem;
  text-transform: uppercase;
}

.count {
  text-align: right;
  font-variant-numeric: tabular-nums;
}

.empty {
  color: #64748b;
}

.loading-note {
  margin: 0;
  color: #284f7c;
  font-size: .88rem;
}

@media (max-width: 600px) {
  .events-heading {
    align-items: flex-start;
    flex-direction: column;
  }
  .event-kpis {
    grid-template-columns: 1fr;
  }
}
</style>
