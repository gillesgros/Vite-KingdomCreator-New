<template>
  <div>
    <GridLayout :items="supplyCardsWithBaneFerrymanMouseWay" :number-of-columns="numberOfColumns" :is-vertical="true"
      class="sortable-supply-cards" :class="{ 'kingdom-supply--is-enlarged': isEnlarged }">
      <template v-slot:default="slotProps">
        <FlippingCard :card="slotProps.item" :is-vertical="true" @front-visible="handleSupplyCardFrontVisible"
          @flipping-to-back="handleSupplyCardFlippingToBack">
          <template v-slot:highlight-content>
            <div v-if="!(isSpecialCard(slotProps.item))"
              class="standard-button standard-button--is-primary standard-button--light-border"
              @click.stop="handleSpecify(slotProps.item)">
              Specify
            </div>
          </template>
          <!--<BaneCardCover v-if="isBane(slotProps.item)" />-->
          <BaneCardCover isType="Bane" v-if="isBaneCard(slotProps.item)" />
          <BaneCardCover isType="Ferryman" v-if="isFerrymanCard(slotProps.item)" />
          <BaneCardCover isType="Obelisk" v-if="isObeliskCard(slotProps.item)" />
          <BaneCardCover isType="MouseWay" v-if="isMouseWayCard(slotProps.item)" />
          <BaneCardCover isType="Riverboat" v-if="isRiverboatCard(slotProps.item)" />
          <BaneCardCover isType="ApproachingArmy" v-if="isApproachingArmyCard(slotProps.item)" />
          <BaneCardCover :is-type="traitsTitle(slotProps.item)" v-if="isTraitsCard(slotProps.item)" />
        </FlippingCard>
      </template>
    </GridLayout>
  </div>
</template>

<script lang="ts">
/* import Vue, typescript */
import { defineComponent, ref, computed, onMounted, watch, nextTick } from 'vue';
import { useI18n } from "vue-i18n";
import gsap, { Sine } from "gsap";

/* import Dominion Objects and type*/
import type { SupplyCard } from "../dominion/supply-card";
import { SupplyCardSorter } from "../utils/supply-card-sorter";
import { cancelActiveAnimations, resetCardPositions, animateCardSort, getElementIndex } from "../utils/card-animation";

/* imoprt store  */
import { usei18nStore } from "../pinia/i18n-store";
import { useRandomizerStore } from "../pinia/randomizer-store";
import { useWindowStore } from "../pinia/window-store";

/* import Components */
import BaneCardCover from "./BaneCardCover.vue";
import FlippingCard from "./FlippingCard.vue";
import GridLayout from "./GridLayout.vue";

const ANIMATION_DURATION_SEC = 0.6;
const WINDOW_RESIZE_DELAY_MSEC = 300;


export default defineComponent({
  name: 'SortableSupplyCards',
  components: {
    GridLayout,
    FlippingCard,
    BaneCardCover,
  },
  setup() {
    const { t } = useI18n()
    const windowStore = useWindowStore();
    const randomizerStore = useRandomizerStore();
    const i18nStore = usei18nStore();

    const kingdom = computed(() => randomizerStore.kingdom);
    const sortOption = computed(() => randomizerStore.settings.sortOption);
    const selection = computed(() => randomizerStore.selection);
    const HasFullScreenRequested = computed(() => randomizerStore.isFullScreen);
    const language = computed(() => i18nStore.language);
    const windowWidth = computed(() => windowStore.width);
    const isEnlarged = computed(() => windowStore.isEnlarged);


    let elementIndexMapping = new Map<number, number>();
    let kingdomId: number = -1;
    const supplyCards = ref<SupplyCard[]>([])

    let numberOfSupplyCardsLoading = 0;
    let requiresSupplyCardSort = false;
    let activeAnimations: Set<any> = new Set();
    let resizeTimerId: ReturnType<typeof setTimeout> | null = null;
    let replacingCard: SupplyCard | null = null;

    onMounted(() => {
      updateActiveSupplyCards();
    });

    const numberOfColumns = computed(() => {
      return isEnlarged.value ? 2 : windowWidth.value > 450 ? 5 : 4;
    });

    const supplyCardsWithBaneFerrymanMouseWay = computed(() => {
      return supplyCards.value;
    });

    const handleKingdomChanged = () => {
      updateActiveSupplyCards();
    }
    watch(kingdom, handleKingdomChanged)

    const handleSortOptionChanged = () => {
      requiresSupplyCardSort = true;
      attemptToAnimateSupplyCardSort();
    }
    watch(sortOption, handleSortOptionChanged)

    const handlelanguagenChanged = () => {
      requiresSupplyCardSort = true;
      attemptToAnimateSupplyCardSort();
    }
    watch(language, handlelanguagenChanged)

    const handleHasFullScreenRequested = () => {
      cancelActiveAnimations(activeAnimations);
      resetCardPositions(supplyCards.value, ".grid-layout_item", elementIndexMapping, activeAnimations);
    }
    watch(HasFullScreenRequested, handleHasFullScreenRequested)

    const handleWindowWidthChanged = () => {
      cancelActiveAnimations(activeAnimations);
      resetCardPositions(supplyCards.value, ".grid-layout_item", elementIndexMapping, activeAnimations);
      // Schedule a reset to happen again after the user finishes resizing the window to catch
      // any cases where the reset happened before the elements were fully positioned.
      if (resizeTimerId) {
        clearTimeout(resizeTimerId);
      }
      resizeTimerId = setTimeout(() => resetCardPositions(supplyCards.value, ".grid-layout_item", elementIndexMapping, activeAnimations), WINDOW_RESIZE_DELAY_MSEC)
    }
    watch(windowWidth, handleWindowWidthChanged)

    const handleNumberOfColumnsChanged = () => {
      nextTick(() => resetCardPositions(supplyCards.value, ".grid-layout_item", elementIndexMapping, activeAnimations));
    }
    watch(numberOfColumns, handleNumberOfColumnsChanged)
    const isSpecialCard = (supplyCard: SupplyCard) => {
      return isBaneCard(supplyCard) || isFerrymanCard (supplyCard) || 
          isObeliskCard (supplyCard) || isMouseWayCard (supplyCard) || 
          isRiverboatCard (supplyCard) || isApproachingArmyCard (supplyCard)
    }
    const isBaneCard = (supplyCard: SupplyCard) => {
      return kingdom.value.supply.baneCard &&
        kingdom.value.supply.baneCard.id == supplyCard.id;
    }
    const isFerrymanCard = (supplyCard: SupplyCard) => {
      return kingdom.value.supply.ferrymanCard &&
        kingdom.value.supply.ferrymanCard.id == supplyCard.id;
    };
    const isObeliskCard = (supplyCard: SupplyCard) => {
      return kingdom.value.supply.obeliskCard &&
        kingdom.value.supply.obeliskCard.id == supplyCard.id;
    }
    const isMouseWayCard = (supplyCard: SupplyCard) => {
      return kingdom.value.supply.mouseWay &&
        kingdom.value.supply.mouseWay.id == supplyCard.id;
    }
    const isRiverboatCard = (supplyCard: SupplyCard) => {
      return kingdom.value.supply.riverboatCard &&
        kingdom.value.supply.riverboatCard.id == supplyCard.id;
    }
    const isApproachingArmyCard = (supplyCard: SupplyCard) => {
      return kingdom.value.supply.approachingArmyCard &&
        kingdom.value.supply.approachingArmyCard.id == supplyCard.id;
    }
    const isTraitsCard = (supplyCard: SupplyCard) => {
      const index = kingdom.value.supply.traitsSupply.indexOf(supplyCard)
      return kingdom.value.supply.traitsSupply[index]  &&
      kingdom.value.supply.traitsSupply[index].id == supplyCard.id;
    }
    const traitsTitle = (supplyCard: SupplyCard) => {
      const index = kingdom.value.supply.traitsSupply.indexOf(supplyCard)
      return "trait#"+ kingdom.value.traits[index]!.id;
    }
    const handleSpecify = (supplyCard: SupplyCard) => {
      randomizerStore.UPDATE_SPECIFYING_REPLACEMENT_SUPPLY_CARD(supplyCard);
    }
    const handleSupplyCardFlippingToBack = (supplyCard: SupplyCard) => {
      numberOfSupplyCardsLoading += 1;
    }
    const handleSupplyCardFrontVisible = (supplyCard: SupplyCard) => {
      numberOfSupplyCardsLoading -= 1;
      attemptToAnimateSupplyCardSort();
    }
    const handleReplace = (supplyCard: SupplyCard) => {
      replacingCard = supplyCard;
    }

    const getSortedFullCards = () => {
      const fullCards = kingdom.value.supply.supplyCards.concat();
      if (kingdom.value.supply.ferrymanCard) fullCards.push(kingdom.value.supply.ferrymanCard);
      if (kingdom.value.supply.riverboatCard) fullCards.push(kingdom.value.supply.riverboatCard);
      if (kingdom.value.supply.approachingArmyCard) fullCards.push(kingdom.value.supply.approachingArmyCard);
      return SupplyCardSorter.sort(fullCards, sortOption.value, t);
    };

    const updateActiveSupplyCards = () => {
      if (!kingdom.value) return;
      kingdomId = kingdom.value.id;
      const sortedSupplyCards = getSortedFullCards();

      // Remap the sorted supply cards to where the elements currently reside.
      const mappedSupplyCards = [];
      for (let i = 0; i < sortedSupplyCards.length; i++) {
        mappedSupplyCards[getElementIndex(i, elementIndexMapping)] = sortedSupplyCards[i];
      }
      supplyCards.value = mappedSupplyCards as SupplyCard[];
    }

    const attemptToAnimateSupplyCardSort = () => {
      console.log ("attemptToAnimateSupplyCardSort");
      if (numberOfSupplyCardsLoading > 0 || !requiresSupplyCardSort) {
        return;
      }
      requiresSupplyCardSort = false;
      cancelActiveAnimations(activeAnimations);
      animateSupplyCardSort();
    }

    const animateSupplyCardSort = () => {
      console.log ("animateSupplyCard supply ", supplyCards.value)
      const sortedCards = SupplyCardSorter.sort(supplyCards.value.concat(), sortOption.value, t);
      console.log ("animateSupplyCard Sort", sortedCards)
      animateCardSort(supplyCards.value, sortedCards, ".grid-layout_item", elementIndexMapping, activeAnimations, ANIMATION_DURATION_SEC);
    }

    return {
      supplyCardsWithBaneFerrymanMouseWay,
      numberOfColumns,
      isEnlarged,
      handleSupplyCardFrontVisible,
      handleSupplyCardFlippingToBack,
      isSpecialCard,
      isBaneCard,
      isFerrymanCard,
      isObeliskCard,
      isMouseWayCard,
      isRiverboatCard,
      isApproachingArmyCard,
      isTraitsCard,
      traitsTitle,
      handleSpecify
    }
  }
})
</script>

<style scoped>
.kingdom-supply--is-enlarged .card-set-description .card-description {
  font-size: 16px !important;
}

.sortable-supply-card-copy-button {
  margin-top: 0px;
}
</style>
