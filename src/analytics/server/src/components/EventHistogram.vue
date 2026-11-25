<template>
  <section class="histogram-chart" :aria-label="title">
    <h3>{{ title }}</h3>
    <p v-if="buckets.length === 0 || seriesTypes.length === 0" class="empty">
      {{ buckets.length === 0 ? 'Aucune donnée temporelle disponible.' : 'Sélectionnez au moins une série.' }}
    </p>
    <div v-if="showLegend && seriesTypes.length > 0" class="legend" aria-label="Légende">
      <span v-for="series in seriesTypes" :key="series" class="legend-item">
        <i :style="{ backgroundColor: colors[series] }"></i>
        {{ series }}
      </span>
    </div>
    <div v-if="buckets.length > 0 && seriesTypes.length > 0" class="chart-scroll">
      <svg
        class="chart"
        :viewBox="`0 0 ${chartWidth} 300`"
        role="img"
        :aria-label="title"
        preserveAspectRatio="xMinYMin meet"
        :style="{ minWidth: `${chartWidth}px` }"
      >
        <g v-for="tick in yTicks" :key="tick">
          <line :x1="plotLeft" :y1="yPosition(tick)" :x2="plotRight" :y2="yPosition(tick)" class="grid-line" />
          <text :x="plotLeft - 8" :y="yPosition(tick) + 4" text-anchor="end" class="axis-label">
            {{ tick.toLocaleString('fr-FR') }}
          </text>
        </g>
        <line :x1="plotLeft" :y1="plotBottom" :x2="plotRight" :y2="plotBottom" class="axis" />
        <g v-for="index in labelIndexes" :key="buckets[index].key">
          <text
            :x="xPosition(index)"
            :y="plotBottom + 22"
            text-anchor="middle"
            class="bucket-label"
          >
            {{ buckets[index].label }}
          </text>
        </g>
        <g v-for="(bucket, bucketIndex) in buckets" :key="bucket.key">
          <rect
            v-for="(series, seriesIndex) in seriesTypes"
            :key="series"
            :x="barX(bucketIndex, seriesIndex)"
            :y="barY(bucket, seriesIndex, series)"
            :width="barWidth"
            :height="barHeight(bucket.counts[series] ?? 0)"
            :fill="colors[series]"
            :rx="stacked ? 0 : 2"
          >
            <title>{{ bucket.label }} — {{ series }} : {{ (bucket.counts[series] ?? 0).toLocaleString('fr-FR') }}</title>
          </rect>
        </g>
      </svg>
    </div>
    <section v-if=false class="data-table" :aria-label="`Données pour Excel — ${title}`">
      <h4>Données pour Excel — {{ title }}</h4>
      <p>Valeurs correspondant à la périodicité et aux événements sélectionnés ci-dessus.</p>
      <div class="table-scroll">
        <table>
          <thead>
            <tr>
              <th scope="col">Période</th>
              <th v-for="series in seriesTypes" :key="series" scope="col">{{ series }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="bucket in buckets" :key="bucket.key">
              <th scope="row">{{ bucket.key }}</th>
              <td v-for="series in seriesTypes" :key="series" class="number">
                {{ bucket.counts[series] ?? 0 }}
              </td>
            </tr>
            <tr v-if="buckets.length === 0">
              <td :colspan="Math.max(1, seriesTypes.length + 1)" class="empty">
                Aucune donnée à afficher.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </section>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import type { TimeBucket } from '../utils/analyticsTrends';

const props = defineProps<{
  title: string;
  buckets: TimeBucket[];
  seriesTypes: string[];
  colors: Record<string, string>;
  stacked?: boolean;
  showLegend?: boolean;
}>();

const plotLeft = 52;
const plotTop = 18;
const plotBottom = 246;
const chartWidth = computed(() => {
  const minimumGroupWidth = props.stacked
    ? 48
    : Math.max(32, Math.min(120, props.seriesTypes.length * 7));
  const groupWidth = Math.max((820 - plotLeft - 20) / Math.max(1, props.buckets.length), minimumGroupWidth);
  return Math.max(820, plotLeft + props.buckets.length * groupWidth + 20);
});
const plotRight = computed(() => chartWidth.value - 20);
const groupWidth = computed(() =>
  (plotRight.value - plotLeft) / Math.max(1, props.buckets.length),
);
const barWidth = computed(() => props.stacked
  ? Math.max(1, Math.min(48, groupWidth.value * .65))
  : Math.max(1, Math.min(18, (groupWidth.value * .84) / Math.max(1, props.seriesTypes.length))));
const maximum = computed(() => Math.max(
  1,
  ...props.buckets.map((bucket) => props.stacked
    ? props.seriesTypes.reduce((total, series) => total + (bucket.counts[series] ?? 0), 0)
    : Math.max(0, ...props.seriesTypes.map((series) => bucket.counts[series] ?? 0))),
));
const yTicks = computed(() => [...new Set([0, Math.ceil(maximum.value / 2), maximum.value])]);
const labelIndexes = computed(() => {
  const stride = Math.max(1, Math.ceil(props.buckets.length / 8));
  const indexes = props.buckets
    .map((_, index) => index)
    .filter((index) => index % stride === 0 || index === props.buckets.length - 1);
  return indexes;
});

function xPosition(index: number): number {
  return plotLeft + groupWidth.value * (index + .5);
}

function yPosition(value: number): number {
  return plotBottom - (value / maximum.value) * (plotBottom - plotTop);
}

function barX(bucketIndex: number, seriesIndex: number): number {
  if (props.stacked) return xPosition(bucketIndex) - barWidth.value / 2;
  const clusterWidth = barWidth.value * props.seriesTypes.length;
  return xPosition(bucketIndex) - clusterWidth / 2 + barWidth.value * seriesIndex;
}

function barY(bucket: TimeBucket, seriesIndex: number, series: string): number {
  const base = props.stacked
    ? props.seriesTypes
      .slice(0, seriesIndex)
      .reduce((total, previousSeries) => total + (bucket.counts[previousSeries] ?? 0), 0)
    : 0;
  return yPosition(base + (bucket.counts[series] ?? 0));
}

function barHeight(value: number): number {
  return (value / maximum.value) * (plotBottom - plotTop);
}
</script>

<style scoped>
.histogram-chart {
  min-width: 0;
  padding: .9rem;
  border: 1px solid #e1e8f1;
  border-radius: 12px;
  background: #fff;
}

h3 {
  margin: 0 0 .4rem;
  font-size: 1rem;
}

.data-table {
  margin-top: 1rem;
}

.data-table h4 {
  margin: 0;
  font-size: .9rem;
}

.data-table p {
  margin: .15rem 0 .5rem;
  color: #64748b;
  font-size: .8rem;
}

.chart-scroll {
  overflow-x: auto;
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
  flex: 0 0 auto;
  border-radius: 2px;
}

.table-scroll {
  max-height: 320px;
  overflow: auto;
  border: 1px solid #e1e8f1;
  border-radius: .4rem;
}

table {
  width: 100%;
  border-collapse: collapse;
  font-size: .8rem;
}

th,
td {
  padding: .4rem .55rem;
  border-bottom: 1px solid #edf1f6;
  text-align: left;
  white-space: nowrap;
}

thead th {
  position: sticky;
  top: 0;
  z-index: 1;
  background: #f7f9fc;
}

tbody th {
  font-weight: 500;
}

.number {
  text-align: right;
  font-variant-numeric: tabular-nums;
}

.chart {
  display: block;
  width: 100%;
  min-width: 560px;
  height: auto;
}

.grid-line {
  stroke: #e7ecf2;
  stroke-width: 1;
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
  margin: .5rem 0 0;
  color: #64748b;
  font-size: .88rem;
}
</style>
