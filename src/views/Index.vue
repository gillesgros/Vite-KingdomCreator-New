<template>
  <div>
    <ReplaceSupplyCardModal :key="randomizerKingdomId" />
    <ReplaceAddonModal replacingType="Addon"/>
    <ReplaceBoonModal />
    <ReplaceAllyModal replacingType="Ally"/>
    <<ReplaceAllyModal replacingType="Prophecy" />
    <Page :subtitle="$t('index_page_subtitle')" :selectedType="selectedType">
      <Randomizer @specify-replacement="showReplaceModal = true" />
    </Page>
    <EnlargeButton />
  </div>
</template>

<script lang="ts">
/* import Vue, typescript */
import { defineComponent, ref } from 'vue';

/* import store  */
import { useRandomizerStore } from '@/pinia/randomizer-store';

/* import Components */
import Page, { MenuItemType } from '@/components/Page.vue';
import EnlargeButton from '@/components/EnlargeButton.vue';
import Randomizer from '@/components/randomize/Randomizer.vue';
import ReplaceSupplyCardModal from '@/components/randomize/ReplaceSupplyCardModal.vue';
import ReplaceAddonModal from '@/components/randomize/ReplaceAddonModal.vue';
import ReplaceBoonModal from '@/components/randomize/ReplaceBoonModal.vue';
import ReplaceAllyModal from '@/components/randomize/ReplaceAllyModal.vue';
import ReplaceProphecyModal from '@/components/randomize/ReplaceProphecyModal.vue';

import useBase from './base';

export default defineComponent({
  name: "Index",
  components: {
    Page,
    Randomizer,
    ReplaceSupplyCardModal,
    ReplaceAddonModal,
    ReplaceBoonModal,
    ReplaceAllyModal,
    ReplaceProphecyModal,
    EnlargeButton
  },
  setup() {
    useBase();
    const randomizerStore= useRandomizerStore();

    const selectedType = MenuItemType.RANDOMIZER;
    const randomizerKingdomId = ref(randomizerStore.kingdom.id);
    const showReplaceModal = ref(false);
  return {
      randomizerKingdomId,
      selectedType,
      showReplaceModal
    };
  }

});
</script>