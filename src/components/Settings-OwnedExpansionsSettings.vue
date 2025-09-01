<template>
  <div class="OwnedSize">
    <div class="SettingTitle">{{ $t("Owned sets Settings") }}</div>
    <div class="sets-description">{{ $t("settings_subtitle_owned_sets") }}</div>

    <div class="switch-groupedline">
      <div class="switch-labelAndswitch">
        <SwitchGroup as="div" class="switch-flex switchGroupcss">
          <SwitchLabel>{{ $t("Use Custom Configuration for Set Display") }}</SwitchLabel>
          <Switch as="button" v-model="ownedRestricted" v-slot="{ checked }" :class="ownedRestricted ? 'switch-bg-indigo-600' : 'switch-bg-gray-200'"
            class="relative-switchcss">
            <span class="SwitchSpan" :class="{ 'translate-x-5': checked, 'translate-x-0': !checked }" />
          </Switch>
        </SwitchGroup>
      </div>
    </div>
    <div class="sidebar"> 
      <div class="sidebar-content filters">
        <div class="ownnedset-constraint-container">
          <div class="setlabel-settings">{{ $t("Sets") }}</div>
          <label class="checkbox sidebar-content-option">
            <input type="radio" style="margin-left:5px;" v-model="setsOrderType" :value="'alpha'" />
            <span>{{ $t("Alphabetical") }}</span>
          </label>
          <label class="checkbox sidebar-content-option" style="margin-left:10px;">
            <input type="radio" style="margin-left:5px;" v-model="setsOrderType" :value="'date'" />
            <span>{{ $t("Date") }}</span>
          </label>

        </div>

        <div class="sets">
          <div class="set" v-for="setId in listedSetids" :key="setId">
            <label class="checkbox">
              <input :id="setId" type="checkbox" v-model="ownedSetIds"  :value="setId">
              <span>{{ $t(setId) }} <span v-if="FindMultipleVersionSets(setId).length !== 0"> - {{ $t("1st") }}</span></span>
            </label>
            <span v-if="FindMultipleVersionSets(setId).length !== 0">
              <label class="checkbox suboption-set">
                <input :id="(FindMultipleVersionSets(setId))[0]!.idv2" type="checkbox" v-model="ownedSetIds" 
                  :value="(FindMultipleVersionSets(setId))[0]!.idv2">
                <span>{{ $t("2nd") }}</span>
              </label>
            </span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script  lang="ts">


/* import Vue, typescript */
import { defineComponent, ref, computed, watch } from "vue";
import { SwitchGroup, Switch, SwitchLabel } from "@headlessui/vue";
import { useI18n } from 'vue-i18n'

/* import Dominion Objects and type*/
import { DominionSets } from "../dominion/dominion-sets";
import { MultipleVersionSets, HideMultipleVersionSets, Sets_To_Ignore_Regroup } from "../dominion/set-id";
import { Year_set } from "../dominion/digital_cards/digital-cards-Illustrator"
import type { SettingsParams } from "../settings/settings";

/* imoprt store  */
import { useSettingsStore } from "../pinia/settings-store";
import { useSetsStore } from "../pinia/sets-store";
import { useRandomizerStore } from "../pinia/randomizer-store";

export default defineComponent({
  name: "OwnedExpansionsSettings",
  components: {
    SwitchGroup,
    Switch,
    SwitchLabel,
  },
  setup() {
    const SettingsStore = useSettingsStore();
    const SetsStore = useSetsStore();
    const randomizerStore = useRandomizerStore()
    const { t } = useI18n();
    
    const ownedRestricted = ref(SettingsStore.isUsingOnlyOwnedsets);
    const ownedSetIds = ref(SettingsStore.ownedSets);
    const setsOrderType = ref("alpha");

    const listedSetids = computed(() => { 
        const AllSetIdsToConsider = DominionSets.getAllSetsIds()
            .filter(setId => !Sets_To_Ignore_Regroup.has(setId))
            .filter(setId => { return (HideMultipleVersionSets.indexOf(setId) == -1) })
        const sortedSets = setsOrderType.value === 'date'   // Check if sortType has a value (not undefined)
            ? AllSetIdsToConsider.sort((a, b) => (Year_set.find(set => set.id === a)?.order ||0) - (Year_set.find(set => set.id === b)?.order ||0))
            : AllSetIdsToConsider.sort((a, b) => t(a).localeCompare(t(b)))
        return sortedSets;
      }
      );

    watch([ownedSetIds, ownedRestricted] , () => {
      if (ownedSetIds.value.length == 0) ownedRestricted.value=false
      SettingsStore.updateSettings({ 
        ownedSets: ownedSetIds.value,
        isUsingOnlyOwnedsets: ownedRestricted.value
      });
      // Sélectionne tous les sets possédés pour le randomizer
      randomizerStore.UPDATE_SETTINGS({
        selectedSets: ownedSetIds.value.map(DominionSets.convertToSetId)
      } as SettingsParams);

      if (!ownedSetIds.value.some(setid => SetsStore.selectedSetId == setid)){
        SetsStore.selectedSetId = ownedSetIds.value[0] ?? SetsStore.selectedSetId;
      }
      if (!ownedSetIds.value.some(setid => SetsStore.selectedBoxesSetId== setid)){
        SetsStore.selectedBoxesSetId=ownedSetIds.value[0] ?? SetsStore.selectedBoxesSetId;
      }
      const ownedIdsSet = new Set(ownedSetIds.value);
      const filteredSelectedIds = randomizerStore.settings.selectedSets.filter((sid) => ownedIdsSet.has(sid) === true);

      randomizerStore.UPDATE_SETTINGS({
          selectedSets: filteredSelectedIds.map(DominionSets.convertToSetId)
        } as SettingsParams);
    });

    const FindMultipleVersionSets = (setValue: string) => {
      return MultipleVersionSets.filter(set => { return (set.id === setValue) })
    }

    return {
      ownedRestricted,
      listedSetids,
      setsOrderType,
      ownedSetIds,
      FindMultipleVersionSets,
    };
  },
});
</script>

<style scoped>

.content .sidebar
{
  width: unset;
  float: unset;
  margin-bottom: unset;
}

.sidebar .sidebar-content {
  border-width: 0px; 
} 

.OwnedSize {
  padding-left: 2%;
  padding-right: 2%;
  width: 55%;
  border-left: 2px solid #ccc; 
}

@media (max-width: 900px) {
  .OwnedSize {
    /* Adjust styles for smaller screens, if needed */
    width: 100%; /* Adjust width for smaller devices */
    border-left : none
  }
}


.content
  .sets
    .set {
      width: 50%;
      float: left;
    }


.sets-container {
  width: 100%;
  padding: 10px;
}



.sets-row {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 5px;
}
</style>