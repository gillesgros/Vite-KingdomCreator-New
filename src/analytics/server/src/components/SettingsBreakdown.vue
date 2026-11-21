<template>
  <section class="panel">
    <h3>{{ title }}</h3>
    <p v-if="rows.length === 0" class="empty">Aucun réglage enregistré.</p>
    <div v-else class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Groupe</th>
            <th>Réglage</th>
            <th>Valeur</th>
            <th class="count">Nombre</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(row, index) in rows" :key="`${row.group}-${row.setting}-${row.value}-${index}`">
            <td>{{ row.group }}</td>
            <td>{{ row.setting || '—' }}</td>
            <td>{{ row.value }}</td>
            <td class="count">{{ row.count.toLocaleString('fr-FR') }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import type {
  AnalyticsCountMap,
  AnalyticsSettingsCounts,
  AnalyticsValueCounts,
} from '../types/analytics';

type BreakdownCounts = AnalyticsValueCounts | AnalyticsSettingsCounts;

interface BreakdownRow {
  group: string;
  setting: string;
  value: string;
  count: number;
}

const props = defineProps<{
  title: string;
  counts: BreakdownCounts;
}>();

function isCountMap(value: unknown): value is AnalyticsCountMap {
  return typeof value === 'object' &&
    value !== null &&
    Object.values(value).every((count) => typeof count === 'number');
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

const rows = computed<BreakdownRow[]>(() => {
  const result: BreakdownRow[] = [];
  const counts: Record<string, unknown> = props.counts;

  for (const [group, entries] of Object.entries(counts)) {
    if (isCountMap(entries)) {
      for (const [value, count] of Object.entries(entries)) {
        result.push({ group, setting: '', value, count });
      }
      continue;
    }

    if (!isRecord(entries)) continue;
    for (const [setting, values] of Object.entries(entries)) {
      if (!isCountMap(values)) continue;
      for (const [value, count] of Object.entries(values)) {
        result.push({ group, setting, value, count });
      }
    }
  }

  return result.sort((left, right) =>
    right.count - left.count ||
    left.group.localeCompare(right.group) ||
    left.setting.localeCompare(right.setting) ||
    left.value.localeCompare(right.value),
  );
});
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
  font-size: .85rem;
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
  font-size: .72rem;
  text-transform: uppercase;
}

.count {
  width: 5rem;
  text-align: right;
  font-variant-numeric: tabular-nums;
}

.empty {
  margin: 0;
  color: #64748b;
  font-size: .88rem;
}
</style>
