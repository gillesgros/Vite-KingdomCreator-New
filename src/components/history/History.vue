<template>
  <div class="history-page">
    <div class="history-header">
      <h1>{{ $t('Historique des parties') }}</h1>
      <button 
        v-if="hasHistory" 
        class="btn-danger" 
        @click="historyStore.clearAllHistory"
      >
        {{ $t('Tout supprimer') }}
      </button>
    </div>

    <!-- Chargement de l'historique -->
    <div v-if="historyStore.isLoading && !hasHistory" class="empty-history">
      <p>History loading...</p>
    </div>

    <!-- Si l'historique est vide -->
    <div v-else-if="!hasHistory" class="empty-history">
      <p>{{ $t('Aucun royaume dans l\'historique pour le moment.') }}</p>
      <router-link to="/" class="btn-primary">{{ $t('Générer un royaume') }}</router-link>
    </div>

    <!-- Liste de l'historique -->
    <div v-else class="history-list">
      <div 
        v-for="item in sortedHistory" 
        :key="item.hash" 
        class="history-item"
      >
        <div class="item-meta">
          <span class="item-date">{{ formatDate(item.timestamp) }}</span>
        </div>
        
        <div class="item-details">
          <!-- Ici on liste les cartes du royaume. On affiche l'ID traduit -->
<!--           <span class="cards-list">
            {{ getKingdomCardsText(item.hash) }}
          </span> -->
          <HistoryKingdomDetails :kingdom="getKingdom(item.hash)" />
        </div>


        <div class="item-actions">
          <!-- Bouton pour charger/rejouer ce royaume -->
          <button class="btn-secondary" @click="PlayAgainKingdom(item.hash)">
            🎯 {{ $t('Play Again') }}
          </button>
          <!-- Bouton de suppression unitaire -->
          <button class="btn-icon-danger" @click="historyStore.deleteKingdomFromHistory(item.hash)">
            🗑️
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent, computed } from 'vue';
import { useHistoryStore } from '@/pinia/history-store';
import { useRandomizerStore } from '@/pinia/randomizer-store';
import { useRoute, useRouter } from 'vue-router';

import { useI18n } from 'vue-i18n';
import { deserializeKingdomFromHash } from '@/randomizer/kingdom-hash';
import { serializeKingdom } from '@/randomizer/serializer';
import { Language } from '@/i18n/language';
import { DominionSets } from '@/dominion/dominion-sets';

import HistoryKingdomDetails from './HistoryKingdomDetails.vue';

export default defineComponent({
  name: "HistoryView",
  components: {
    HistoryKingdomDetails 
  },
  setup() {
    const historyStore = useHistoryStore();
    const randomizerStore = useRandomizerStore();
    const route = useRoute();
    const router = useRouter();
    const { t } = useI18n();

    const hasHistory = computed(() => Object.keys(historyStore.playedKingdoms).length > 0);

    // Trier l'historique du plus récent au plus ancien
    const sortedHistory = computed(() => {
      return Object.entries(historyStore.playedKingdoms)
        .map(([hash, timestamp]) => ({ hash, timestamp: timestamp as number }))
        .sort((a, b) => b.timestamp - a.timestamp);
    });

const getKingdom = (hash: string) => {
  return deserializeKingdomFromHash(hash);
};

const getKingdomCardsText = (hash: string) => {
  const kingdom = getKingdom(hash);
  if (!kingdom || !kingdom.supply || !kingdom.supply.supplyCards) {
    return t('Unknown or invalid kingdom');
  }

  const sortedCards = [...kingdom.supply.supplyCards].sort((a: any, b: any) => {
    const setOrder = (setId: string) => {
      const index = DominionSets.getAllSetsIds().indexOf(setId as any);
      return index >= 0 ? index : Number.MAX_SAFE_INTEGER;
    };

    const setDiff = setOrder(a.setId) - setOrder(b.setId);
    if (setDiff !== 0) return setDiff;

    return a.id.localeCompare(b.id);
  });

  return sortedCards.map((card: any) => t(card.id)).join(', ');
};

const PlayAgainKingdom = async (hash: string) => {
  try {
    const kingdom = deserializeKingdomFromHash(hash);
    if (!kingdom) return;

    await historyStore.addKingdomToHistory(kingdom);

    const lang = Array.isArray(route.query.lang)
      ? route.query.lang[0]
      : route.query.lang || Language.ENGLISH;

    randomizerStore.LOAD_INITIAL_KINGDOM(kingdom);

    await router.push({
      path: '/',
      query: {
        lang,
        ...serializeKingdom(kingdom)
      }
    });
  } catch (e) {
    console.error("Erreur lors du chargement du royaume à rejouer :", e);
  }
};


    const formatDate = (timestamp: number) => {
      return new Date(timestamp).toLocaleDateString(undefined, {
        day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute:'2-digit'
      });
    };

    return {
      historyStore,
      hasHistory,
      sortedHistory,
      getKingdom,
      getKingdomCardsText,
      PlayAgainKingdom,
      formatDate
    };
  }
});
</script>

<style scoped>
.history-page { padding: 20px; max-width: 800px; margin: 0 auto; }
.history-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.history-list { display: flex; flex-direction: column; gap: 12px; }
.history-item { 
  display: flex; justify-content: space-between; align-items: center;
  padding: 12px; border: 1px solid #ccc; border-radius: 6px; background: #fff;
}
.item-meta { font-size: 0.85em; color: #666; min-width: 120px; }
.item-details { flex: 1; padding: 0 15px; color: #333; font-weight: 500; }
.item-actions { display: flex; gap: 8px; }
.btn-danger { background: #d32f2f; color: white; border: none; padding: 8px 12px; border-radius: 4px; cursor: pointer; }
.btn-icon-danger { background: transparent; border: none; cursor: pointer; font-size: 1.2em; }
.btn-secondary { background: #f0f0f0; border: 1px solid #ccc; padding: 6px 12px; border-radius: 4px; cursor: pointer; }
</style>