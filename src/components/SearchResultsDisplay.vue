<template>
  <div class="main">
    <h3>{{ $t("Results") }} ({{ cards.length }})</h3>
    <ul v-if="cards.length > 0" class="card-grid">
      <li v-for="card in cards" :key="card.id" class="card-item">
        <div class="card-image-container">
          <img :src="getCardImageUrl(card.id)" :alt="card.name" class="card-image" />
        </div>
        <div class="card-details">
          <span class="card-name">{{ card.name }}</span>
          <span class="card-set">{{ getSetName(card.setId) }}</span>
          <span class="card-cost">{{ getCostName(card.cost) }}</span>
          <span class="card-types">{{ getCardTypeNames(card) }}</span>
        </div>
      </li>
    </ul>
    <p v-else>{{ $t("No cards found matching your criteria.") }}</p>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';
import type { PropType } from 'vue';
import { getCardImageUrl } from '../utils/resources';
import { useI18n } from 'vue-i18n';
import { CardType, VISIBLE_CARD_TYPES } from "../dominion/card-type";
import type { Cost } from '../dominion/cost';
import type { SupplyCard } from '../dominion/supply-card';
import { DominionSets } from '../dominion/dominion-sets';

export default defineComponent({
  name: "SearchResultsDisplay",
  props: {
    cards: {
      type: Array as PropType<SupplyCard[]>,
      required: true,
    },
  },
  setup() {
    const { t, locale } = useI18n();

    const getCostName = (cost: Cost) => {
      const parts: string[] = [];
      if (cost.treasure > 0) {
        parts.push(`${cost.treasure} Coins`);
      }
      if (cost.potion > 0) {
        parts.push(`${cost.potion} Potion${cost.potion > 1 ? 's' : ''}`);
      }
      if (cost.debt > 0) {
        parts.push(`${cost.debt} Debt${cost.debt > 1 ? 's' : ''}`);
      }
      return parts.length > 0 ? parts.join(' + ') : '0 Coins';
    };

    const getCardTypeNames = (card: SupplyCard) => {
      // On parcourt dynamiquement tous les types de CardType
      const types = Object.values(CardType).filter(type => (card as any)[type]);
      return types.map(type => {
        const visibleType = VISIBLE_CARD_TYPES.find(vt => vt.type === type);
        return visibleType ? visibleType.name : type;
      }).join(', ');
    };

    const getSetName = (setId: string) => {
      const set = DominionSets.getSetById(setId as any);
      return set ? t(set.setId) : setId;
    };

    const getCardImageUrlWrapper = (cardId: string) => {
      return getCardImageUrl(cardId, locale.value as any);
    };

    return {
      getCostName,
      getCardTypeNames,
      getSetName,
      getCardImageUrl: getCardImageUrlWrapper,
    };
  },
});
</script>

<style scoped>
.search-results {
  padding: 20px;
  background: #fff;
  border-radius: 8px;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
  flex-grow: 1; /* Allow results to take available space */
}

h3 {
  margin-top: 0;
  margin-bottom: 15px;
  color: #333;
}

.card-grid {
  list-style: none;
  padding: 0;
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); /* Responsive grid */
  gap: 15px;
}

.card-item {
  background: #f9f9f9;
  border: 1px solid #eee;
  border-radius: 8px;
  padding: 10px;
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
}

.card-image-container {
  width: 100%;
  max-width: 150px; /* Adjust max width of image */
  height: auto;
  margin-bottom: 10px;
  overflow: hidden;
  border-radius: 4px;
}

.card-image {
  width: 100%;
  height: auto;
  display: block;
}

.card-details {
  display: flex;
  flex-direction: column;
  gap: 3px;
  font-size: 0.85em;
  color: #555;
  width: 100%;
}

.card-name {
  font-weight: bold;
  color: #333;
  font-size: 1em;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.card-set, .card-cost, .card-types {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
</style>