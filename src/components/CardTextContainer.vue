<template>
    <div class="full-card-text-container">
      <div class="card-text-container" style="font-size: 20px; font-family: 'Times New Roman';">
        <CardTextLine
          v-for="(line, index) in lines"
          :key="index"
          :line="line"
          :getBlocks="getBlocks"
        />
      </div>
    </div>
  </template>
  
  <script lang="ts">
  import { defineComponent, computed } from 'vue';
  import CardTextLine from './CardTextLine.vue';
  import type { DigitalCard } from "../dominion/digital_cards/digital-cards-type";
  import { FrenchCardTexts } from "../dominion/digital_cards/french/frenchCardTexts.ts";
  
  export default defineComponent({
    name: 'CardTextContainer',
    components: {
      CardTextLine,
    },
    props: {
      card: {
        type: Object as () => DigitalCard,
        required: true,
      },
    },
    setup(props) {
      const cardText = computed(() => FrenchCardTexts[props.card.id.toUpperCase()] || '');
      const lines = computed(() => cardText.value.split('//'));
  
      const regextests = [
        { regex: /^(---)/, type: 'separator' },
        { regex: /^\|\+\[(.*?)\]\|/, type: 'bigcoin_singleline' }, // Cas particulier
        { regex: /^([^\[{\|%]+)/, type: 'normal' },
        { regex: /^\|(.*?)\|/, type: 'bold' },
        { regex: /^%(.*?)%/, type: 'italics' },
        { regex: /^\[!(.*?)\]/, type: 'bigcoin' },
        { regex: /^\[(.*?)\]/, type: 'coin' },
        { regex: /^{!(.*?)}/, type: 'bigshield' },
        { regex: /^{(.*?)}/, type: 'shield' },
      ];
  
      const getBlocks = (line: string) => {
        let text = line;
        const blocks: { type: string; inner: string }[] = [];
        while (text.length > 0) {
          let matched = false;
          for (const test of regextests) {
            const match = text.match(test.regex);
            if (match) {
              blocks.push({ type: test.type, inner: match[1] });
              text = text.slice(match[0].length);
              matched = true;
              break;
            }
          }
          if (!matched) {
            break;
          }
        }
        console.log("line:" , line)
        console.log("blocks:", blocks);
        return blocks;
      };
  
      return {
        lines,
        getBlocks,
      };
    },
  });
  </script>
  