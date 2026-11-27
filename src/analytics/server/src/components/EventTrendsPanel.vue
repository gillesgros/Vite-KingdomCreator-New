<template>
  <section class="event-trends" aria-label="Évolution temporelle des événements">
    <section class="trend-block">
      <header class="block-heading">
        <div>
          <h3>Vue d’ensemble par catégorie</h3>
        </div>
        <label class="control">
          Périodicité
          <select v-model="overviewGrouping">
            <option value="hour">Par heure</option>
            <option value="day">Par jour</option>
            <option value="week">Par semaine</option>
            <option value="month">Par mois</option>
          </select>
        </label>
      </header>
      <div class="analysis-note">
        <p><strong>Accès :</strong>
          <code>RANDOMIZER_VIEW</code>, <code>BOX_VIEW</code>, <code>SET_VIEW</code>,
          <code>RULEBOOK_VIEW</code>, <code>SEARCH_CARDS_VIEW</code>, <code>CARDS_VIEW</code>,
          <code>SETTINGS_VIEW</code>, <code>HELP_VIEW</code>, <code>HISTORY_VIEW</code>.
        </p>
        <p><strong>Actions :</strong>
          <code>RANDOMIZE</code>, <code>RANDOMIZE_FULL_KINGDOM</code>,
          <code>RANDOMIZE_MULTIPLE_SUPPLY</code>, <code>RANDOMIZE_SINGLE</code>,
          <code>RANDOMIZE_EVENTS_AND_LANDMARKS</code>, <code>RANDOMIZE_BOONS</code>,
          <code>RANDOMIZE_ALLY</code>, <code>RANDOMIZE_PROPHECY</code>,
          <code>LOAD_FULL_KINGDOM_FROM_URL</code>, <code>LOAD_PARTIAL_KINGDOM_FROM_URL</code>,
          <code>MARK_KINGDOM_PLAYED</code>, <code>HISTORY_KINGDOM_REPLAY</code>,
          <code>NEWS_MODAL_OPEN</code>.
        </p>
        <p><strong>Suivi :</strong> <code>KINGDOM_ACTIVITY</code>. <code>UPDATE_KINGDOM</code>
          est écarté car il accompagne la même mise à jour. Les erreurs, dont
          <code>RANDOMIZE_CREATE_SUPPLY</code>, sont affichées séparément.
        </p>
        <p class="analysis-caveat">
          <code>BOX_VIEW</code> et <code>SET_VIEW</code> comptent aussi les changements de sélection.
          Les types inconnus apparaissent dans « Autres ». <code>PAGE_VIEW</code>,
          <code>LOAD_INITIAL_KINGDOM</code>, <code>RANDOMIZE_KINGDOM</code> et
          <code>LOAD_INITIAL_RANDOMIZE</code> sont prévus dans le code, mais ne sont pas émis actuellement.
        </p>
      </div>
      <EventHistogram
        title="Événements par catégorie"
        :buckets="overviewBuckets"
        :series-types="overviewSeries"
        :colors="categoryColors"
        :stacked="true"
        :show-legend="true"
      />
    </section>

    <section class="trend-block">
      <header class="block-heading">
        <div>
          <h3>Détail par type d’événement</h3>
          <p>Sélectionnez une catégorie et les types à comparer.</p>
        </div>
        <div class="controls">
          <label class="control">
            Catégorie
            <select v-model="detailCategory">
              <option
                v-for="(label, category) in EVENT_CATEGORY_LABELS"
                :key="category"
                :value="category"
              >
                {{ label }}
              </option>
            </select>
          </label>
          <label class="control">
            Périodicité
            <select v-model="detailGrouping">
              <option value="hour">Par heure</option>
              <option value="day">Par jour</option>
              <option value="week">Par semaine</option>
              <option value="month">Par mois</option>
            </select>
          </label>
        </div>
      </header>

      <fieldset class="event-toggles">
        <legend>
          Types affichés
          <label class="select-all">
            <input
              type="checkbox"
              :checked="allSelected"
              :indeterminate="someSelected"
              :disabled="eventTypes.length === 0"
              @change="toggleAll"
            >
            Tout sélectionner
          </label>
        </legend>
        <label v-for="eventType in eventTypes" :key="eventType">
          <input
            type="checkbox"
            :checked="isVisible(eventType)"
            @change="toggleEvent(eventType, $event)"
          >
          <i :style="{ backgroundColor: eventColors[eventType] }" aria-hidden="true"></i>
          <span>{{ eventType }}</span>
        </label>
        <p v-if="eventTypes.length === 0" class="empty">Aucun événement dans cette catégorie.</p>
      </fieldset>

      <EventHistogram
        :title="`${EVENT_CATEGORY_LABELS[detailCategory]} — détail`"
        :buckets="detailBuckets"
        :series-types="visibleTypes"
        :colors="eventColors"
      />
    </section>

    <section class="trend-block">
      <header class="block-heading">
        <div>
          <h3>Erreurs au fil du temps</h3>
          <p>Les échecs restent séparés des événements réussis, même lorsque leur type est identique.</p>
        </div>
        <label class="control">
          Périodicité
          <select v-model="errorGrouping">
            <option value="hour">Par heure</option>
            <option value="day">Par jour</option>
            <option value="week">Par semaine</option>
            <option value="month">Par mois</option>
          </select>
        </label>
      </header>
      <EventHistogram
        title="Erreurs par type"
        :buckets="errorBuckets"
        :series-types="errorTypes"
        :colors="eventColors"
        :show-legend="true"
      />
    </section>
  </section>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type { AnalyticsEventTrends, TimeBucket } from '../types/analytics';
import {
  categorizeEventType,
  EVENT_CATEGORY_LABELS,
  type AnalyticsEventCategory,
  type TimeGrouping,
} from '../utils/analyticsTrends';
import EventHistogram from './EventHistogram.vue';

const props = defineProps<{
  eventTypes: string[];
  eventTrends: AnalyticsEventTrends | undefined;
}>();
const overviewGrouping = ref<TimeGrouping>('week');
const detailGrouping = ref<TimeGrouping>('week');
const errorGrouping = ref<TimeGrouping>('week');
const detailCategory = ref<AnalyticsEventCategory>('actions');
const selectionMode = ref<'top' | 'all' | 'custom'>('top');
const customTypes = ref(new Set<string>());
function groupEventBuckets(buckets: TimeBucket[]): TimeBucket[] {
  return buckets.map((bucket) => {
    const counts: Record<string, number> = {};
    for (const [eventType, count] of Object.entries(bucket.counts)) {
      const category = categorizeEventType(eventType);
      if (category) {
        const label = EVENT_CATEGORY_LABELS[category];
        counts[label] = (counts[label] ?? 0) + count;
      }
    }
    return { ...bucket, counts };
  });
}

function sumBucketCounts(buckets: TimeBucket[]): Record<string, number> {
  const counts: Record<string, number> = {};
  for (const bucket of buckets) {
    for (const [label, count] of Object.entries(bucket.counts)) {
      counts[label] = (counts[label] ?? 0) + count;
    }
  }
  return counts;
}

const overviewBuckets = computed(() =>
  groupEventBuckets(props.eventTrends?.[overviewGrouping.value]?.events ?? []),
);
const overviewSeries = computed(() =>
  Object.values(EVENT_CATEGORY_LABELS).filter((label) =>
    overviewBuckets.value.some((bucket) => (bucket.counts[label] ?? 0) > 0),
  ),
);
const categoryColors: Record<string, string> = {
  [EVENT_CATEGORY_LABELS.access]: '#285b91',
  [EVENT_CATEGORY_LABELS.actions]: '#d08032',
  [EVENT_CATEGORY_LABELS.kingdom]: '#4b8a65',
  [EVENT_CATEGORY_LABELS.other]: '#7b8494',
};

const detailBuckets = computed(() => {
  const buckets = props.eventTrends?.[detailGrouping.value]?.events ?? [];
  return buckets.map((bucket) => ({
    ...bucket,
    counts: Object.fromEntries(
      Object.entries(bucket.counts).filter(([eventType]) =>
        categorizeEventType(eventType) === detailCategory.value,
      ),
    ),
  }));
});
const eventCounts = computed(() => sumBucketCounts(detailBuckets.value));
const eventTypes = computed(() => Object.keys(eventCounts.value).sort((left, right) => left.localeCompare(right)));
const rankedEventTypes = computed(() => Object.entries(eventCounts.value)
  .sort(([leftType, leftCount], [rightType, rightCount]) =>
    rightCount - leftCount || leftType.localeCompare(rightType),
  )
  .map(([eventType]) => eventType));
const defaultTypes = computed(() => rankedEventTypes.value.slice(0, 8));
const visibleTypes = computed(() => {
  if (selectionMode.value === 'all') return eventTypes.value;
  if (selectionMode.value === 'custom') {
    return eventTypes.value.filter((eventType) => customTypes.value.has(eventType));
  }
  return defaultTypes.value;
});
const allSelected = computed(() =>
  eventTypes.value.length > 0 && visibleTypes.value.length === eventTypes.value.length,
);
const someSelected = computed(() => visibleTypes.value.length > 0 && !allSelected.value);
const errorBuckets = computed(() => props.eventTrends?.[errorGrouping.value]?.errors ?? []);
const errorTypes = computed(() => Object.entries(sumBucketCounts(errorBuckets.value))
  .sort(([leftType, leftCount], [rightType, rightCount]) =>
    rightCount - leftCount || leftType.localeCompare(rightType),
  )
  .map(([eventType]) => eventType));
const eventColors = computed(() => {
  const rankedTypes = [...props.eventTypes].sort((left, right) => left.localeCompare(right));
  return Object.fromEntries(rankedTypes.map((eventType, index) => [
    eventType,
    `hsl(${Math.round((index * 137.508) % 360)} 55% 40%)`,
  ]));
});

watch(detailCategory, () => {
  selectionMode.value = 'top';
  customTypes.value = new Set();
});

function isVisible(eventType: string): boolean {
  return visibleTypes.value.includes(eventType);
}

function toggleEvent(eventType: string, event: Event): void {
  const target = event.target;
  if (!(target instanceof HTMLInputElement)) return;
  const nextSelected = new Set(visibleTypes.value);
  if (target.checked) nextSelected.add(eventType);
  else nextSelected.delete(eventType);
  customTypes.value = nextSelected;
  selectionMode.value = 'custom';
}

function toggleAll(event: Event): void {
  const target = event.target;
  if (!(target instanceof HTMLInputElement)) return;
  if (target.checked) {
    selectionMode.value = 'all';
    return;
  }
  customTypes.value = new Set();
  selectionMode.value = 'custom';
}
</script>

<style scoped>
.event-trends {
  display: grid;
  gap: 1rem;
}

.trend-block {
  display: grid;
  gap: .65rem;
  min-width: 0;
  padding: 1rem;
  border: 1px solid #dfe6f0;
  border-radius: .6rem;
  background: #f7f9fc;
}

.block-heading {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 1rem;
}

.block-heading h3,
.block-heading p {
  margin: 0;
}

.block-heading p,
.analysis-note {
  color: #64748b;
  font-size: .85rem;
}

.controls {
  display: flex;
  flex-wrap: wrap;
  gap: .65rem;
}

.control {
  display: grid;
  gap: .2rem;
  color: #344054;
  font-size: .82rem;
  font-weight: 600;
}

select {
  min-height: 2rem;
  padding: .2rem .45rem;
  border: 1px solid #c5ccd6;
  border-radius: .35rem;
  background: #fff;
}

.event-toggles {
  display: flex;
  flex-wrap: wrap;
  gap: .45rem 1rem;
  margin: 0;
  padding: .65rem .8rem .8rem;
  border: 1px solid #dfe6f0;
  border-radius: .5rem;
  background: #fff;
}

.event-toggles legend {
  padding: 0 .25rem;
  color: #52627a;
  font-size: .82rem;
  font-weight: 700;
}

.select-all,
.event-toggles label {
  display: inline-flex;
  align-items: center;
  gap: .35rem;
  color: #344054;
  font-size: .82rem;
}

.select-all {
  gap: .3rem;
  margin-left: .55rem;
  font-size: .78rem;
  font-weight: 600;
}

.select-all input,
.event-toggles input {
  margin: 0;
}

.event-toggles i {
  width: .65rem;
  height: .65rem;
  flex: 0 0 auto;
  border-radius: 50%;
}

.empty {
  width: 100%;
  margin: 0;
  color: #64748b;
  font-size: .85rem;
}

.analysis-note {
  display: grid;
  gap: .35rem;
  padding: .65rem .8rem;
  border-left: 3px solid #6b8eb8;
  border-radius: .25rem;
  background: #fff;
}

.analysis-note p {
  margin: 0;
  color: #64748b;
  font-size: .82rem;
  line-height: 1.45;
}

.analysis-note strong {
  color: #344054;
}

.analysis-note code {
  overflow-wrap: anywhere;
}

.analysis-note .analysis-caveat {
  color: #7a5a18;
}

code {
  color: #344054;
}

@media (max-width: 700px) {
  .block-heading {
    align-items: flex-start;
    flex-direction: column;
  }
}
</style>
