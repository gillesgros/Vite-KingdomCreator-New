<template>
  <div>
    <transition name="fade">
      <div class="modal-background" v-if="specifying"></div>
    </transition>
    <transition name="expand-fade">
      <div class="modal-container" v-if="specifying" @keydown.esc="handleEscapeKey">
        <div class="modal" tabindex="0" ref="modal">
          <div class="modal__title">
            {{ $t("ReplaceModal") }} " {{ specifying_names }} "
          </div>
          <div class="modal__subtitle">
            {{ $t('Customize the replacement card') }}
          </div>
          
          <div class="modal__body">
            <div class="modal__body__left-pane">
              <div class="modal__body__filters">
                <div class="modal__body__section">
                  <div class="modal__body__section__title">Set</div>
                  <div class="modal__body__section__options">
                    <div class="modal__body__section__option">
                      <label class="checkbox">
                        <input type="radio" id="selectedSet" :value="null" v-model="selectedSetId" />
                        <span>Any Set</span>
                      </label>
                    </div>
                    <div v-for="set in sets" :key="set.setId" class="modal__body__section__option">
                      <label class="checkbox">
                        <input type="radio" id="selectedSet" :value="set.setId" v-model="selectedSetId" />
                        <span>{{ set.name }}</span>
                      </label>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div class="modal__body__section__sep"></div>

            <div class="modal__body__section modal__body__section--choose">
              <div class="modal__body__section__title">{{ $t('Choose Card') }}</div>
              <input 
                type="text" 
                class="card-search-input" 
                v-model="searchQuery" 
                :placeholder="$t('Search card...')" 
              />
              <div class="modal__body__section__options cards-list-scrollable">
                <div 
                  v-for="card in searchedCards" 
                  :key="card.id" 
                  class="card-selection-item" 
                  @click="handleSelectCard(card)"
                  @mouseenter="handleMouseEnterCard(card, $event)"
                  @mousemove="handleMouseMoveCard($event)"
                  @mouseleave="handleMouseLeaveCard"
                >
                  {{ $t(card.id) }}
                </div>
                <div v-if="searchedCards.length === 0" class="no-cards-message">
                  {{ $t('No matching cards') }}
                </div>
              </div>
            </div>
          </div>

          <div class="modal__footer">
            <div class="modal__footer__left">
              <div class="standard-button standard-button--is-grey" @click="handleCancel">Cancel</div>
              <div class="standard-button standard-button--is-primary" @click="handleRandomize">{{ $t('Randomize') }}</div>
            </div>

            <div class="modal__footer__right">
              <span class="modal__footer__text">
                {{ $t('ChooseCardExplanation') }}
              </span>
            </div>
          </div>
        </div>
      </div>
    </transition>

    <!-- Floating Card Preview -->
    <div 
      v-if="hoveredCard" 
      class="card-hover-preview-horizontal" 
      :style="previewStyle"
    >
      <img :src="hoveredCardImageUrl" class="card-hover-preview-horizontal__img" @error="handleImgError" />
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent, computed, watch, ref } from 'vue';
import type { SetId } from '@/dominion/set-id';
import { DominionSets } from '@/dominion/dominion-sets';
import { Cards } from '@/utils/cards';
import type { Ally } from '@/dominion/ally';
import { getCardImageUrl, incaseofImgerror } from '@/utils/resources';
import { useRandomizerStore } from '@/pinia/randomizer-store';
import { usei18nStore } from '@/pinia/i18n-store';
import { useI18n } from 'vue-i18n';
import type { Addon } from '@/dominion/addon';
import { Addons_TYPE } from '@/dominion/addon';

export default defineComponent({
  name: "ReplaceAllyModal",
  props: {
    replacingType: String
  },
  setup(props) {
    const { t } = useI18n();
    const randomizerStore = useRandomizerStore();
    const i18nStore = usei18nStore();
    console.log(  'replacingType:', props.replacingType)
    const selectedSetIds = computed(() => randomizerStore.settings.selectedSets);
    const selectedSetId = ref<SetId | null>(null);
    const selectedAddonType = ref<Addons_TYPE | null>(null);
    const searchQuery = ref('');

    const specifying = computed(() => {
      switch (props.replacingType) {
        case 'Ally':
          return randomizerStore.specifyingReplacementAlly;
        case 'Prophecy':
          return randomizerStore.specifyingReplacementProphecy;
        case 'Addon':
          return randomizerStore.specifyingReplacementAddon;
        default:
          return null;
      }
    });
    
    const specifying_names = computed(() => specifying.value ? t(specifying.value.id) : '');
    const allowedAddonTypes: Addons_TYPE[] = [];
    switch (props.replacingType) {
      case 'Ally':
        allowedAddonTypes.push(Addons_TYPE.ALLY);
        break;
      case 'Prophecy':
        allowedAddonTypes.push(Addons_TYPE.PROPHECY);
        break;
      case 'Addon':
        allowedAddonTypes.push(Addons_TYPE.EVENT);
        allowedAddonTypes.push(Addons_TYPE.LANDMARK);
        allowedAddonTypes.push(Addons_TYPE.PROJECT);
        allowedAddonTypes.push(Addons_TYPE.WAY);
        allowedAddonTypes.push(Addons_TYPE.TRAIT);
        break;
      default:
        break; 
    }
    
    const sets = computed(() => {
      const xx=  selectedSetIds.value
        .map((setId) => DominionSets.getSetById(setId))
        .filter((set) => {
          const addonCollections: { type: Addons_TYPE; cards: any[] }[] = [
              { type: Addons_TYPE.EVENT, 
                cards: allowedAddonTypes.includes(Addons_TYPE.EVENT) ? (set.events || []) : [] },
              { type: Addons_TYPE.LANDMARK, 
                cards: allowedAddonTypes.includes(Addons_TYPE.LANDMARK) ? (set.landmarks || []) : [] },
              { type: Addons_TYPE.PROJECT, 
                cards: allowedAddonTypes.includes(Addons_TYPE.PROJECT) ? (set.projects || []) : [] },
              { type: Addons_TYPE.WAY, 
                cards: allowedAddonTypes.includes(Addons_TYPE.WAY) ? (set.ways || []) : [] },
              { type: Addons_TYPE.TRAIT, 
                cards: allowedAddonTypes.includes(Addons_TYPE.TRAIT) ? (set.traits || []) : [] },
              { type: Addons_TYPE.ALLY, 
                cards: allowedAddonTypes.includes(Addons_TYPE.ALLY) ? (set.allies || []) : [] },
              { type: Addons_TYPE.PROPHECY, 
                cards: allowedAddonTypes.includes(Addons_TYPE.PROPHECY) ? (set.prophecies || []) : [] }
          ];
          console.log(addonCollections);
          return addonCollections.some((collection) => collection.cards.length > 0)
        })
        .sort((a, b) => {
          return a.name === b.name ? 0 : a.name < b.name ? -1 : 1;
        });
        console.log(xx);
      return xx;
    });

    const filteredCards = computed(() => {
      if (!specifying.value) return [];
      
      const setIdsToUse = selectedSetId.value ? [selectedSetId.value] : selectedSetIds.value;
      const setsToSearch = setIdsToUse.map(id => DominionSets.getSetById(id));
      let matchedCards: Addon[] = [];

      const typesToConsider = selectedAddonType.value ? [selectedAddonType.value] : availableAddonTypes.value;

      for (const set of setsToSearch) {
        if (typesToConsider.includes(Addons_TYPE.EVENT) && set.events) matchedCards.push(...set.events);
        if (typesToConsider.includes(Addons_TYPE.LANDMARK) && set.landmarks) matchedCards.push(...set.landmarks);
        if (typesToConsider.includes(Addons_TYPE.PROJECT) && set.projects) matchedCards.push(...set.projects);
        if (typesToConsider.includes(Addons_TYPE.WAY) && set.ways) matchedCards.push(...set.ways);
        if (typesToConsider.includes(Addons_TYPE.TRAIT) && set.traits) matchedCards.push(...set.traits);
        
	if (typesToConsider.includes(Addons_TYPE.ALLY) && set.allies) matchedCards.push(...set.allies);
        if (typesToConsider.includes(Addons_TYPE.PROPHECY) && set.prophecies) matchedCards.push(...set.prophecies);
      }
      
      const currentAddonsIds = randomizerStore.addons.map(a => a.id);
      matchedCards = matchedCards.filter(card => {
        const inKingdom = currentAddonsIds.includes(card.id);
        const isSpecifyingCard = specifying.value && specifying.value.id === card.id;
        return !inKingdom || isSpecifyingCard;
      });

      return (matchedCards as Addon[]).sort((a, b) => {
        const nameA = t(a.id);
        const nameB = t(b.id);
        return nameA.localeCompare(nameB);
      });
    });

    const availableAddonTypes = computed(() => {
      const types = new Set<Addons_TYPE>();    
      const setIdsToUse = selectedSetId.value ? [selectedSetId.value] : selectedSetIds.value;
      const setsToSearch = setIdsToUse.map(id => DominionSets.getSetById(id));
      for (const set of setsToSearch) {
        if (allowedAddonTypes.includes(Addons_TYPE.EVENT) && set.events && set.events.length > 0) types.add(Addons_TYPE.EVENT);
        if (allowedAddonTypes.includes(Addons_TYPE.LANDMARK) &&set.landmarks && set.landmarks.length > 0) types.add(Addons_TYPE.LANDMARK);
        if (allowedAddonTypes.includes(Addons_TYPE.PROJECT) &&set.projects && set.projects.length > 0) types.add(Addons_TYPE.PROJECT);
        if (allowedAddonTypes.includes(Addons_TYPE.WAY) &&set.ways && set.ways.length > 0) types.add(Addons_TYPE.WAY);
        if (allowedAddonTypes.includes(Addons_TYPE.TRAIT) &&set.traits && set.traits.length > 0) types.add(Addons_TYPE.TRAIT);
        
	if (allowedAddonTypes.includes(Addons_TYPE.ALLY) &&set.allies && set.allies.length > 0) types.add(Addons_TYPE.ALLY);
        if (allowedAddonTypes.includes(Addons_TYPE.PROPHECY) &&set.prophecies && set.prophecies.length > 0) types.add(Addons_TYPE.PROPHECY);
      }
      // If a specific addon type is selected, but after changing set there are no cards of this type, reset it
      if (selectedAddonType.value && !types.has(selectedAddonType.value)) {
        selectedAddonType.value = null;
      }
      return Array.from(types);
    });

    watch(selectedSetId, () => selectedAddonType.value = null);

    const searchedCards = computed(() => {
      const query = searchQuery.value.trim().toLowerCase();
      if (!query) {
        return filteredCards.value;
      }
      return filteredCards.value.filter(card => {
        const localizedName = t(card.id).toLowerCase();
        const rawId = card.id.toLowerCase();
        return localizedName.includes(query) || rawId.includes(query);
      });
    });

    const hoveredCard = ref<Ally | null>(null);
    const mouseX = ref(0);
    const mouseY = ref(0);

    const handleMouseEnterCard = (card: Ally, event: MouseEvent) => {
      hoveredCard.value = card;
      updateMousePosition(event);
    };

    const handleMouseMoveCard = (event: MouseEvent) => {
      updateMousePosition(event);
    };

    const handleMouseLeaveCard = () => {
      hoveredCard.value = null;
    };

    const updateMousePosition = (event: MouseEvent) => {
      mouseX.value = event.clientX;
      mouseY.value = event.clientY;
    };

    const hoveredCardImageUrl = computed(() => {
      if (!hoveredCard.value) return '';
      return getCardImageUrl(hoveredCard.value.id, i18nStore.language);
    });

    const previewStyle = computed(() => {
      const offset = 15;
      let left = mouseX.value + offset;
      let top = mouseY.value + offset;
      
      if (left + 200 > window.innerWidth) {
        left = mouseX.value - 200 - offset;
      }
      if (top + 280 > window.innerHeight) {
        top = window.innerHeight - 280 - offset;
      }
      
      return {
        position: 'fixed' as const,
        left: `${left}px`,
        top: `${top}px`,
        zIndex: 9999,
        pointerEvents: 'none' as const
      };
    });

    const handleImgError = (ev: globalThis.Event) => {
      incaseofImgerror(ev);
    };

    const handleSpecifyingChanged = () => {
      setTimeout(() => {
        if (specifying.value) {
          searchQuery.value = '';
          (document.querySelector('.modal') as HTMLElement).focus();
        }
      }, 0);
    };
    watch(specifying, handleSpecifyingChanged);

    const handleEscapeKey = () => {
      randomizerStore.CLEAR_SPECIFYING_REPLACEMENT_ALLY();
    };
    const handleCancel = () => {
      randomizerStore.CLEAR_SPECIFYING_REPLACEMENT_ALLY();
    };

    const handleSelectCard = (card: Ally) => {
      randomizerStore.REPLACE_SPECIFYING_ALLY(card);
      hoveredCard.value = null;
    };

    const handleRandomize = () => {
      const cards = searchedCards.value;
      handleSelectCard(cards[Math.floor(Math.random() * cards.length)]);
    };
    
    return {
      specifying,
      specifying_names,
      selectedSetId,
      sets,
      handleEscapeKey,
      handleCancel,
      searchQuery,
      searchedCards,
      handleSelectCard,
      hoveredCard,
      handleMouseEnterCard,
      handleMouseMoveCard,
      handleMouseLeaveCard,
      hoveredCardImageUrl,
      previewStyle,
      handleImgError,
      handleRandomize
    };
  }
});
</script>

<style scoped>
  .modal-background,
  .modal-container {
    position: fixed;
    height: 100%;
    left: 0;
    top: 0;
    width: 100%;
    z-index: 20;
  }

  .modal-background {
    background: rgba(0, 0, 0, 0.4);
    z-index: 10;
  }

  .modal-container {
    align-items: center;
    display: flex;
    flex-direction: row;
    justify-content: center;
    z-index: 20;
  }

  .modal {
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 0 20px rgba(0, 0, 0, 0.4);
    min-width: 300px;
    max-height: 605px;
    max-width: calc(100% - 8px);
    outline: none;
    overflow-x: hidden;
    overflow-y: auto;
    z-index: 20;
  }

  .modal__title {
    font-size: 28px;
    padding: 20px 20px 0 20px;
  }

  .modal__subtitle {
    color: #777;
    font-size: 14px;
    border-bottom: 1px solid #ccc;
    margin-bottom: 8px;
    padding: 0 20px 4px 20px;
  }

  .modal__body {
    display: flex;
    flex-direction: row;
    flex-wrap: wrap;
    justify-content: stretch;
    padding: 0 20px;
  }

  .modal__body__section {
    display: flex;
    flex-direction: column;
    margin: 6px;
    width: 130px;
  }

  .modal__body__section__options {
    display: flex;
    flex-direction: column;
  }

  .modal__body__section__sep {
    background: #ccc;
    height: auto;
    margin: 36px 16px;
    width: 1px;
  }

  .modal__body__section__title {
    font-size: 24px;
    margin-bottom: 6px;
  }

  .modal__body__section .checkbox {
    font-size: 18px;
  }

  .modal__body__left-pane {
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    max-height: 400px;
    overflow-y: auto;
  }
  
  .modal__body__filters {
    display: flex;
    flex-direction: row;
    flex-wrap: wrap;
    justify-content: stretch;
  }

  .modal__footer {
    background: #eee;
    border-top: 1px solid #ccc;
    display: flex;
    flex-direction: row;
    padding: 16px 20px;
    margin-top: 20px;
  }

  .modal__footer__left {
    flex: 1;
    display: flex;
    flex-direction: row;
    justify-content: flex-end;
    align-items: center;
    padding-right: 32px;
  }

  .modal__footer__left .standard-button {
    margin-left: 8px;
  }

  .modal__footer__right {
    width: 250px;
    margin-left: 16px;
    display: flex;
    align-items: center;
  }

  .modal__footer__text {
    color: #333;
    font-size: 16px;
    text-align: left;
  }

  .standard-button {
    padding: 8px 16px;
    border: 1px solid #ccc;
    border-radius: 4px;
    cursor: pointer;
    display: inline-block;
    font-size: 16px;
    text-align: center;
    transition: background-color 0.2s ease;
  }
  
  .standard-button--is-grey {
    background-color: #f0f0f0;
    color: #333;
  }

  .modal__body__section--choose {
    width: 250px;
  }
  
  .card-search-input {
    width: 100%;
    padding: 8px;
    border: 1px solid #ccc;
    border-radius: 4px;
    font-size: 14px;
    margin-bottom: 8px;
    box-sizing: border-box;
    outline: none;
    transition: border-color 0.2s ease;
  }

  .card-search-input:focus {
    border-color: #007bff;
  }
  
  .cards-list-scrollable {
    max-height: 215px;
    overflow-y: auto;
    border: 1px solid #ccc;
    border-radius: 4px;
    padding: 4px;
    background: #fafafa;
    display: flex;
    flex-direction: column;
    gap: 4px;
    box-sizing: border-box;
  }

  .card-selection-item {
    padding: 4px 12px;
    background: #fff;
    border: 2px solid #e0e0e0;
    border-radius: 4px;
    cursor: pointer;
    font-size: 16px;
    transition: all 0.2s ease;
    text-align: left;
  }

  .card-selection-item:hover {
    background: #007bff;
    color: #fff;
    border-color: #007bff;
  }

  .no-cards-message {
    padding: 12px;
    color: #888;
    text-align: center;
    font-size: 14px;
  }

  .card-hover-preview-horizontal {
    width: 310px;
    height: 200px;
    border-radius: 8px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.5);
    background: #000;
    overflow: hidden;
    pointer-events: none;
    transition: transform 0.1s ease-out;
  }
  
  .card-hover-preview-horizontal__img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  @media (max-width: 570px) {
    .modal {
      height: calc(100vh - 8px);
    }

    .modal__title {
      padding: 12px 12px 0 12px;
    }

    .modal__subtitle {
      padding: 0 12px 4px 12px;
    }

    .modal__body {
      display: block;
      max-width: 100%;
      padding: 0 6px;
    }

    .modal__body__section {
      box-sizing: border-box;
      flex: 0 1 auto;
      margin-bottom: 10px;
      max-width: 100%;
      width: auto;
    }

    .modal__body__section__sep {
      display: none;
    }

    .modal__body__section__options {
      display: flex;
      flex-direction: row;
      flex-wrap: wrap;
    }

    .modal__body__section__option {
      width: 50%;
    }

    .modal__body__section--choose {
      width: 100% !important;
      margin-top: 15px;
    }
    .cards-list-scrollable {
      max-height: 200px;
      display: flex !important;
      flex-direction: column !important;
      flex-wrap: nowrap !important;
    }
    .card-selection-item {
      width: 100% !important;
      box-sizing: border-box;
    }
    .card-hover-preview {
      display: none !important;
    }
  }
</style>
