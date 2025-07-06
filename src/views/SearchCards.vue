<template>
  <Page :subtitle="$t('search_cards_page_subtitle')">
    <div class="content">
      <div class="sidebar">
      <div class="search-criteria-container">
        <SearchFilters
          :searchName="searchName"
          :selectedSetIds="selectedSetIds"
          :selectedCardTypes="selectedCardTypes"
          :selectedCostTypes="selectedCostTypes"
          @update:searchName="searchName = $event"
          @update:selectedSetIds="selectedSetIds = $event"
          @update:selectedCardTypes="selectedCardTypes = $event"
          @update:selectedCostTypes="selectedCostTypes = $event"
        />
      </div>
      </div>
      <SearchResultsDisplay :cards="filteredCards" />
    </div>
  </Page>
</template>

<script lang="ts">
import { defineComponent, ref, computed } from 'vue';
import useBase from "./base";
import Page, { MenuItemType } from "../components/Page.vue";
import SearchFilters from "../components/SearchFilters.vue"; 
import SearchResultsDisplay from "../components/SearchResultsDisplay.vue";
import { useI18n } from 'vue-i18n';
import { useSettingsStore } from "../pinia/settings-store";

// Dominion Objects and types
import type { SetId } from "../dominion/set-id";
import { DominionSets } from "../dominion/dominion-sets";
import { CardType } from "../dominion/card-type";
import { CostType } from "../dominion/cost-type";
import { Cards } from "../utils/cards";
import { Randomizer } from "../randomizer/randomizer";
import { Cost } from "../dominion/cost";
import { VISIBLE_COSTS } from '../dominion/cost-type';
import { VISIBLE_CARD_TYPES } from '../dominion/card-type'

export default defineComponent({
  name: "SearchCards",
  components: {
    Page,
    SearchFilters,
    SearchResultsDisplay,
  },
  setup() {
    useBase();
    const { t } = useI18n();
    const SettingsStore = useSettingsStore();

    const searchName = ref('');
    const selectedSetIds = ref<SetId[]>([]);
    const selectedCardTypes = ref<CardType[]>([]);
    const selectedCostTypes = ref<CostType[]>([]);

    const setsToUse = computed<SetId[]>(() => {
      if (SettingsStore.isUsingOnlyOwnedsets && SettingsStore.ownedSets.length > 0) {
        return SettingsStore.ownedSets as SetId[];
      }
      // Si aucun paramètre custom, on prend tous les sets
      return DominionSets.getAllSets().map(set => set.setId) as SetId[];
    });
    const excludedCards = computed(() => {
      return setsToUse.value.flatMap(setId => (SettingsStore.getSetConstraints(setId)?.excludedCards ?? []));
    });

    const allDominionCards = computed(() => {
      const sets = DominionSets.getAllSets().filter(set => setsToUse.value.includes(set.setId));
      const cards = Cards.getAllSupplyCards(Cards.getAllCardsFromSets(sets));
      return cards.filter(card => !excludedCards.value.includes(card.id));
    });

    function getTypesFromCard(card: any): CardType[] {
      // Simplification dynamique : chaque valeur de CardType correspond à une propriété booléenne
      return Object.values(CardType).filter(type => card[type]);
    }

    const filteredCards = computed(() => {
      let cards = allDominionCards.value;
      console.log("cards in filteredcards:", cards)

      if (selectedSetIds.value.length > 0) {
        cards = cards.filter(card => selectedSetIds.value.includes(card.setId));
      }

      if (searchName.value) {
        const lowerSearchName = searchName.value.toLowerCase();
        cards = cards.filter(card => card.name && card.name.toLowerCase().includes(lowerSearchName));
      }

      if (selectedCardTypes.value.length > 0) {
        cards = cards.filter(card =>
          selectedCardTypes.value.some(selectedType => getTypesFromCard(card).includes(selectedType))
        );
      }

      if (selectedCostTypes.value.length > 0) {
        cards = cards.filter(card => {
          if (!card.cost) return false;
          return selectedCostTypes.value.includes(card.cost.getType());
        });
      }

      // to remove duplicate and avoid problem with multiple version of Set           
      return Randomizer.removeDuplicateCards(cards, []).sort((a, b) => (a.name || '').localeCompare(b.name || ''));
    });

    return {
      searchName,
      selectedSetIds,
      selectedCardTypes,
      selectedCostTypes,
      filteredCards,
    };
  },
});
</script>

<style scoped>
.content-wrapper {
  display: flex;
  flex-direction: column; /* Default: criteria on top, results below for mobile */
  gap: 20px;
  padding: 20px;
  width: 100%;
}

.search-criteria-container {
  /* This container helps encapsulate the filters and can be styled independently */
  flex-shrink: 0; /* Prevent it from shrinking */
}

/* Mobile layout: criteria on top */
@media (max-width: 768px) {
  .content-wrapper {
    flex-direction: column; /* Sur mobile, les critères en haut et les résultats en bas */
    gap: 20px;
  }

  .search-criteria-container {
    width: 100%; /* Les critères prennent toute la largeur */
    position: static; /* Pas de position sticky sur mobile */
  }

  .search-results {
    margin-right: 0; /* Pas d'espace entre les critères et les résultats */
  }
}

/* Desktop/Tablet layout: criteria on the right */
@media (min-width: 769px) {
  .content-wrapper {
    flex-direction: row; /* Sur ordinateur, les critères à droite et les résultats à gauche */
    align-items: flex-start; /* Alignement en haut */
  }

  .search-criteria-container {
    order: 2; /* Les critères à droite */
    width: 300px; /* Largeur fixe pour la barre latérale des critères */
    position: sticky; /* Les critères restent visibles lors du défilement */
    top: 20px; /* Décalage par rapport au haut */
  }

  .search-results {
    order: 1; /* Les résultats à gauche */
    flex-grow: 1; /* Les résultats prennent l'espace restant */
    margin-right: 20px; /* Espace entre les critères et les résultats */
  }
}
</style>