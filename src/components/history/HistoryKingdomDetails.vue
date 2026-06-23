<template>
  <div class="kingdom-details-display">
    <!-- 1. Affichage des cartes groupées par Set -->
    <div v-for="group in groupedCards" :key="group.setId" class="set-row">
      <span class="set-name">{{ $t(group.setId) }} :</span>
      <div class="cards-group">
        <div 
          v-for="card in group.cards" 
          :key="card.id" 
          class="card-hover-container"
        >
          <span class="card-inline-name">{{ $t(card.id) }}</span>
          <div class="card-preview-tooltip">
            <img :src="cardImageUrl(card.id)" :alt="card.id" class="preview-img" />
          </div>
        </div>
      </div>
    </div>

    <!-- 2. Affichage des Addons, Allies & Prophecies (seulement s'il y en a) -->
        <div v-for="group in groupedAddons" :key="group.type" class="addons-row">
      <span class="addons-label">{{ $t(group.type) }} :</span>
      <div class="cards-group">
        <div 
          v-for="addon in group.items" 
          :key="addon.id" 
          class="card-hover-container"
        >
          <span class="card-inline-name addon-style">{{ $t(addon.id) }}</span>
          <div class="card-preview-tooltip">
            <img :src="cardImageUrl(addon.id)" :alt="addon.id" class="preview-img addon-preview-img" />
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent, computed } from 'vue';
import type { PropType } from 'vue';
import { Kingdom } from '@/randomizer/kingdom';
import { getCardImageUrl } from '@/utils/resources';
import { usei18nStore } from '@/pinia/i18n-store';

export default defineComponent({
  name: "HistoryKingdomDetails",
  props: {
    kingdom: {
      type: Object as PropType<Kingdom>,
      required: true
    }
  },
  setup(props) {
    const i18nStore = usei18nStore();
    const language = computed(() => i18nStore.language);

    // Regrouper les Supply Cards par leur Set d'origine
    const groupedCards = computed(() => {
      if (!props.kingdom?.supply?.supplyCards) return [];
      
      const maps: Record<string, any[]> = {};
      
      props.kingdom.supply.supplyCards.forEach(card => {
        const setId = card.setId || 'unknown';
        if (!maps[setId]) maps[setId] = [];
        maps[setId].push(card);
      });

      return Object.entries(maps).map(([setId, cards]) => ({
        setId,
        cards
      }));
    });

    const cardImageUrl = (id: string) => {
      return getCardImageUrl(id.replace("tohidesplitcard", ""), language.value);
    };

    // 🎯 Transformation des addons en tableau d'objets typés pour le v-for
    const groupedAddons = computed(() => {
        const sections: { type: string; items: { id: string }[] }[] = []; 
        const k = props.kingdom;
      if (!k) return [];

if (k.events?.length) sections.push({ type: 'Events', items: k.events.map(e => ({ id: e.id })) });
      if (k.landmarks?.length) sections.push({ type: 'Landmarks', items: k.landmarks.map(l => ({ id: l.id })) });
      if (k.projects?.length) sections.push({ type: 'Projects', items: k.projects.map(p => ({ id: p.id })) });
      if (k.ways?.length) sections.push({ type: 'Ways', items: k.ways.map(w => ({ id: w.id })) });
      if (k.traits?.length) sections.push({ type: 'Traits', items: k.traits.map(t => ({ id: t.id })) });
      if (k.ally) sections.push({ type: 'Ally', items: [{ id: k.ally.id }] });
      if (k.prophecy) sections.push({ type: 'Prophecy', items: [{ id: k.prophecy.id }] });

      return sections;
    });

    return {
      groupedCards,
      groupedAddons,
      cardImageUrl
    };
  }
});
</script>

<style scoped>
.kingdom-details-display {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.set-row, .addons-row {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  font-size: 0.95rem;
}
.set-name, .addons-label {
  font-weight: bold;
  min-width: 140px;
  color: #02779e;
}
.cards-group {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.card-inline-name {
  background: #f5f5f5;
  padding: 2px 6px;
  border-radius: 4px;
  border: 1px solid #ddd;
  display: inline-block;
  transition: background-color 0.2s;
}
.card-inline-name:hover {
  background-color: #e2f0f5;
  border-color: #02779e;
}

/* Style légèrement distinct pour les addons (italique ou bordure différente si désiré) */
.addon-style {
  font-style: italic;
  background: #fafafa;
}

/* --- Système de Tooltip --- */
.card-hover-container {
  position: relative;
  display: inline-block;
}

/* Conteneur de l'image (caché par défaut) */
.card-preview-tooltip {
  visibility: hidden;
  opacity: 0;
  position: absolute;
  left: 50%;
  transform: translateX(-50%);
  z-index: 100; 
  transition: opacity 0.15s ease, visibility 0.15s ease;
  pointer-events: none; /* Évite que l'image bloque la souris */
  
  /* Bel effet d'ombrage */
  filter: drop-shadow(0px 8px 20px rgba(0,0,0,0.4)); 
}

/* Taille maximale pour l'image Dominion afin qu'elle reste discrète mais lisible */
.preview-img {
  width: 180px; 
  height: auto;
  border-radius: 8px;
  display: block;
}

.addon-preview-img {
  width: auto; 
  height: 180px; 
}

/* Déclenchement de l'affichage au survol du conteneur */
.card-hover-container:hover .card-preview-tooltip {
  visibility: visible;
  opacity: 1;
}
</style>