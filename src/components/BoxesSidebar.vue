<template>
  <div class="sidebar">
    <a class="standard-button standard-button--is-primary standard-button--large desktop_randomize-button masking-button">
      {{ "placeHolder" }}
    </a>    
    <div class="sidebar-content">
      <div class="sidebar-content-title">
        <span>{{ $t("Sets content") }}</span>
        <div class="sidebar-content-option">
        <label class="checkbox sidebar-content-option">
            <input id="alpha" type="radio" style="margin-left:5px;" v-model="setsOrderType" :value="'alpha'"
            @change="handleSetOrderTypeChange('alpha')" />
            <span>{{ $t("Alphabetical") }}</span>
        </label> 
        <label class="checkbox sidebar-content-option" style="margin-left:10px;">
            <input id="date" type="radio" style="margin-left:5px;" v-model="setsOrderType" :value="'date'"
            @change="handleSetOrderTypeChange('date')" />
            <span>{{ $t("Date") }}</span>
        </label>      
        </div>
      </div>
      <div class="sets">
        <div class="set" v-for="setId in sets" :key="setId">
          <label class="checkbox">
            <input type="radio" v-model="selectedBoxesSetId" :id="setId" :value="setId"
              @change="handleSelectionChange(setId)" />
            <span>{{ $t(setId) }} <span v-if="findMultipleVersionSets(setId).length !== 0"> - 1st</span></span>
          </label>
          <span v-if="findMultipleVersionSets(setId).length !== 0">
            <label class="checkbox suboption-set">
              <input type="radio" v-model="selectedBoxesSetId" :id="findMultipleVersionSets(setId)[0].idv2"
                :value="findMultipleVersionSets(setId)[0].idv2" 
                @change="handleSelectionChange(findMultipleVersionSets(setId)[0].idv2)" />
              <span>2nd</span>
            </label>
          </span>
        </div>
      </div>
      <div class="clear"></div>

      <div class="sidebar-content-title">{{ $t("Sort") }}</div>
      <div class="option" v-for="sortOption in sortOptions" :key="sortOption.value">
        <label class="checkbox">
          <input type="radio" name="sortOption" :value="sortOption.value" v-model="sortBoxesSet"
            @change="handleSortChange(sortOption.value)">
          <span>{{ $t(sortOption.display) }}</span>
        </label>
      </div>

    </div>
  </div>
</template>

<script lang="ts">
/* import Vue, typescript */
import { defineComponent, ref, computed } from "vue";
import { useI18n } from 'vue-i18n'

/* import Dominion Objects and type*/
import { DominionSets } from "../dominion/dominion-sets";
import type { SetId } from "../dominion/set-id";
import { Year_set } from "../dominion/digital_cards/digital-cards-Illustrator"

import { MultipleVersionSets, HideMultipleVersionSets, Sets_To_Ignore_Regroup } from "../dominion/set-id";
import { SortOption } from "../settings/settings";

/* import store  */
import { useSetsStore } from '../pinia/sets-store';
import { useSettingsStore } from "../pinia/settings-store";

/* import Components */

const sortOptions = [
  { display: "Alphabetical", value: SortOption.ALPHABETICAL },
  { display: "Cost", value: SortOption.COST },
];

export default defineComponent({
  name: 'BoxesSidebar',
  setup() {
    const { t } = useI18n();
    const setsStore = useSetsStore()
    const settingsStore = useSettingsStore()
    const selectedBoxesSetId = ref(setsStore.selectedBoxesSetId)
    const sortBoxesSet = ref(setsStore.sortBoxesSet);
    const setsOrderType = ref(setsStore.setsOrderType)
    const sortSet = ref(setsStore.sortSet);
    setsStore.updateSortSet(sortSet.value)


    const sets = computed(() => { 
      const AllSetIdsToConsider = DominionSets.getAllSetsIds()
            .filter(setId => {
              if (settingsStore.isUsingOnlyOwnedsets){
                return settingsStore.ownedSets.indexOf(setId as never) != -1
              } 
              return true;
              })
            .filter(setId => !HideMultipleVersionSets.includes(setId) && !Sets_To_Ignore_Regroup.has(setId))
        const sortedSets = setsOrderType.value === 'date'   // Check if sortType has a value (not undefined)
            ? AllSetIdsToConsider.sort((a, b) => (Year_set.find(set => set.id === a)?.order ||0) - (Year_set.find(set => set.id === b)?.order ||0))
            : AllSetIdsToConsider.sort((a, b) => t(a).localeCompare(t(b)))
        return sortedSets;
      }
      );

    const handleSelectionChange = (value: SetId) => {
      setsStore.updateSelectedBoxesSet(value);
    };

    const handleSetOrderTypeChange = (value: string) => {
      setsStore.updateSetsOrderType(value);
    };
    
    const handleSortChange = (value: SortOption) => {
      //console.log(value);
      setsStore.updateSortBoxesSet(value);
    };

    const findMultipleVersionSets = (setValue: string) => {
      if (settingsStore.isUsingOnlyOwnedsets){
        const AllSetIdsToConsider = DominionSets.getAllSetsIds()
            .filter(setId => { return settingsStore.ownedSets.indexOf(setId as never) != -1 })
        return MultipleVersionSets.filter(set => { return (set.id === setValue) })
                .filter(set => AllSetIdsToConsider.some(setid => setid===set.idv2))
      } else {
        return MultipleVersionSets.filter(set => { return (set.id === setValue) })
      }
    };

    return {
      selectedBoxesSetId,
      sortBoxesSet,
      setsOrderType,

      sortOptions,
      sets,
      handleSelectionChange,
      handleSetOrderTypeChange,
      handleSortChange,
      findMultipleVersionSets
    };
  }
});
</script>

<style scoped>
.desktop_randomize-button,
.condensed_randomize-button {
  display: block;
  margin: 2px;
}

.condensed_randomize-button {
  margin-top: 12px;
}

.masking-button {
  background: unset;
  border-color: unset;
  box-shadow: unset;
  color:#ffffff;
  pointer-events: none;
  cursor: default;
}
</style>