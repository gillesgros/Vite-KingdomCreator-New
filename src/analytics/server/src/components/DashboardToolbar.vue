<template>
  <form class="toolbar panel" aria-label="Contrôles du tableau de bord" @submit.prevent="loadDashboard">
    <div class="source-control">
      <label for="analytics-source">Source</label>
      <select
        id="analytics-source"
        :value="draftSource"
        :disabled="sourcesLoading || sources.length === 0"
        @change="updateSource"
      >
        <option v-if="sources.length === 0" value="">
          {{ sourcesLoading ? 'Chargement des sources…' : 'Aucune source disponible' }}
        </option>
        <option v-for="item in sources" :key="item" :value="item">
          {{ item }}
        </option>
      </select>
    </div>

    <div class="token-control">
      <label for="analytics-token">Jeton administrateur</label>
      <input
        id="analytics-token"
        v-model="draftToken"
        type="password"
        autocomplete="current-password"
        placeholder="Saisir le jeton administrateur"
        required
      >
      <p v-if="authError" class="token-error" role="alert">
        {{ authError.message }}
      </p>
    </div>

    <button class="primary" type="submit" :disabled="sourcesLoading || summaryLoading">
      {{ loadedSource === draftSource ? 'Mettre à jour le dashboard' : 'Charger le dashboard' }}
    </button>
  </form>

  <p v-if="sourcesError" class="toolbar-message error" role="alert">{{ sourcesError }}</p>
  <p v-else-if="!sourcesLoading && sources.length === 0" class="toolbar-message muted">
    Aucune source enregistrée pour le moment. Une source apparaîtra après la réception de ses premiers événements.
  </p>
</template>

<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { fetchSources } from '../api/analyticsApi';

const props = defineProps<{
  source: string;
  token: string;
  summaryLoading: boolean;
  loadedSource: string;
  authError: { status: number; message: string } | null;
}>();

const emit = defineEmits<{
  'update:source': [source: string];
  'update:token': [token: string];
  refresh: [];
}>();

const sources = ref<string[]>([]);
const draftSource = ref(props.source);
const draftToken = ref(props.token);
const sourcesLoading = ref(false);
const sourcesError = ref<string | null>(null);

watch(
  () => props.token,
  (token) => {
    draftToken.value = token;
  },
);

watch(
  () => props.source,
  (source) => {
    draftSource.value = source;
  },
);

async function loadSources(): Promise<void> {
  sourcesLoading.value = true;
  sourcesError.value = null;
  try {
    const availableSources = await fetchSources();
    sources.value = availableSources;
    if (!availableSources.includes(draftSource.value)) {
      draftSource.value = availableSources[0] ?? '';
    }
  } catch (error) {
    sourcesError.value = error instanceof Error
      ? error.message
      : 'Impossible de charger la liste des sources.';
  } finally {
    sourcesLoading.value = false;
  }
}

function updateSource(event: Event): void {
  if (event.target instanceof HTMLSelectElement) {
    draftSource.value = event.target.value;
  }
}

async function loadDashboard(): Promise<void> {
  await loadSources();
  emit('update:source', draftSource.value);
  emit('update:token', draftToken.value.trim());
  emit('refresh');
}

onMounted(() => {
  void loadSources();
});
</script>

<style scoped>
.panel {
  margin-top: 1rem;
  padding: 1rem;
  border: 1px solid #d8dde5;
  border-radius: .5rem;
  background: #fff;
}

.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: end;
  gap: .75rem;
}

.source-control,
.token-control {
  display: grid;
  flex: 1 1 200px;
  gap: .25rem;
  min-width: 0;
}

label {
  display: grid;
  gap: .25rem;
  font-weight: 600;
}

select,
input {
  width: 100%;
  min-width: 0;
  min-height: 2.4rem;
  padding: .4rem .65rem;
  border: 1px solid #c5ccd6;
  border-radius: .35rem;
  background: #fff;
  color: #202735;
}

select:focus,
input:focus {
  border-color: #284f7c;
  outline: 2px solid rgb(40 79 124 / 15%);
}

.toolbar > button {
  flex: 0 0 190px;
  min-height: 2.4rem;
  padding: .4rem .65rem;
  border: 1px solid #c5ccd6;
  border-radius: .35rem;
  background: #f7f8fa;
  font: inherit;
}

.toolbar > button:hover:not(:disabled) {
  background: #e9edf2;
}

.toolbar > button.primary {
  border-color: #284f7c;
  background: #284f7c;
  color: #fff;
}

.toolbar > button.primary:hover:not(:disabled) {
  background: #1c3e64;
}

.toolbar-message {
  min-height: 1.5rem;
  margin: .75rem 0;
}

.muted {
  color: #667085;
}

.error {
  color: #b42318;
}

.token-error {
  margin: 0;
  color: #b42318;
  font-size: .85rem;
  overflow-wrap: anywhere;
}
</style>
