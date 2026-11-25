<template>
  <section class="usage-trends" aria-label="Évolution de l’utilisation des sets et cartes">
    <header class="trends-heading">
      <div>
        <h3>Évolution de l’utilisation</h3>
        <p>
          Les données proviennent de KINGDOM_ACTIVITY. Une extension est comptée une fois par royaume
          lorsqu’au moins une de ses cartes y est présente.
        </p>
      </div>
      <label>
        Période
        <select v-model="grouping">
          <option value="week">Par semaine</option>
          <option value="month">Par mois</option>
        </select>
      </label>
    </header>

    <p v-if="loading" class="loading-note" role="status">
      Chargement progressif des journaux — {{ logs.length.toLocaleString('fr-FR') }} ligne(s) reçue(s).
    </p>
    <div class="game-metrics">
      <article class="game-metric">
        <span>Événements KINGDOM_ACTIVITY chargés</span>
        <strong>{{ trends.kingdomEvents.toLocaleString('fr-FR') }}</strong>
      </article>
      <article class="game-metric">
        <span>Jeux distincts (composition de cartes)</span>
        <strong>{{ trends.distinctGames.toLocaleString('fr-FR') }}</strong>
      </article>
    </div>

    <div class="trend-grid">
      <TrendChart title="Extensions présentes dans les royaumes" :buckets="trends.setBuckets" />
      <TrendChart title="Cartes présentes dans les royaumes" :buckets="trends.cardBuckets" />
    </div>
    <div class="trend-grid">
      <CountTable title="Extensions présentes (journaux chargés)" :counts="topSets" />
      <CountTable title="Cartes les plus utilisées (journaux chargés)" :counts="topCards" />
    </div>
    <p v-if="logs.length === 0" class="empty">
      Chargez les journaux pour afficher leur évolution temporelle.
    </p>
  </section>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type { AnalyticsCountMap, AnalyticsLog } from '../types/analytics';
import { buildUsageTrends, type TimeGrouping } from '../utils/analyticsTrends';
import CountTable from './CountTable.vue';
import TrendChart from './TrendChart.vue';

const props = defineProps<{ logs: AnalyticsLog[]; loading: boolean }>();
const emit = defineEmits<{
  'usage-counts-change': [counts: { setCounts: AnalyticsCountMap; cardCounts: AnalyticsCountMap }];
}>();
const grouping = ref<TimeGrouping>('week');
const trends = computed(() => buildUsageTrends(props.logs, grouping.value));
const topSets = computed(() => topCounts(trends.value.setCounts));
const topCards = computed(() => topCounts(trends.value.cardCounts));

watch(
  trends,
  ({ setCounts, cardCounts }) => emit('usage-counts-change', { setCounts, cardCounts }),
  { immediate: true },
);

function topCounts(counts: Record<string, number>): Record<string, number> {
  return Object.fromEntries(
    Object.entries(counts)
      .sort(([leftLabel, leftCount], [rightLabel, rightCount]) =>
        rightCount - leftCount || leftLabel.localeCompare(rightLabel),
      )
      .slice(0, 20),
  );
}
</script>

<style scoped>
.usage-trends {
  display: grid;
  gap: 1rem;
  margin-top: 1rem;
  padding: 1rem;
  border: 1px solid #d8dde5;
  border-radius: .5rem;
  background: #f7f8fa;
}

.trends-heading {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 1rem;
}

.trends-heading h3,
.trends-heading p {
  margin: 0;
}

.trends-heading p,
.game-metric span {
  color: #64748b;
  font-size: .85rem;
}

.trends-heading label {
  display: grid;
  gap: .25rem;
  font-size: .85rem;
  font-weight: 600;
}

select {
  min-height: 2rem;
  padding: .25rem .5rem;
  border: 1px solid #c5ccd6;
  border-radius: .35rem;
  background: #fff;
}

.game-metrics {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: .75rem;
}

.game-metric {
  display: grid;
  gap: .25rem;
  padding: .75rem;
  border: 1px solid #dfe6f0;
  border-radius: .5rem;
  background: #fff;
}

.game-metric strong {
  color: #183c78;
  font-size: 1.4rem;
  font-variant-numeric: tabular-nums;
}

.trend-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 1rem;
}

.empty {
  margin: 0;
  color: #64748b;
}

.loading-note {
  margin: 0;
  color: #284f7c;
  font-size: .88rem;
}

@media (max-width: 800px) {
  .trend-grid,
  .game-metrics {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 560px) {
  .trends-heading {
    align-items: flex-start;
    flex-direction: column;
  }
}
</style>
