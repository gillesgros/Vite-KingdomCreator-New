<template>
  <Page :subtitle="$t('search_cards_page_subtitle')">
    <div class="content">
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

