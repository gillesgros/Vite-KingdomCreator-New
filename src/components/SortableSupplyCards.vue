<template>
  <div>
    <GridLayout :items="supplyCardsWithBane" :number-of-columns="numberOfColumns" :is-vertical="true"
      class="sortable-supply-cards" :class="{ 'kingdom-supply--is-enlarged': isEnlarged }">
      <template v-slot:default="slotProps">
        <FlippingCard :card="slotProps.item" :is-vertical="true" @front-visible="handleSupplyCardFrontVisible"
          @flipping-to-back="handleSupplyCardFlippingToBack">
          <template v-slot:highlight-content>
            <div v-if="!isBaneCard(slotProps.item)"
              class="standard-button standard-button--is-primary standard-button--light-border"
              @click.stop="handleSpecify(slotProps.item)">
              Specify
            </div>
          </template>
          <!--<BaneCardCover v-if="isBane(slotProps.item)" />-->
          <BaneCardCover isType="Bane" v-if="isBaneCard(slotProps.item)" />
          <BaneCardCover isType="Obelisk" v-if="isObeliskCard(slotProps.item)" />
        </FlippingCard>
      </template>
    </GridLayout>
  </div>
</template>

<script lang="ts">
import FlippingCard from "./FlippingCard.vue";
import BaneCardCover from "./BaneCardCover.vue";
import type { Coordinate } from "../utils/coordinate";
import type { SupplyCard } from "../dominion/supply-card";
import type { SortOption } from "../settings/settings";
import type { Kingdom } from "../randomizer/kingdom";
import { SupplyCardSorter } from "../utils/supply-card-sorter";
import { gsap, Sine } from "gsap";
import GridLayout from "./GridLayout.vue";
import { defineComponent, computed, ref, onMounted, watch, nextTick } from 'vue';

import { usei18nStore } from "../pinia/i18n-store";
import { useRandomizerStore } from "../pinia/randomizer-store";
import { useWindowStore } from "../pinia/window-store";
import { useI18n } from "vue-i18n";

interface MoveDescriptor {
  elementIndex: number;
  newVisualIndex: number;
}

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

    const kingdom = ref(randomizerStore.kingdom);
    const sortOption = ref(randomizerStore.settings.sortOption);
    const selection = ref(randomizerStore.selection);
    const HasFullScreenRequested = ref(randomizerStore.isFullScreen);
    const language = ref(i18nStore.language);
    const windowWidth = ref(windowStore.width);
    const isEnlarged = ref(windowStore.isEnlarged);


    let elementIndexMapping = new Map<number, number>();
    let kingdomId: number = 0;
    let supplyCards: SupplyCard[] = [];
    let numberOfSupplyCardsLoading = 0;
    let requiresSupplyCardSort = false;
    let activeAnimations: Set<TweenLite> = new Set();
    let resizeTimerId: ReturnType<typeof setTimeout> | null = null;
    let replacingCard: SupplyCard | null = null;

    onMounted(() => {
      updateActiveSupplyCards();
    });

    const numberOfColumns = computed(() => {
      return isEnlarged.value ? 2 : windowWidth.value > 450 ? 5 : 4;
    });

    const supplyCardsWithBane = () => {
      //const cards =  SupplyCardSorter.sort(this.supplyCards.concat() as SupplyCard[], this.sortOption, this.$t.bind(this));
      const cards = supplyCards.concat();
      if (kingdom.value.supply.baneCard) {
        cards.push(kingdom.value.supply.baneCard);
      }
      /*if (this.kingdom.supply.obeliskCardId) {
        cards.push(this.kingdom.supply.obeliskCardId);
      }*/
      return cards;
    }

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
      cancelActiveAnimations();
      resetCardPositions();
    }
    watch(HasFullScreenRequested, handleHasFullScreenRequested)

    const handleWindowWidthChanged = () => {
      cancelActiveAnimations();
      resetCardPositions();

      // Schedule a reset to happen again after the user finishes resizing the window to catch
      // any cases where the reset happened before the elements were fully positioned.
      if (resizeTimerId) {
        clearTimeout(resizeTimerId);
      }
      resizeTimerId = setTimeout(() => resetCardPositions(), WINDOW_RESIZE_DELAY_MSEC)
    }
    watch(windowWidth, handleWindowWidthChanged)

    const handleNumberOfColumnsChanged = () => {
      nextTick(() => resetCardPositions());
    }
    watch(numberOfColumns, handleNumberOfColumnsChanged)

    const isBaneCard = (supplyCard: SupplyCard) => {
      return kingdom.value.supply.baneCard &&
        kingdom.value.supply.baneCard.id == supplyCard.id;
    }

    const isObeliskCard = (supplyCard: SupplyCard) => {
      return kingdom.value.supply.obeliskCard &&
        kingdom.value.supply.obeliskCard.id == supplyCard.id;
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

    const updateActiveSupplyCards = () => {
      if (!kingdom) {
        return;
      }
      if (kingdomId == kingdom.value.id) {
        updateSupplyCards();
        return;
      }
      kingdomId = kingdom.value.id;
      const sortedSupplyCards =
        SupplyCardSorter.sort(kingdom.value.supply.supplyCards.concat(), sortOption.value, t);

      // Remap the sorted supply cards to where the elements currently reside.
      const mappedSupplyCards = [];
      for (let i = 0; i < sortedSupplyCards.length; i++) {
        mappedSupplyCards[getElementIndex(i)] = sortedSupplyCards[i];
      }
      supplyCards = mappedSupplyCards;
    }

    const updateSupplyCards = () => {
      requiresSupplyCardSort = true;
      supplyCards = replaceSupplyCards(
        supplyCards, kingdom.value.supply.supplyCards);
    }

    const attemptToAnimateSupplyCardSort = () => {
      if (numberOfSupplyCardsLoading > 0 || !requiresSupplyCardSort) {
        return;
      }
      requiresSupplyCardSort = false;
      cancelActiveAnimations();
      animateSupplyCardSort();
    }

    const resetCardPositions = () => {
      for (let visualIndex = 0; visualIndex < supplyCards.length; visualIndex++) {
        const elementIndex = getElementIndex(visualIndex);
        const element = getSupplyCardElement(elementIndex);
        const startCoord = getPositionForElementIndex(elementIndex);
        const endCoord = getPositionForElementIndex(visualIndex);
        const x = endCoord.x - startCoord.x;
        const y = endCoord.y - startCoord.y;
        element.style.transform = `translate(${x}px,${y}px)`;
      }
    }

    const cancelActiveAnimations = () => {
      for (let animation of activeAnimations) {
        animation.kill();
      }
      activeAnimations.clear();
    }

    const animateSupplyCardSort = () => {
      const sortedCards = SupplyCardSorter.sort(supplyCards.concat(), sortOption.value, t);
      const descriptors = createMoveDescriptors(sortedCards);
      const newMapping: Map<number, number> = new Map();

      for (let descriptor of descriptors) {
        const element = getSupplyCardElement(descriptor.elementIndex);
        const startCoord = getPositionForElementIndex(descriptor.elementIndex);
        const endCoord = getPositionForElementIndex(descriptor.newVisualIndex);
        const x = endCoord.x - startCoord.x;
        const y = endCoord.y - startCoord.y;
        const tweenLite =
          gsap.to(element, {
            duration: ANIMATION_DURATION_SEC,
            transform: `translate(${x}px,${y}px)`,
            ease: Sine.easeInOut,
            onComplete: function () {
              tweenLite.kill
              return;
            }
          }
          ) as TweenLite;

        activeAnimations.add(tweenLite);
        newMapping.set(descriptor.newVisualIndex, descriptor.elementIndex);
      }
      elementIndexMapping = newMapping;
    }

    const createMoveDescriptors = (sortedSupplyCards: SupplyCard[]) => {
      const cardIds = supplyCards.map((card) => card.id);
      const descriptors: MoveDescriptor[] = [];
      for (let newVisualIndex = 0; newVisualIndex < sortedSupplyCards.length; newVisualIndex++) {
        descriptors.push({
          newVisualIndex: newVisualIndex,
          elementIndex: cardIds.indexOf(sortedSupplyCards[newVisualIndex].id),
        });
      }
      return descriptors;
    }

    const getPositionForElementIndex = (index: number): Coordinate => {
      const container = getSupplyCardContainers()[index];
      return { x: container.offsetLeft, y: container.offsetTop };
    }

    const getSupplyCardElement = (index: number) => {
      return getSupplyCardContainers()[index].firstChild! as HTMLElement;
    }

    const getSupplyCardContainers = () => {
      return document.querySelectorAll(".grid-layout_item") as NodeListOf<HTMLElement>;
    }

    const getElementIndex = (visualIndex: number) => {
      return elementIndexMapping.has(visualIndex)
        ? elementIndexMapping.get(visualIndex)!
        : visualIndex;
    }

    const replaceSupplyCards = (oldSupplyCards: SupplyCard[], newSupplyCards: SupplyCard[]) => {
      const supplyCards: SupplyCard[] = [];
      const supplyCardsToAdd = getSupplyCardsToAdd(oldSupplyCards, newSupplyCards);
      const oldIds = getOldIds(oldSupplyCards, newSupplyCards);
      let supplyCardsToAddIndex = 0;
      for (let i = 0; i < oldSupplyCards.length; i++) {
        const supplyCard = oldSupplyCards[i];
        if (oldIds.has(supplyCard.id)) {
          supplyCards.push(supplyCardsToAdd[supplyCardsToAddIndex]);
          supplyCardsToAddIndex += 1;
        } else {
          supplyCards.push(supplyCard);
        }
      }
      return supplyCards;
    }

    const getSupplyCardsToAdd = (oldSupplyCards: SupplyCard[], newSupplyCards: SupplyCard[]) => {
      const existingIds = new Set(oldSupplyCards.map((card) => card.id));
      return newSupplyCards.filter((card) => !existingIds.has(card.id));
    }

    const getOldIds = (oldSupplyCards: SupplyCard[], newSupplyCards: SupplyCard[]) => {
      const newIds = new Set(newSupplyCards.map((card) => card.id));
      return new Set(oldSupplyCards.filter((card) => !newIds.has(card.id)).map((card) => card.id));
    }
    return{
      supplyCardsWithBane,
      numberOfColumns,
      isEnlarged,
      handleSupplyCardFrontVisible,
      handleSupplyCardFlippingToBack,
      isBaneCard,
      isObeliskCard,
      handleSpecify
    }
  }
})
</script>

<style>
.kingdom-supply--is-enlarged .card-set-description .card-description {
  font-size: 16px !important;
}

.sortable-supply-card-copy-button {
  margin-top: 0px;
}

</style>