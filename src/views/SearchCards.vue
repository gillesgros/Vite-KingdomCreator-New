<template>
  <Page :subtitle="$t('search_cards_page_subtitle')">
    <div class="content">
      <div class="sets-description">{{$t("search_page_description")}}</div>
        <SearchFilters />
      <SearchResultsDisplay :cards="filteredCards" 
        :horizontal-cards="filteredHorizontalCards"/>
    </div>
  </Page>
</template>

<script lang="ts">
import { defineComponent, computed } from 'vue';
import useBase from './base';
import Page, { MenuItemType } from '../components/Page.vue';
import SearchFilters from '../components/search/SearchFilters.vue'; 
import SearchResultsDisplay from '../components/search/SearchResultsDisplay.vue';
import { useI18n } from 'vue-i18n';
import { useSettingsStore } from '../pinia/settings-store';
import { useSearchStore } from '../pinia/search-store';

// Dominion Objects and types
import type { SetId } from '../dominion/set-id';
import { DominionSets } from '../dominion/dominion-sets';
import { CardType } from '../dominion/card-type';
import { Cards } from '../utils/cards';
import type { Card } from '../dominion/card';
import { Randomizer } from '../randomizer/randomizer';
import { OTHER_CARD_TYPES, OTHER_CARD_TYPES_HORIZONTAL } from '../utils/cards-other';
import type { OtherCard } from '../dominion/other-card';
import { SupplyCard } from '../dominion/supply-card';
import { SortOption } from '../settings/settings';
import type { Addon } from '../dominion/addon';

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

    const SearchStore = useSearchStore();

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

    const allDominionCards = () => {
      const sets = DominionSets.getAllSets().filter(set => setsToUse.value.includes(set.setId));
      let cards = Cards.getAllSupplyCards(Cards.getAllCardsFromSets(sets))
      const otherCards = Cards.getAllOtherCardsFromSets(sets);
      for (const otherCardType of OTHER_CARD_TYPES) {
        const xx = otherCards.filter((card) => ((card as OtherCard).type.includes(otherCardType.cardType)));
        cards.push(...xx as SupplyCard[]);
      }
      // to remove duplicate and avoid problem with multiple version of Set           
      cards = Randomizer.removeDuplicateCards(cards, []);
      // complete multiple version by based on id - remove duplicate
      cards = cards.filter((card, index, self) => {
        const x = card.id.replace('tohidesplitcard','')
        return index === self.findIndex((c) => c.id === x)
      });

      return cards.filter(card => !excludedCards.value.includes(card.id));
    };

    function getTypesFromCard(card: any): CardType[] {
      // Simplification dynamique : chaque valeur de CardType correspond à une propriété booléenne
      return Object.values(CardType).filter(type => card[type]);
    }

    const filteredCards = computed(() => {
      let cards = allDominionCards()

      if (SearchStore.selectedSetIds.length > 0) {
        cards = cards.filter(card => SearchStore.selectedSetIds.includes(card.setId));
      }
      if (SearchStore.searchName) {
        const lowerSearchName = SearchStore.searchName.toLowerCase();
        cards = cards.filter(card => card.name && card.name.toLowerCase().includes(lowerSearchName));
      }
      if (SearchStore.selectedCardTypes.length > 0) {
        cards = cards.filter(card =>
          SearchStore.selectedCardTypes.some(selectedType => getTypesFromCard(card).includes(selectedType))
        );
      }
      if (SearchStore.selectedCostTypes.length > 0) {
        cards = cards.filter(card => {
          if (!card.cost) return false;
          return SearchStore.selectedCostTypes.includes(card.cost.getType());
        });
      }

      switch (SearchStore.selectedSortOption) {
        case SortOption.SET:
          return cards.sort((a, b) => (a.setId || '').localeCompare(b.setId || ''));
        case SortOption.COST:
          return cards.sort((a, b) => {
            const costA = a.cost ? a.cost.treasure + a.cost.potion * 10 + a.cost.debt * 100 : 0;
            const costB = b.cost ? b.cost.treasure + b.cost.potion * 10 + b.cost.debt * 100 : 0;
            return costA - costB !== 0 ? costA- costB : (a.name || '').localeCompare(b.name || '');
          });
        case SortOption.ALPHABETICAL:
        default:
          return cards.sort((a, b) => (a.name || '').localeCompare(b.name || ''));
      }
    });

    const allHorizontalDominionCards = () => {
      const sets = DominionSets.getAllSets().filter(set => setsToUse.value.includes(set.setId));
      let cards = Cards.getAllNonSupplyCards(Cards.getAllCardsFromSets(sets));
      const otherCards = Cards.getAllOtherCardsFromSets(sets);
      for (const otherCardType of OTHER_CARD_TYPES_HORIZONTAL) {
        const xx = otherCards.filter((card) => ((card as OtherCard).type.includes(otherCardType.cardType)));
        cards.push(...xx as Card[]);
      }
      // Exclure les cartes exclues
      cards = cards.filter(card => !excludedCards.value.includes(card.id));
      // Supprimer les doublons sur l'id (en tenant compte de tohidesplitcard)
      cards = cards.filter((card, index, self) =>
        index === self.findIndex((c) =>
          c.id.replace("tohidesplitcard", "") === card.id.replace("tohidesplitcard", "")
        )
      );
      return cards;
    };

    const filteredHorizontalCards = computed(() => {
      let cards = allHorizontalDominionCards();

      if (SearchStore.selectedSetIds.length > 0) {
        cards = cards.filter(card => SearchStore.selectedSetIds.includes(card.setId));
      }
      if (SearchStore.searchName) {
        const lowerSearchName = SearchStore.searchName.toLowerCase();
        cards = cards.filter(card => card.name && card.name.toLowerCase().includes(lowerSearchName));
      }
      switch (SearchStore.selectedSortOption) {
        case SortOption.SET:
          return cards.sort((a, b) => (a.setId || '').localeCompare(b.setId || ''));
        case SortOption.COST:
          return cards.sort((a, b) => {
            const costA = (a as Addon).cost ? (a as Addon).cost.treasure + (a as Addon).cost.potion * 10 + (a as Addon).cost.debt * 100 : 0;
            const costB = (b as Addon).cost ? (b as Addon).cost.treasure + (b as Addon).cost.potion * 10 + (b as Addon).cost.debt * 100 : 0;
            return costA - costB !== 0 ? costA- costB : (a.name || '').localeCompare(b.name || '');
          });
        case SortOption.ALPHABETICAL:
        default:
          return cards.sort((a, b) => (a.name || '').localeCompare(b.name || ''));
      }
    });
  
    return {
      filteredCards,
      filteredHorizontalCards
    };
  },
});
</script>

