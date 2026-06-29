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
      class="card-hover-preview" 
      :style="previewStyle"
    >
      <img :src="hoveredCardImageUrl" class="card-hover-preview__img" @error="handleImgError" />
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent, computed, watch, ref } from 'vue';
import type { SetId } from '@/dominion/set-id';
import { DominionSets } from '@/dominion/dominion-sets';
import { Cards } from '@/utils/cards';
import type { Boon } from '@/dominion/boon';
import { getCardImageUrl, incaseofImgerror } from '@/utils/resources';
import { useRandomizerStore } from '@/pinia/randomizer-store';
import { usei18nStore } from '@/pinia/i18n-store';
import { useI18n } from 'vue-i18n';

export default defineComponent({
  name: "ReplaceBoonModal",
  setup() {
    const { t } = useI18n();
    const randomizerStore = useRandomizerStore();
    const i18nStore = usei18nStore();
    const selectedSetIds = computed(() => randomizerStore.settings.selectedSets);
    const selectedSetId = ref<SetId | null>(null);
    const searchQuery = ref('');

    const specifying = computed(() => randomizerStore.specifyingReplacementBoon);
    const specifying_names = computed(() => specifying.value ? t(specifying.value.id) : '');

    const sets = computed(() => {
      return selectedSetIds.value.map((setId) => DominionSets.getSetById(setId)).sort((a, b) => {
        return a.name == b.name ? 0 : a.name < b.name ? -1 : 1;
      });
    });

    const filteredCards = computed(() => {
      if (!specifying.value) return [];
      
      const setIdsToUse = selectedSetId.value ? [selectedSetId.value] : selectedSetIds.value;
      const allCards = Cards.getAllCardsFromSets(DominionSets.getAllSets());
      
      let matchedCards = Cards.getAllBoons(allCards);
      matchedCards = matchedCards.filter(Cards.filterByIncludedSetIds(setIdsToUse));
      
      const currentBoonsIds = randomizerStore.kingdom.boons.map(b => b.id);
      matchedCards = matchedCards.filter(card => {
        const inKingdom = currentBoonsIds.includes(card.id);
        const isSpecifyingCard = specifying.value && specifying.value.id === card.id;
        return !inKingdom || isSpecifyingCard;
      });

      return matchedCards.sort((a, b) => {
        const nameA = t(a.id);
        const nameB = t(b.id);
        return nameA.localeCompare(nameB);
      });
    });

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

    const hoveredCard = ref<Boon | null>(null);
    const mouseX = ref(0);
    const mouseY = ref(0);

    const handleMouseEnterCard = (card: Boon, event: MouseEvent) => {
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

    const handleImgError = (ev: Event) => {
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
      randomizerStore.CLEAR_SPECIFYING_REPLACEMENT_BOON();
    };
    const handleCancel = () => {
      randomizerStore.CLEAR_SPECIFYING_REPLACEMENT_BOON();
    };

    const handleSelectCard = (card: Boon) => {
      randomizerStore.REPLACE_SPECIFYING_BOON(card);
      hoveredCard.value = null;
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

  .card-hover-preview {
    width: 200px;
    height: 310px;
    border-radius: 8px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.5);
    background: #000;
    overflow: hidden;
    pointer-events: none;
    transition: transform 0.1s ease-out;
  }
  
  .card-hover-preview__img {
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
