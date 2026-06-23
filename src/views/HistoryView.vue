<template>
  <Page :subtitle="$t('history_page_subtitle')" :selectedType="selectedType">
    <div class="contentRules main">
      <History />
    </div>
  </Page>
</template>

<script lang="ts">
/* import Vue, typescript */
import { defineComponent } from 'vue';
/* import Components */
import Page, { MenuItemType } from '@/components/Page.vue';
import History from '@/components/history/History.vue';

import useBase from './base';

export default defineComponent({
  name: "HistoryView", 
  components: {
    Page,
    History
  },
  setup() {
    useBase();
    const selectedType = MenuItemType.HISTORY;

  return {
      selectedType,
    };
  }

});
</script>