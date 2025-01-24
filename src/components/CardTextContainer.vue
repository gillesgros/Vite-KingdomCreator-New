<template>
  <div :class="layoutClass">
    <div class="card-text-container" :style="FontSize" style="font-family: 'Times New Roman';">
      <CardTextLine :cardId="card.id"
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
import type { DigitalCard } from "../dominion/digital_cards/digital-cards-type.ts";
import { FrenchCardTexts } from "../dominion/digital_cards/Dominion.games.ts";
import { DominionSets } from '../dominion/dominion-sets.ts';

interface Block {
  type: string;
  inner: string;
};

const divSize = 340
const lineHeightSize = 50
const lineLenghtSize = 48

export default defineComponent({
  name: 'CardTextContainer',
  components: {
    CardTextLine,
  },
  props: {
    direction: {
      type: String,
      required:true
    },
    card: {
      type: Object as () => DigitalCard,
      required: true,
    },
  },
  setup(props) {
      const layoutClass = computed(()=> {
        if (props.direction == "portrait")
         return `full-card-text-container card-stack-layer text-layer`;
        if (props.direction == "landscape")
          return `landscape-text-container`
        return `landscape-text-container`
      })

    const cardText = computed(() => FrenchCardTexts[props.card.id.toUpperCase()] || '');
    const lines = computed(() => { return cardText.value.split('//') });

    const regextests = [
      { regex: /^(---)/, type: 'separator' },
      { regex: /^\|\+\[(.*?)\]\|/, type: 'bigcoin_singleline' }, // Cas particulier
      { regex: /^\|\[!(.*?)\]\|/, type: 'bigcoin_singleline_noPlus' }, // Cas particulier
      { regex: /^\|\+{(.*?)}\|/, type: 'bigshield_singleline' }, // Cas particulier
      { regex: /^\|\+(.*?) <>\|/, type: 'bigsun_singleline' }, // Cas particulier

      { regex: /^([^><\[{\|%]+)/, type: 'normal' },
      { regex: /^\|%(.*?)%\|/, type: 'bold_italics' },
      { regex: /^%\|(.*?)\|%/, type: 'bold_italics' },
      { regex: /^\|(.*?)\|/, type: 'bold' },
      { regex: /^%(.*?)%/, type: 'italics' },
      { regex: /^\[!(.*?)\]/, type: 'bigcoin' },
      { regex: /^\[(.*?)\]/, type: 'coin' },
      { regex: /^{!(.*?)}/, type: 'bigshield' },
      { regex: /^{(.*?)}/, type: 'shield' },
      { regex: /^<>/, type: "sun" },
    ];

    const getBlocks = (line: string) => {
      let text = line;
      const blocks: Block[] = [];
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
        if (!matched) break;
      }
      return blocks;
    };

// To calculate the font size of the card text
//constant
    const cardName = { isLandscape: DominionSets.isLandscape(props.card.id), hasHeirloom: false };
    const CardSizes = { FULL: { portraitRatio : 1.5777777777777777, landscapeRatio : 0.6338028169014085 } };
    const factors = { boldLineFactor : 1.3,
                      bigLineFactor : 2, 
                      separatorFactor : cardName.isLandscape ? 0.4 : 0.8, 
                      blankFactor : cardName.isLandscape ? 0.2 : 0.4,
                      maxFontFactor : 0.15,
                      maxLandscapeFontFactor : 0.4,
                      heirloomFactor : 1.1 ,
                      victoryRatio : 0.9,
                      sunRatio : 1
                    }
    const { boldLineFactor, bigLineFactor, separatorFactor, blankFactor, maxFontFactor,
            maxLandscapeFontFactor, heirloomFactor, victoryRatio, sunRatio } = factors;
    const measurementCanvas = document.createElement("canvas");
    const ctx = measurementCanvas.getContext("2d");

// function for font-size and line-height calculation
    const FontSize = computed(() =>  {
      const lineHeightMeasurementSize = lineHeightSize
      const lineLenghtMeasurementSize = lineLenghtSize
      let totalHeight = 1;
      let longestWidth = 1;

      for(const line of lines.value) {
        const metrics = measureLine(line, "Times New Roman", lineHeightMeasurementSize);
        longestWidth = Math.max(longestWidth, metrics.width);
        totalHeight += metrics.height;
        //console.log(props.card.id, longestWidth, totalHeight)
      };
      //console.log(props.card.id, longestWidth, totalHeight)
      const bbox = getCardTextBlockSize(divSize, cardName); 
      //console.log(props.card.id, bbox)
      // font-size considering bbox
      let capSize = bbox.height * (cardName.isLandscape ? maxLandscapeFontFactor : maxFontFactor);
      // font-size considering line 
      let measuredSize = Math.min(
                            lineHeightMeasurementSize * bbox.height / totalHeight,
                            lineLenghtMeasurementSize * bbox.width / longestWidth
                          );
      const lineHeightbox = bbox.width * (cardName.isLandscape ? maxLandscapeFontFactor : maxFontFactor);
      const lineHeight = Math.min(
            Math.max(1.0,capSize/measuredSize),
            Math.max(1.0,400/totalHeight)
          ).toFixed(2);
      
      if (props.direction == "landscape") measuredSize = capSize
      if (props.card.id == "alms" ||props.card.id == "ferry" ) {    
        console.log(props.card.id, 
          "font", measuredSize.toFixed(2), capSize.toFixed(2), "===>", Math.min(capSize, measuredSize).toFixed(2))
        console.log(props.card.id, 
          "line", lineHeightbox, totalHeight, 340, Math.max(1.0,capSize/measuredSize).toFixed(2), Math.max(1.0,divSize/totalHeight).toFixed(2),
          "final line", lineHeight)
      }
      if (props.direction == "landscape") {
        capSize=measuredSize
        return `font-size: ${Math.min(capSize, measuredSize,23).toFixed(2)}px; line-height: ${lineHeight};`;

      }
      if (props.direction == "portrait") {
        return `font-size: ${Math.min(capSize, measuredSize,23).toFixed(2)}px; line-height: ${lineHeight};`;
      }
      return "";
    })

    const measureLine = (line:string, fontFamily:string, measurementSize: number) => {
      const lineIsBold = /^\|.*?\|$/.test(line);
      const lineIsHeirloom = /^%%.*?%%$/.test(line);
      const lineIsSeparator = line === "---";
      const lineIsBlank = line === "";
      const lineIsBig = /^[\[{]!.*?[\]}]$/.test(line);
      const lineText = lineIsBold || lineIsHeirloom ? line.slice(1, -1) : line;

      let adjustedSize = measurementSize;
      if (lineIsBold) adjustedSize *= boldLineFactor;
      if (lineIsBig) adjustedSize *= bigLineFactor;
      if (lineIsSeparator) adjustedSize *= separatorFactor;
      if (lineIsBlank) adjustedSize *= blankFactor;
      if (lineIsHeirloom) adjustedSize *= heirloomFactor;

      let totalLength = 0;
      let totalHeight = 0;
      if (!lineIsSeparator && !lineIsBlank) {
        const blocks = getBlocks(lineText);
        blocks.forEach((block) => {
          totalLength += measureBlock(block, fontFamily, adjustedSize).width;
          totalHeight += measureBlock(block, fontFamily, adjustedSize).height;
        });
      }
      return { width: totalLength, height: adjustedSize };
    }

    const measureBlock = (block: Block, fontFamily: string, measurementSize: number) => {     
      if (!ctx) throw new Error("Failed to get 2D context");
      let evaluatedSize
      let addition = 0;
      switch (block.type) {
        case "normal":
          ctx.font = `${measurementSize}px ${fontFamily}`;
          break
        case "italics":
          ctx.font = `italic ${measurementSize}px ${fontFamily}`;
          break
        case "bold":
        case "bold_italics":
          ctx.font = `bold ${measurementSize}px ${fontFamily}`;
          break
        case "coin":
        case "bigcoin":
        case "sun":
          return {width : measurementSize, height : measurementSize}
        case "shield":
          ctx.font = `bold ${measurementSize}px ${fontFamily}`;
          addition = measurementSize * victoryRatio;
          break;
        case "bigshield":
          ctx.font = `bold ${measurementSize * bigLineFactor}px ${fontFamily}`;
          addition = measurementSize * victoryRatio;
          break;
        default:
          throw new Error(`Unknown block type: ${block.type}`);
      }
      evaluatedSize = ctx.measureText(block.inner); 
      const ascent = evaluatedSize.actualBoundingBoxAscent || measurementSize * 0.8; // Valeur par défaut si non supportée
      const descent = evaluatedSize.actualBoundingBoxDescent || measurementSize * 0.2; // Valeur par défaut si non supportée

      return {width : ctx.measureText(block.inner).width + addition, height : ascent + descent};  
    }

    const getCardTextBlockSize = (w: number, cardName: { isLandscape: boolean; hasHeirloom: boolean }) => {
      const { portraitRatio, landscapeRatio } = CardSizes.FULL;
      if (cardName.isLandscape) return { height: w * landscapeRatio * 0.21, width: w * 0.815 };
      if (cardName.hasHeirloom) return { height: w * portraitRatio * 0.27, width: w * 0.795 };
      return { height: w * portraitRatio * 0.31, width: w * 0.795 };
    }

    return {
      layoutClass,
      lines,
      getBlocks,
      FontSize
    };
  },
});
</script>