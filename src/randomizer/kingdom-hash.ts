import type { Kingdom } from '@/randomizer/kingdom';

/**
 * Génère un identifiant unique stable (Hash) à partir d'un objet Kingdom.
 * Tous les éléments sont triés par leur identifiant (.id) pour garantir
 * que l'ordre du tirage n'influence pas le résultat final.
 */
export function generateKingdomHash(kingdom: Kingdom): string {
  const supply = kingdom.supply;

  // 1. Extraction et tri des 10 cartes de la réserve principale
  const supplyIds = supply.supplyCards.map(c => c.id).sort().join(',');

  // 2. Extraction et tri des paysages / Addons
  const eventIds = kingdom.events.map(e => e.id).sort().join(',');
  const landmarkIds = kingdom.landmarks.map(l => l.id).sort().join(',');
  const projectIds = kingdom.projects.map(p => p.id).sort().join(',');
  const wayIds = kingdom.ways.map(w => w.id).sort().join(',');
  const traitIds = kingdom.traits.map(t => t.id).sort().join(',');

  // 3. Extraction des cartes spéciales liées au Supply
  const baneId = supply.baneCard?.id || '';
  const ferrymanId = supply.ferrymanCard?.id || '';
  const obeliskCardId = supply.obeliskCard?.id || '';
  const mouseWayId = supply.mouseWay?.id || '';
  const riverboatId = supply.riverboatCard?.id || '';
  const approachingArmyId = supply.approachingArmyCard?.id || '';

  // 4. Extraction et tri des cartes associées aux Traits (traitsSupply)
  const traitSupplyIds = supply.traitsSupply.map(c => c.id).sort().join(',');

  // 5. Éléments uniques optionnels (Ally et Prophecy)
  const allyId = kingdom.ally?.id || '';
  const prophecyId = kingdom.prophecy?.id || '';

  // 6. Options de jeu (Metadata)
  const meta = `c:${kingdom.metadata.useColonies ? 1 : 0},s:${kingdom.metadata.useShelters ? 1 : 0}`;

  // 7. Reconstruction d'une chaîne normalisée ultra-stricte
  const normalizedString = 
    `main:${supplyIds}|` +
    `special:${baneId},${ferrymanId},${obeliskCardId},${mouseWayId},${riverboatId},${approachingArmyId}|` +
    `addons:${eventIds};${landmarkIds};${projectIds};${wayIds};${traitIds}|` +
    `traitSupply:${traitSupplyIds}|` +
    `unique:${allyId},${prophecyId}|` +
    `meta:${meta}`;

  // 8. Encodage moderne en Base64 URL-Safe (Remplace unescape de manière sûre)
  const bytes = new TextEncoder().encode(normalizedString);
  const binString = Array.from(bytes, (byte) => String.fromCharCode(byte)).join("");
  
  return btoa(binString)
    .replace(/\+/g, '-')
    .replace(/\//g, '_')
    .replace(/=+$/, '');
}

/**
 * Opération inverse : prend un Hash Base64 URL-Safe et reconstruit
 * la chaîne normalisée d'origine (ex: "main:cellar,chapel...|addons:...")
 */
export function decodeKingdomHash(hash: string): string {
  // 1. On remet les caractères Base64 standards (+ et /) à la place des caractères URL-Safe
  let base64 = hash.replace(/-/g, '+').replace(/_/g, '/');

  // 2. On rajoute le bourrage "=" (padding) requis par atob si la longueur n'est pas un multiple de 4
  while (base64.length % 4) {
    base64 += '=';
  }

  // 3. Décodage du Base64 en chaîne binaire
  const binString = atob(base64);

  // 4. Conversion de la chaîne binaire en tableau d'octets (Uint8Array)
  const bytes = Uint8Array.from(binString, (m) => m.charCodeAt(0));

  // 5. Lecture des octets en UTF-8 pour retrouver le texte d'origine
  return new TextDecoder().decode(bytes);
}