<template>
  <section class="panel">
    <h3>{{ title }}</h3>
    <p v-if="rows.length === 0" class="empty">Aucune donnée pour cette période.</p>
    <div v-else class="table-wrap">
      <table>
        <thead>
          <tr><th>Élément</th><th class="count">Nombre</th></tr>
        </thead>
        <tbody>
          <tr v-for="[label, count] in rows" :key="label">
            <td>{{ label }}</td>
            <td class="count">{{ count.toLocaleString('fr-FR') }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import type { AnalyticsCountMap } from '../types/analytics';

const props = withDefaults(defineProps<{
  title: string;
  counts: AnalyticsCountMap;
}>(), {});

const rows = computed(() =>
  Object.entries(props.counts)
    .sort(([leftLabel, leftCount], [rightLabel, rightCount]) =>
      rightCount - leftCount || leftLabel.localeCompare(rightLabel),
    ),
);
</script>

<style scoped>
.panel {
  min-width: 0;
  padding: 1rem;
  border: 1px solid #e1e8f1;
  border-radius: 12px;
  background: #fff;
}

h3 {
  margin: 0 0 .8rem;
  font-size: 1rem;
}

.table-wrap {
  max-height: 340px;
  overflow: auto;
}

table {
  width: 100%;
  border-collapse: collapse;
  font-size: .88rem;
}

th,
td {
  padding: .45rem .35rem;
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
  width: 5.5rem;
  text-align: right;
  font-variant-numeric: tabular-nums;
}

.empty {
  margin: 0;
  color: #64748b;
  font-size: .88rem;
}
</style>
