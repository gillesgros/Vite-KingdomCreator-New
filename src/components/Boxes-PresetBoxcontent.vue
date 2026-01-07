<template>
  <div>
    <div v-for="set in sets" :set="set">
      <div class="preset-kingdom_title">
        <div class="preset-kingdom_title_name">{{ $t(set.setId) }}</div>
      </div>

      <!-- Supply Cards -->
      <GenericLayout :items="displayedCards" :title="$t('Kingdoms Cards')" 
        :shape="Shape.CARD" :showOverlay="OverlayCheck" :generic-nb-columns="numberOfColumnsForSupplyCards" :is-vertical="true">
        <template #card="{ item }">
          <FlippingCard :card="item" :is-vertical="true" :show-overlay="shouldShowOverlay(item)">
            <template #highlight-content>
              <!-- highlight actions for Boxes can be added here -->
            </template>
          </FlippingCard>
        </template>
      </GenericLayout>
      <!-- generic slot : Events -->
      <GenericLayout :items="getCards(set.events.concat(getOtherCards(set, 'Events') as any []))" :title="$t('Events')" 
        :shape="Shape.CARD" :showOverlay="OverlayCheck" :generic-nb-columns="numberOfColumnsForAddons" :is-vertical="false" />
      <!-- generic slot : Landmarks -->
      <GenericLayout :items="getCards(set.landmarks.concat(getOtherCards(set, 'Landmarks') as any []))" :title="$t('Landmarks')" 
        :shape="Shape.CARD" :showOverlay="OverlayCheck" :generic-nb-columns="numberOfColumnsForAddons" :is-vertical="false" />
      <!-- generic slot : Projects -->
      <GenericLayout :items="getCards(set.projects.concat(getOtherCards(set, 'Projects') as any []))" :title="$t('Projects')" 
        :shape="Shape.CARD" :showOverlay="OverlayCheck" :generic-nb-columns="numberOfColumnsForAddons" :is-vertical="false" />
      <!-- generic slot : Ways -->
      <GenericLayout :items="getCards(set.ways.concat(getOtherCards(set, 'Ways') as any []))" :title="$t('Ways')"
        :shape="Shape.CARD" :showOverlay="OverlayCheck" :generic-nb-columns="numberOfColumnsForAddons" :is-vertical="false" />
      <!-- generic slot : Boons -->
      <GenericLayout :items="getCards(set.boons.concat(getOtherCards(set, 'Boons') as any []))" :title="$t('Boons')"
        :shape="Shape.CARD" :showOverlay="OverlayCheck" :generic-nb-columns="numberOfColumnsForAddons" :is-vertical="false" />
      <!-- generic slot : Allies -->
      <GenericLayout :items="getCards(set.allies.concat(getOtherCards(set, 'Allies') as any []))" :title="$t('Allies')"
        :shape="Shape.CARD" :showOverlay="OverlayCheck" :generic-nb-columns="numberOfColumnsForAddons" :is-vertical="false" />
      <!-- generic slot : Traits -->
      <GenericLayout :items="getCards(set.traits.concat(getOtherCards(set, 'Traits') as any []))" :title="$t('Traits')"
        :shape="Shape.CARD" :showOverlay="OverlayCheck" :generic-nb-columns="numberOfColumnsForAddons" :is-vertical="false" />
      <!-- generic slot : Prophecies -->
      <GenericLayout :items="getCards(set.prophecies.concat(getOtherCards(set, 'Prophecies') as any []))" :title="$t('Prophecies')"
        :shape="Shape.CARD" :showOverlay="OverlayCheck" :generic-nb-columns="numberOfColumnsForAddons" :is-vertical="false" />

      <!-- otherCards : Basic Supply Cards, Ruins, Shelters, Non-Supply, Travellers, Artefacts, Hexes, -->
      <GenericLayout v-for="card in GetOtherCardTypes('vertical')" :key="card.cardType"
        :items="getCards(getOtherCards(set, card.cardType), challenge_sortBoxesSet(card.cardType))" :title="$t(card.title)"
        :shape="Shape.CARD" :showOverlay="OverlayCheck" :generic-nb-columns="numberOfColumnsForSupplyCards"
        :is-vertical="true" />

      <GenericLayout v-for="card in GetOtherCardTypes('horizontal')" :key="card.cardType"
        :items="getCards(getOtherCards(set, card.cardType))" :title="$t(card.title)" :shape="Shape.CARD"
        :showOverlay="OverlayCheck" :generic-nb-columns="numberOfColumnsForAddons" :is-vertical="false" />

      <GenericLayout v-for="card in GetOtherCardTypes('verticalMat')" :key="card.cardType"
        :items="getCards(getOtherCards(set, card.cardType))" :title="$t(card.title)" :shape="Shape.CARD"
        :showOverlay="OverlayCheck" :generic-nb-columns=3 :is-vertical="true" />

      <GenericLayout v-for="card in GetOtherCardTypes('horizontalMat')" :key="card.cardType"
        :items="getCards(getOtherCards(set, card.cardType))" :title="$t(card.title)" :shape="Shape.CARD"
        :showOverlay="OverlayCheck" :generic-nb-columns=2 :is-vertical="false" />

      <GenericLayout v-for="card in GetOtherCardTypes('squareMat')" :key="card.cardType"
        :items="getCards(getOtherCards(set, card.cardType))" :title="$t(card.title)"
        :shape="getshapeofmat(card.cardType)" :showOverlay="OverlayCheck" :generic-nb-columns=3 :is-vertical="false" />
    </div>
  </div>
</template>
 
<script lang="ts">
/* import Vue, typescript */
import { defineComponent, computed, ref, onMounted, watch, nextTick } from 'vue';
import { useI18n } from "vue-i18n";

/* import Dominion Objects and type*/
import type { DominionSet } from "../dominion/dominion-set";
import { DominionSets } from "../dominion/dominion-sets";
import type { SupplyCard } from "../dominion/supply-card";
import { SupplyCardSorter } from "../utils/supply-card-sorter";
import { ShowOverlayOptions } from '../utils/resources'; 
import { OTHER_CARD_TYPES, OTHER_CARD_TYPES_HORIZONTAL, 
          OTHER_CARD_TYPES_MAT_HORIZONTAL, OTHER_CARD_TYPES_MAT, 
          OTHER_CARD_TYPES_MAT_SQUARE } from '../utils/cards-other' 
import { getOtherCards } from '../utils/cards-other'
import gsap, { Sine } from 'gsap';

/* import store  */
import { useWindowStore } from '../pinia/window-store';
import { useSetsStore } from '../pinia/sets-store';
import { usei18nStore } from '../pinia/i18n-store';
import { Language } from '../i18n/language';
import { IMAGES_MISSING_FROM_TRANSLATIONS, LANGUAGES_WITH_TRANSLATED_CARDS } from '../dominion/set-id';
import { SortOption } from "../settings/settings";

/* import Components */
import GenericLayout from "./GenericLayout.vue";
import FlippingCard from "./FlippingCard.vue";
import { Shape as shapeFromGridLayout } from "./GridLayout.vue";

const FOUR_COLUMN_SUPPLY_CARD_WIDTH = 450;
const TWO_COLUMN_ADDON_WIDTH = 525;


export default defineComponent({
  name: 'PresetBoxContent',
  components: {
    GenericLayout,
    FlippingCard,
  },
  setup() {
    const windowStore = useWindowStore();
    const setsStore = useSetsStore();
    const i18nStore = usei18nStore();
    const { t } = useI18n();
    const language = computed(() => i18nStore.language);

    const Shape = shapeFromGridLayout
    const sets = computed(() => {
      return [(DominionSets.sets[setsStore.selectedBoxesSetId] as DominionSet)];
    });

    const displayedCards = ref<SupplyCard[]>([]);

    let elementIndexMapping = new Map<number, number>();
    let requiresSort = false;
    let activeAnimations: Set<any> = new Set();

    const getSupplyCardContainers = () => {
      return document.querySelectorAll('.grid-layout_item') as NodeListOf<HTMLElement>;
    };

    const getSupplyCardElement = (index: number) => {
      return getSupplyCardContainers()[index]!.firstElementChild! as HTMLElement;
    };

    const getPositionForElementIndex = (index: number) => {
      const container = getSupplyCardContainers()[index];
      return { x: container!.offsetLeft, y: container!.offsetTop };
    };

    const getElementIndex = (visualIndex: number) => {
      return elementIndexMapping.has(visualIndex) ? elementIndexMapping.get(visualIndex)! : visualIndex;
    };

    const cancelActiveAnimations = () => {
      for (const animation of activeAnimations) {
        animation.kill();
      }
      activeAnimations.clear();
    };

    const resetCardPositions = () => {
      for (let visualIndex = 0; visualIndex < displayedCards.value.length; visualIndex++) {
        const elementIndex = getElementIndex(visualIndex);
        const element = getSupplyCardElement(elementIndex);
        const startCoord = getPositionForElementIndex(elementIndex);
        const endCoord = getPositionForElementIndex(visualIndex);
        const x = endCoord.x - startCoord.x;
        const y = endCoord.y - startCoord.y;
        const activeAnimation = gsap.to(element, {
          duration: 0.6,
          x: x,
          y: y,
          ease: Sine.easeInOut,
          onComplete: function () {
            activeAnimation.kill;
            return;
          }
        });
        activeAnimations.add(activeAnimation);
      }
    };

    const createMoveDescriptors = (sortedCards: SupplyCard[]) => {
      const cardIds = displayedCards.value.map((card) => (card ? card.id : ""));
      const descriptors: { elementIndex: number; newVisualIndex: number }[] = [];
      for (let newVisualIndex = 0; newVisualIndex < sortedCards.length; newVisualIndex++) {
        descriptors.push({
          newVisualIndex: newVisualIndex,
          elementIndex: cardIds.indexOf(sortedCards[newVisualIndex]!.id),
        });
      }
      return descriptors;
    };

    const animateSupplyCardSort = () => {
      const setObj = sets.value[0];
      if (!setObj) return;
      const sourceCards = setObj.supplyCards.concat(getOtherCards(setObj, 'Normal Supply Cards') as any[]);
      const sortedCards = getCards(sourceCards);
      const descriptors = createMoveDescriptors(sortedCards);
      const newMapping: Map<number, number> = new Map();

      for (let descriptor of descriptors) {
        const element = getSupplyCardElement(descriptor.elementIndex);
        const startCoord = getPositionForElementIndex(descriptor.elementIndex);
        const endCoord = getPositionForElementIndex(descriptor.newVisualIndex);
        const x = endCoord.x - startCoord.x;
        const y = endCoord.y - startCoord.y;
        let activeAnimation = gsap.to(element, {
          duration: 0.6,
          x: x,
          y: y,
          ease: Sine.easeInOut,
          onComplete: function () {
            activeAnimation.kill;
            return;
          }
        });
        activeAnimations.add(activeAnimation);
        newMapping.set(descriptor.newVisualIndex, descriptor.elementIndex);
      }
      elementIndexMapping = newMapping;
    };

    const attemptToAnimateSupplyCardSort = () => {
      if (!requiresSort) return;
      requiresSort = false;
      cancelActiveAnimations();
      animateSupplyCardSort();
    };

    const updateActiveCards = () => {
      const setObj = sets.value[0];
      if (!setObj) return;
      const sorted = getCards(setObj.supplyCards.concat(getOtherCards(setObj, 'Normal Supply Cards') as any[]));
      const mappedSupplyCards: SupplyCard[] = [];
      for (let i = 0; i < sorted.length; i++) {
        const card = sorted[i];
        if (card) mappedSupplyCards[getElementIndex(i)] = card;
      }
      displayedCards.value = mappedSupplyCards as SupplyCard[];
    };

    const numberOfColumnsForSupplyCards = computed(() => {
      return windowStore.isEnlarged ? 2 : windowStore.width <= FOUR_COLUMN_SUPPLY_CARD_WIDTH ? 4 : 5;
    });

    const numberOfColumnsForAddons = computed(() => {
      return windowStore.isEnlarged ? 1 : windowStore.width <= TWO_COLUMN_ADDON_WIDTH ? 2 : 3;
    });

    const getshapeofmat = (mycardtype: string) => {
      if (mycardtype == 'advToken') return Shape.SMALLSQUARE
      return Shape.SQUARE
    };

    const getCards = (cardIds: any[], origine?: SortOption) => {
      const option = origine !== undefined ? origine : (setsStore.sortBoxesSet as SortOption) || SortOption.ALPHABETICAL;
      return SupplyCardSorter.sort(cardIds as SupplyCard[], option, t);
    };


    // const OtherCardTypes = (isVertical: boolean) => {
    //   return isVertical ? OTHER_CARD_TYPES : OTHER_CARD_TYPES_HORIZONTAL;
    // };
    const GetOtherCardTypes = (typeRequested: string) => {
      if (typeRequested == 'horizontal') return OTHER_CARD_TYPES_HORIZONTAL;
      if (typeRequested == 'vertical') return OTHER_CARD_TYPES;
      if (typeRequested == 'horizontalMat') return OTHER_CARD_TYPES_MAT_HORIZONTAL;
      if (typeRequested == 'verticalMat') return OTHER_CARD_TYPES_MAT;
      if (typeRequested == 'squareMat') return OTHER_CARD_TYPES_MAT_SQUARE;
      return OTHER_CARD_TYPES;
    };

    const challenge_sortBoxesSet = (mycard_type: string) => {
      if (mycard_type == "Travellers Page" || mycard_type == "Travellers Peasant") return SortOption.COST;
      if (mycard_type == "Split Cards") return SortOption.ORDERSTRING;
      if (mycard_type == "Castle") return SortOption.COST;
      return setsStore.sortBoxesSet;
    };
    const OverlayCheck = ShowOverlayOptions.CHECK;

    const shouldShowOverlay = (card: any) => {
      if (language.value === Language.ENGLISH) return false;
      return !LANGUAGES_WITH_TRANSLATED_CARDS.has(language.value) ||
        IMAGES_MISSING_FROM_TRANSLATIONS.get(language.value)?.has(card.setId);
    };

    // Initialize displayed cards and watchers for animated reordering
    onMounted(() => {
      updateActiveCards();
      // ensure mapping is identity initially
      elementIndexMapping = new Map<number, number>();
    });

    watch(() => sets.value[0], () => {
      updateActiveCards();
      nextTick(() => resetCardPositions());
    });

    watch(() => setsStore.sortBoxesSet, () => {
      requiresSort = true;
      attemptToAnimateSupplyCardSort();
    });

    return {
      Shape,
      sets,
      displayedCards,
      numberOfColumnsForSupplyCards,
      numberOfColumnsForAddons,
      getshapeofmat,
      getCards,
      getOtherCards,
      // OtherCardTypes,
      GetOtherCardTypes,
      challenge_sortBoxesSet,
      shouldShowOverlay,
      OverlayCheck
    };
  },
});
</script>

<style scoped>
@media (max-width: 450px) {
  .preset-kingdom_title_name {
    font-size: 30px;
    margin-right: 8px;
  }

  .preset-kingdom_set-name {
    font-size: 14px;
    padding: 4px 6px;
  }
}
</style>