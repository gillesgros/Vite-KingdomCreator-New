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

let divSize = 20
let lineHeightSize = 4
let lineLenghtSize = 4

// card H: 47 L: 58
const cardParams = {
  "portrait": { divSize : 340,
                lineHeightSize : 47,
                lineLenghtSize : 58
              },
  "landscape" :  { divSize : 240,
                lineHeightSize : 40,
                lineLenghtSize : 47
              }
}

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
                      heirloomFactor : 1.1 , //0.8
                      victoryRatio : 0.9,
                      sunRatio : 1
                    }
    const { boldLineFactor, bigLineFactor, separatorFactor, blankFactor, maxFontFactor,
            maxLandscapeFontFactor, heirloomFactor, victoryRatio, sunRatio } = factors;
    const measurementCanvas = document.createElement("canvas");
    const ctx = measurementCanvas.getContext("2d");

// function for font-size and line-height calculation
    const FontSize = computed(() =>  {
      console.log(props.card.id, props.direction)
      switch (props.direction) {
        case "portrait":
          divSize = cardParams.portrait.divSize
          lineHeightSize = cardParams.portrait.lineHeightSize
          lineLenghtSize = cardParams.portrait.lineLenghtSize
          break;
        case "landscape":
          divSize = cardParams.landscape.divSize
          lineHeightSize = cardParams.landscape.lineHeightSize
          lineLenghtSize = cardParams.landscape.lineLenghtSize
          break;
      }
      console.log(props.card.id, props.direction, divSize, lineHeightSize, lineLenghtSize)

      const lineHeightMeasurementSize = lineHeightSize
      const lineLenghtMeasurementSize = lineLenghtSize
      let totalHeight = 1;
      let longestWidth = 1;

      for(const line of lines.value) {
        console.log(props.card.id, "measureLine", line, lineLenghtMeasurementSize, lineHeightMeasurementSize)
        const metrics = measureLine(line, "Times New Roman", lineLenghtMeasurementSize, lineHeightMeasurementSize);
        console.log(props.card.id, metrics.width, metrics.height, line)
        longestWidth = Math.max(longestWidth, metrics.width);
        totalHeight += metrics.height;
        //console.log(props.card.id, longestWidth, totalHeight)
      };
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
      if (props.direction == "landscape") {
        //capSize=measuredSize
        console.log(props.card.id, 
          "font", measuredSize.toFixed(2), capSize.toFixed(2), "===>", Math.min(capSize, measuredSize).toFixed(2))
        console.log(props.card.id,
          "line", lineHeightbox, totalHeight, 340, Math.max(1.0,capSize/measuredSize).toFixed(2), Math.max(1.0,divSize/totalHeight).toFixed(2),
          "final line", lineHeight)
        return `font-size: ${Math.min(capSize, measuredSize,23).toFixed(2)}px; line-height: ${lineHeight};`;

      }
      if (props.direction == "portrait") {
        console.log(props.card.id, 
          "font", measuredSize.toFixed(2), capSize.toFixed(2), "===>", Math.min(capSize, measuredSize).toFixed(2))
        console.log(props.card.id,
          "line", lineHeightbox, totalHeight, 340, Math.max(1.0,capSize/measuredSize).toFixed(2), Math.max(1.0,divSize/totalHeight).toFixed(2),
          "final line", lineHeight)
        return `font-size: ${Math.min(capSize, measuredSize,23).toFixed(2)}px; line-height: ${lineHeight};`;
      }
      return "";
    })

    const measureLine = (line:string, fontFamily:string, measurementSizeLenght: number, measurementSizeHeight: number) => {
      const lineIsBold = /^\|.*?\|$/.test(line);
      const lineIsHeirloom = /^%%.*?%%$/.test(line);
      const lineIsItalics = /^%.*?%$/.test(line);
      const lineIsSeparator = line === "---";
      const lineIsBlank = line === "";
      const lineIsBig = /^[\[{]!.*?[\]}]$/.test(line);
      const lineText = lineIsBold || lineIsItalics ? line.slice(1, -1) : line;

      let adjustedSize = measurementSizeLenght;
      if (lineIsBold) adjustedSize *= boldLineFactor;
      if (lineIsBig) adjustedSize *= bigLineFactor;
      if (lineIsSeparator) adjustedSize *= separatorFactor;
      if (lineIsBlank) adjustedSize *= blankFactor;
      if (lineIsHeirloom) adjustedSize *= heirloomFactor;

      console.log(props.card.id, adjustedSize, line)
      let totalLength = 0;
      let totalHeight = 0;
      if (!lineIsSeparator && !lineIsBlank) {
        const blocks = getBlocks(line);
        blocks.forEach((block) => {
          totalLength += measureBlock(block, fontFamily, adjustedSize).width;
          totalHeight += measureBlock(block, fontFamily, adjustedSize).height;
          console.log(props.card.id, totalLength, totalHeight, block.inner)
        });
      }
      return { width: totalLength, height: adjustedSize };
    }

    const measureBlock = (block: Block, fontFamily: string, measurementSize: number) => {     
      if (!ctx) throw new Error("Failed to get 2D context");
      let evaluatedSize
      let addition = 0;
      switch (block.type) {
        case "bigcoin": 
          measurementSize *= 1.1;
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
        case "bigcoin": // measurementSize*1.1
        case "bigcoin_singleline":
        case "bigcoin_singleline_noPlus":
        case "bigsun_singleline":
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
          console.log(block.type, block.inner)
          throw new Error(`Unknown block type: ${block.type}`);
      }
      evaluatedSize = ctx.measureText(block.inner); 
      const ascent = evaluatedSize.actualBoundingBoxAscent || measurementSize * 0.8; // Valeur par défaut si non supportée
      const descent = evaluatedSize.actualBoundingBoxDescent || measurementSize * 0.2; // Valeur par défaut si non supportée

      return {width : ctx.measureText(block.inner).width + addition, height : ascent + descent};  
    }

    const getCardTextBlockSize = (w: number, cardName: { isLandscape: boolean; hasHeirloom: boolean }) => {
      const { portraitRatio, landscapeRatio } = CardSizes.FULL;
      let height = 0;
      let width = 0;
      if (cardName.isLandscape) {
        const h = w * landscapeRatio;
        //const rect = new Rectangle(0.09 * w, 0.72 * h, 0.815 * w, 0.21 * h);
        height = 0.21 * h;
        width =  0.815 * w;
      } else {
        const h = w * portraitRatio;
        if (cardName.hasHeirloom) {
          //const rect = new Rectangle(0.1 * w, 0.55 * h, 0.795 * w, 0.27 * h);
          height = 0.27 * h;
          width = 0.795 * w;
        } else {
          //const rect = new Rectangle(0.1 * w, 0.55 * h, 0.795 * w, 0.31 * h);
          height = 0.31 * h;
          width = 0.795 * w;
        }
      }
      return { height: height, width: width };
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