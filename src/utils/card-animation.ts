import gsap, { Sine } from "gsap";
import type { SupplyCard } from "../dominion/supply-card";

export interface Coordinate {
  x: number;
  y: number;
}

export interface MoveDescriptor {
  elementIndex: number;
  newVisualIndex: number;
}

export function getSupplyCardContainers(selector: string): NodeListOf<HTMLElement> {
  return document.querySelectorAll(selector) as NodeListOf<HTMLElement>;
}

export function getSupplyCardElement(index: number, selector: string): HTMLElement {
  return getSupplyCardContainers(selector)[index]!.firstElementChild! as HTMLElement;
}

export function getPositionForElementIndex(index: number, selector: string): Coordinate {
  const container = getSupplyCardContainers(selector)[index];
  return { x: container!.offsetLeft, y: container!.offsetTop };
}

export function getElementIndex(visualIndex: number, elementIndexMapping: Map<number, number>): number {
  return elementIndexMapping.has(visualIndex)
    ? elementIndexMapping.get(visualIndex)!
    : visualIndex;
}

export function cancelActiveAnimations(activeAnimations: Set<any>): void {
  for (const animation of activeAnimations) {
    animation.kill();
  }
  activeAnimations.clear();
}

export function resetCardPositions(
  cards: SupplyCard[],
  selector: string,
  elementIndexMapping: Map<number, number>,
  activeAnimations: Set<any>,
  duration: number = 0.6
): void {
  for (let visualIndex = 0; visualIndex < cards.length; visualIndex++) {
    const elementIndex = getElementIndex(visualIndex, elementIndexMapping);
    const element = getSupplyCardElement(elementIndex, selector);
    const startCoord = getPositionForElementIndex(elementIndex, selector);
    const endCoord = getPositionForElementIndex(visualIndex, selector);
    const x = endCoord.x - startCoord.x;
    const y = endCoord.y - startCoord.y;
    const activeAnimation = gsap.to(element, {
      duration: duration,
      x: x,
      y: y,
      ease: Sine.easeInOut,
      onComplete: function () {
        activeAnimation.kill();
        return;
      }
    });
    activeAnimations.add(activeAnimation);
  }
}

export function createMoveDescriptors(currentCards: SupplyCard[], sortedCards: SupplyCard[]): MoveDescriptor[] {
  const cardIds = currentCards.map((card) => card.id);
  const descriptors: MoveDescriptor[] = [];
  for (let newVisualIndex = 0; newVisualIndex < sortedCards.length; newVisualIndex++) {
    descriptors.push({
      newVisualIndex: newVisualIndex,
      elementIndex: cardIds.indexOf(sortedCards[newVisualIndex]!.id),
    });
  }
  return descriptors;
}

export function animateCardSort(
  currentCards: SupplyCard[],
  sortedCards: SupplyCard[],
  selector: string,
  elementIndexMapping: Map<number, number>,
  activeAnimations: Set<any>,
  duration: number = 0.6
): void {
  const descriptors = createMoveDescriptors(currentCards, sortedCards);
  const newMapping: Map<number, number> = new Map();

  for (let descriptor of descriptors) {
    const element = getSupplyCardElement(descriptor.elementIndex, selector);
    const startCoord = getPositionForElementIndex(descriptor.elementIndex, selector);
    const endCoord = getPositionForElementIndex(descriptor.newVisualIndex, selector);
    const x = endCoord.x - startCoord.x;
    const y = endCoord.y - startCoord.y;
    let activeAnimation = gsap.to(element, {
      duration: duration,
      x: x,
      y: y,
      force3D: true,
      ease: Sine.easeInOut,
      onComplete: function () {
        activeAnimation.kill();
        return;
      }
    });
    activeAnimations.add(activeAnimation);
    newMapping.set(descriptor.newVisualIndex, descriptor.elementIndex);
  }
  // Update the mapping
  elementIndexMapping.clear();
  for (const [key, value] of newMapping) {
    elementIndexMapping.set(key, value);
  }
}