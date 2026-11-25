<template>
  <section class="trend-panel">
    <h3>{{ title }}</h3>
    <p v-if="buckets.length === 0" class="empty">Aucune donnée temporelle disponible.</p>
    <template v-else>
      <div class="legend" aria-label="Légende">
        <span v-for="(series, index) in visibleSeries" :key="series" class="legend-item">
          <i :style="{ backgroundColor: colors[index % colors.length] }"></i>
          {{ series }}
        </span>
      </div>
      <div class="chart-scroll">
        <svg
          class="chart"
          viewBox="0 0 920 280"
          role="img"
          :aria-label="title"
          preserveAspectRatio="xMinYMin meet"
        >
          <line x1="48" y1="230" x2="910" y2="230" class="axis" />
          <line x1="48" y1="18" x2="48" y2="230" class="axis" />
          <text x="42" y="24" text-anchor="end" class="axis-label">{{ maximum }}</text>
          <text x="42" y="232" text-anchor="end" class="axis-label">0</text>
          <g v-for="(bucket, bucketIndex) in buckets" :key="bucket.key">
            <g v-for="(series, seriesIndex) in visibleSeries" :key="series">
              <rect
                :x="barX(bucketIndex, seriesIndex)"
                :y="barY(bucket.counts[series] ?? 0)"
                :width="barWidth"
                :height="barHeight(bucket.counts[series] ?? 0)"
                :fill="colors[seriesIndex % colors.length]"
                rx="2"
              >
                <title>{{ bucket.label }} — {{ series }} : {{ (bucket.counts[series] ?? 0).toLocaleString('fr-FR') }}</title>
              </rect>
            </g>
            <text
              :x="bucketX(bucketIndex)"
              y="250"
              text-anchor="middle"
              class="bucket-label"
            >
              {{ bucket.label }}
            </text>
          </g>
        </svg>
      </div>
    </template>
  </section>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import type { TimeBucket } from '../utils/analyticsTrends';

const props = defineProps<{
  title: string;
  buckets: TimeBucket[];
  limit?: number;
}>();

const colors = ['#285b91', '#d08032', '#4b8a65', '#8d5fa8', '#bd5260', '#4e9b9d'];
const visibleSeries = computed(() => {
  const totals: Record<string, number> = {};
  for (const bucket of props.buckets) {
    for (const [label, count] of Object.entries(bucket.counts)) {
      totals[label] = (totals[label] ?? 0) + count;
    }
  }
  return Object.entries(totals)
    .sort(([leftLabel, leftCount], [rightLabel, rightCount]) =>
      rightCount - leftCount || leftLabel.localeCompare(rightLabel),
    )
    .slice(0, props.limit ?? 6)
    .map(([label]) => label);
});

const maximum = computed(() =>
  Math.max(1, ...props.buckets.flatMap((bucket) =>
    visibleSeries.value.map((series) => bucket.counts[series] ?? 0),
  )),
);
const barWidth = computed(() =>
  Math.max(3, Math.min(20, 52 / Math.max(1, visibleSeries.value.length))),
);
const groupWidth = computed(() => 860 / Math.max(1, props.buckets.length));

function bucketX(index: number): number {
  return 52 + groupWidth.value * (index + .5);
}

function barX(bucketIndex: number, seriesIndex: number): number {
  const width = barWidth.value;
  const groupBarsWidth = width * visibleSeries.value.length;
  return bucketX(bucketIndex) - groupBarsWidth / 2 + width * seriesIndex;
}

function barHeight(value: number): number {
  return (value / maximum.value) * 195;
}

function barY(value: number): number {
  return 230 - barHeight(value);
}
</script>

<style scoped>
.trend-panel {
  min-width: 0;
  padding: 1rem;
  border: 1px solid #e1e8f1;
  border-radius: 12px;
  background: #fff;
}

h3 {
  margin: 0 0 .65rem;
  font-size: 1rem;
}

.legend {
  display: flex;
  flex-wrap: wrap;
  gap: .35rem .8rem;
  margin-bottom: .5rem;
}

.legend-item {
  display: inline-flex;
  align-items: center;
  gap: .35rem;
  color: #52627a;
  font-size: .78rem;
}

.legend-item i {
  width: .65rem;
  height: .65rem;
  border-radius: 2px;
}

.chart-scroll {
  overflow-x: auto;
}

.chart {
  display: block;
  width: 100%;
  min-width: 620px;
  height: auto;
}

.axis {
  stroke: #cbd5e1;
  stroke-width: 1;
}

.axis-label,
.bucket-label {
  fill: #64748b;
  font-size: 11px;
}

.empty {
  margin: 0;
  color: #64748b;
  font-size: .88rem;
}
</style>
