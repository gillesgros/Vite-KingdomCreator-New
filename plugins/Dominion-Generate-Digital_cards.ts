import fs from 'fs';
import path from 'path';
import XLSX from 'xlsx'; 

// for function Convert_to_CSV
const ArtworkFileDir = 'design'
const ArtworkFileName = 'artwork.xlsx'
const DigitalCardFileName = 'digital-cards - '
const DigitalCardDir = 'src/dominion/digital_cards'

// Fonction pour convertir le fichier xlsx de messages traduit en CSV
function Generate_Digitalcard_for_set (setid:string) {
  console.log("Starting Generation of Digital Card", setid)
  const inputfile = path.join(ArtworkFileDir, ArtworkFileName)
  const outputfile = path.join(DigitalCardDir, DigitalCardFileName + setid +'.ts')
  XLSX.set_fs(fs);

  // Charger le fichier Excel source
  const workbook = XLSX.readFile(inputfile);
    // Sélectionner la feuille "Digital_cards"
  const worksheet = workbook.Sheets['digital_cards'];
    // Définir la plage à copier (A1:C250)
  const ranges = getRange(setid);
  let allData: any[] = [];
  
  ranges.forEach(range => {
    const mydata = XLSX.utils.sheet_to_json(worksheet, { range });
    // Concaténer les données de toutes les plages
    allData = allData.concat(mydata);
  });

  // Convertir les données en une chaîne de caractères, chaque ligne étant séparée par des retours à la ligne
  //const textData = mydata.map(row => Object.values(row as string ).join('\t')).join('\n');

  const textData = `import type { DigitalCard } from "./digital-cards-type";

export const Cards_list_${setid}: DigitalCard[] = [

${allData.map(row => Object.values(row as string).join('\t')).join('\n')}



];`;
  
  // Écrire les données dans le fichier texte
  fs.writeFileSync(outputfile, textData);


}

function getRange(setid:string) {
  // Tableau associatif pour mapper les valeurs de setid aux valeurs de range
  const rangeMap: { [key: string]: string [] }  = {
'Baseset': [ 'A5:C83', 'A104:C122' ],
'Alchemy': [ 'A143:C179' ],
'Seaside': [ 'A185:C263', 'A1868:C1895' ],
'Cornucopia': [ 'A263:C317' ],
'Prosperity': [ 'A317:C392', 'A1895:C1922' ],
'Intrigue': [ 'A404:C479', 'A500:C521' ],
'Guilds': [ 'A542:C581' ],
'Hinterlands': [ 'A581:C659', 'A1922:C1949' ],
'Darkages': [ 'A659:C827' ],
'Adventures': [ 'A827:C917', 'A977:C1001' ],
'Empires': [ 'A1001:C1103', 'A1205:C1229' ],
'Nocturne': [ 'A1229:C1328', 'A1364:C1409' ],
'Renaissance': [ 'A1460:C1535' ],
'Promo': [ 'A1610:C1652' ],
'Ménagerie': [ 'A1655:C1748' ],
'Allies': [ 'A1949:C2114' ],
'Plunder': [ 'A2183:C2348' ],
'Guildscornucopia': [ 'A2438:C2450' ],

    // ... ajoutez les autres paires ici
    'setid10': ['range10']
  };

  // Récupérer la valeur de range associée à setid
  const ranges = rangeMap[setid];

  // Si aucune valeur correspondante n'est trouvée, retourner un tableau vide
  return ranges || [];
}


export function Generate_Digitalcard () {

  Generate_Digitalcard_for_set('Baseset')
  Generate_Digitalcard_for_set('Alchemy')
  Generate_Digitalcard_for_set('Seaside')
  Generate_Digitalcard_for_set('Cornucopia')
  Generate_Digitalcard_for_set('Prosperity')
  Generate_Digitalcard_for_set('Intrigue')
  Generate_Digitalcard_for_set('Guilds')
  Generate_Digitalcard_for_set('Hinterlands')
  Generate_Digitalcard_for_set('Darkages')
  Generate_Digitalcard_for_set('Adventures')
  Generate_Digitalcard_for_set('Empires')
  Generate_Digitalcard_for_set('Nocturne')
  Generate_Digitalcard_for_set('Renaissance')
  Generate_Digitalcard_for_set('Promo')
  Generate_Digitalcard_for_set('Menagerie')
  Generate_Digitalcard_for_set('Allies')
  Generate_Digitalcard_for_set('Plunder')
  Generate_Digitalcard_for_set('Guildscornucopia')

}


