<template>
  <main class="dashboard">
    <header class="page-header">
      <h1>Suivi Avancé — Dominion Randomizer</h1>
      <p class="subtitle">
        Analyse hiérarchique du trafic (Type → Région → Pays → Subdivision),
        événements filtrés et statistiques d’extensions.
      </p>
    </header>

    <DashboardToolbar
      v-model:source="source"
      v-model:token="token"
      :summary-loading="logsLoading || summaryLoading"
      :auth-error="authError"
      @refresh="refreshDashboard"
    />

    <p v-if="!source" class="dashboard-status" role="status">
      Sélectionnez une source pour charger le dashboard.
    </p>
    <p v-else-if="!token" class="dashboard-status" role="status">
      Saisissez le jeton administrateur pour charger les données.
    </p>

    <nav v-if="source && token" class="view-tabs" aria-label="Navigation du tableau de bord">
      <button
        type="button"
        :aria-pressed="activeView === 'logs'"
        :class="{ active: activeView === 'logs' }"
        @click="activeView = 'logs'"
      >
        Carte et catégories de trafic
      </button>
      <button
        type="button"
        :aria-pressed="activeView === 'summary'"
        :class="{ active: activeView === 'summary' }"
        @click="activeView = 'summary'"
      >
        Synthèse — statistiques d’utilisation des extensions
      </button>
    </nav>

    <LogsExplorerView
      v-if="source && token && activeView === 'logs'"
      :source="source"
      :token="token"
      :refresh-key="refreshKey"
      @loading-change="logsLoading = $event"
      @maintenance-complete="refreshDashboard"
      @auth-error="showAuthError"
    />

    <AnalyticsSummaryView
      v-if="source && token && activeView === 'summary'"
      :source="source"
      :token="token"
      :refresh-key="refreshKey"
      @loading-change="summaryLoading = $event"
      @auth-error="showAuthError"
    />

  </main>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue';
import AnalyticsSummaryView from './components/AnalyticsSummaryView.vue';
import DashboardToolbar from './components/DashboardToolbar.vue';
import LogsExplorerView from './components/LogsExplorerView.vue';

const source = ref('');
const token = ref('');
const refreshKey = ref(0);
const logsLoading = ref(false);
const summaryLoading = ref(false);
const authError = ref<{ status: number; message: string } | null>(null);
const activeView = ref<'summary' | 'logs'>('logs');

function refreshDashboard(): void {
  authError.value = null;
  refreshKey.value++;
}

function showAuthError(error: { status: number; message: string }): void {
  authError.value = error;
}

watch(source, () => {
  authError.value = null;
});
</script>

<style>
:root {
  color-scheme: light;
  font: 15px/1.5 system-ui, sans-serif;
  color: #202735;
  background: #f5f6f8;
  font-synthesis: none;
  text-rendering: optimizeLegibility;
}

* {
  box-sizing: border-box;
}

body {
  min-width: 320px;
  min-height: 100vh;
  margin: 0;
  padding: 1.5rem;
}

button,
input,
select {
  font: inherit;
}

button {
  cursor: pointer;
}

button:disabled,
select:disabled {
  cursor: not-allowed;
  opacity: .55;
}

.dashboard {
  width: 100%;
  max-width: 1800px;
  margin: 0 auto;
}

.page-header {
  margin-bottom: 1rem;
}

.page-header h1 {
  margin: 0 0 .25rem;
  font-size: 1.7rem;
}

.subtitle,
.dashboard-status {
  margin: .25rem 0;
  color: #667085;
}

.dashboard-status {
  min-height: 1.5rem;
}

.view-tabs {
  display: flex;
  gap: .35rem;
  margin-top: 1.25rem;
  border-bottom: 1px solid #d8dde5;
}

.view-tabs button {
  margin-bottom: -1px;
  padding: .6rem 1rem;
  border: 1px solid transparent;
  border-radius: .4rem .4rem 0 0;
  background: transparent;
  color: #52627a;
  font-size: 1.25rem;
  font-weight: 700;
  line-height: 1.2;
}

.view-tabs button:hover {
  background: #e9edf2;
}

.view-tabs button.active {
  border-color: #284f7c;
  background: #284f7c;
  color: #fff;
  box-shadow: 0 2px 6px rgb(40 79 124 / 24%);
}

.view-tabs button.active:hover {
  background: #1c3e64;
}

.view-tabs button:focus-visible {
  outline: 3px solid #78a8e0;
  outline-offset: 2px;
}

@media (max-width: 600px) {
  body {
    padding: .75rem;
  }

  .page-header h1 {
    font-size: 1.4rem;
  }
}
</style>
