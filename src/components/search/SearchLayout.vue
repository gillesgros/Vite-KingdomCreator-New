<template>
  <div v-if="items.length">
    <div class="Search-layout__title">{{title}}</div> 
    <div class="search-layout-wrapper" :style="gridStyle">
      <div v-for="item in items" :key="item.id" class="search-layout-item" :style="{ width: itemWidth }">
        <div class="beforeStaticSet">
          <StaticCardWithSet 
            :card="item" 
            :showOverlay="showOverlay"
            style="position:relative;"/>
        </div>
        <div class="card-details">
          <span class="card-name">{{ $t(item.id) }}</span>
          <span class="card-set">{{ $t(item.setId) }}</span>
          <span class="card-cost">{{ getCostName(item) }}</span>
          <span class="card-types">{{ getCardTypeNames(item) }}</span>
        </div>
      </div>
    </div>
  </div>
</template>

<script lang="ts">
/* import Vue, typescript */
import { defineComponent } from 'vue';
import type { PropType } from 'vue';

/* import Dominion Objects and type*/
import { ShowOverlayOptions } from '@/utils/resources.ts';

/* import Components */
import StaticCardWithSet from '../StaticCardWithSet.vue';

export default defineComponent({
  name: "SearchLayout",
  components: {
    StaticCardWithSet
  },
  props: {
    items: {
      type: Array as PropType<any[]>,
      required: true
    },
    genericNbColumns: {
      type: Number,
      required: true
    },
    isVertical: {
      type: Boolean,
      required: true
    },
    title: {
      type: String,
      required: true
    },
    getCostName: {
      type: Function as PropType<(card: any) => string>,
      required: true
    },
    getCardTypeNames: {
      type: Function as PropType<(card: any) => string>,
      required: true
    }
  },
  setup(props) {
    // Calcul du width en fonction du nombre de colonnes
    // Formule: 900 / genericNbColumns
    // 5 colonnes → 180px, 3 colonnes → 300px, 2 colonnes → 450px, 1 colonne → 900px (max 600px)
    const itemWidth = Math.min(1000 / props.genericNbColumns, 600);
    const OverlayCheck = ShowOverlayOptions.CHECK;
    const gridStyle = {
      gridTemplateColumns: `repeat(${props.genericNbColumns}, minmax(180px, 1fr))`
    };
    
    return {
      getCostName: props.getCostName,
      getCardTypeNames: props.getCardTypeNames,
      showOverlay: OverlayCheck,
      gridStyle,
      itemWidth: `${itemWidth}px`
    };
  }
});
</script>

<style scoped>
.Search-layout__title {
  margin-bottom: 15px;
  color: #333;
  font-weight: bold;
  font-size: 24px;
}

.search-layout-wrapper {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: 18px;
  margin-bottom: 20px;
  align-items: start;
  border-bottom: 1px solid #ccc;
}

.search-layout-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  background: #f9f9f9;
  border: 1px solid #eee;
  border-radius: 8px;
  padding: 10px 6px 12px 6px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
  width: 180px;
  max-width: 100%;
}

.beforeStaticSet{
  display: flex;
  flex-direction: column;
  position : relative;
}

.beforeStaticSet img {
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
  align-items: center;
  margin-top: 6px;
  overflow: hidden;
}

.card-details span {
  width: 100%;
  text-align: center;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  display: block;
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