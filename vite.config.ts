import { defineConfig } from 'vite';
import fs from 'fs';
import path from 'path';

import vue from '@vitejs/plugin-vue';
import vueI18n from '@intlify/unplugin-vue-i18n/vite';
import { createMpaPlugin } from 'vite-plugin-virtual-mpa';

import UnPluginVueComponents from 'unplugin-vue-components/vite'; // On-demand components auto importing for Vue.
import DominionContentPlugin from './plugins/dominion-content-plugin';
import rollupDel from 'rollup-plugin-delete';
const languages = ['fr', 'de', 'es', 'nl', 'pl']; // Liste des langues à fusionner
const languageSourceDir = './src/i18n/locales'
const languageDestDir = './docs/locales'

export default defineConfig(({ mode }) => {
  if (mode == "production") {
    mergeJSONLanguageFiles();
    const sourceFile = './styles/normalize-v8.css';
    const destinationFile = './docs/normalize-v8.css';
    fs.copyFile('./styles/normalize-v8.css', './docs/normalize-v8.css',()=>{
      console.log(`Le fichier a été copié avec succès de ${sourceFile} vers ${destinationFile}`);
    })
  }

  return {
    plugins: [
      {
        ...DominionContentPlugin(),
        enforce: "pre",
      },
      UnPluginVueComponents({
        // Chemin vers le dossier qui contient les composants
        dirs: ['src/components'],
        // Filtre des fichiers de composants
        extensions: ['vue'],
        // Options pour le plugin vue-router (optionnel)
        deep: true,
      }),
      vue(),
      vueI18n({
        include: path.resolve(__dirname, './docs/locales/*.json'),
        compositionOnly: true, fullInstall: false,
        allowDynamic: true
      }),
      /* import { createMpaPlugin } from 'vite-plugin-virtual-mpa'; */
      createMpaPlugin({
        htmlMinify: false,
        verbose: false,
        pages: [
          {
            name: "index",
            filename:  "index.html",
            template: "./views/layout.html",
            data: {
              injectDominioncontent: `<script src="./dominion-content.js"></script>`,
              injectEntry: `<script type="module" src="./src/index-page.ts"></script>`,
            }
          },
          {
            name: "sets",
            filename:  "sets.html",
            template: "./views/layout.html",
            data: {
              injectDominioncontent: `<script src="./dominion-content.js"></script>`,
              injectEntry: `<script type="module" src="./src/sets-page.ts"></script>`,
            }
          },
          {
            name: "rules",
            filename: "rules.html",
            template: "./views/layout.html",
            data: {
              injectDominioncontent: '<script src="./dominion-content.js"></script>',
              injectEntry: '<script type="module" src="./src/rules-page.ts"></script>',
            }
          },
          {
            name: "boxes",
            filename:  "boxes.html",
            template: "./views/layout.html",
            data: {
              injectDominioncontent: `<script src="./dominion-content.js"></script>`,
              injectEntry: `<script type="module" src="./src/boxes-page.ts"></script>`,
            }
          }
        ]
      }),
      mode == "development" ? rollupDel({
        targets: ['docs/*',
          '!docs/rules',
          '!docs/rules.fr',
          '!docs/img',
          '!docs/favicon.ico',
          '!docs/dominion-content.js',
          '!docs/normalize-v8.css',
          '!docs/locales',
          '!docs/locales/??.json',
          '!docs/CNAME',
          '!docs/ads.txt'],
        verbose: false
      })
        : rollupDel({
          targets: ['docs/*',
            '!docs/rules',
            '!docs/rules.fr',
            '!docs/img',
            '!docs/favicon.ico',
            '!docs/dominion-content.js',
            '!docs/normalize-v8.css',
            '!docs/locales',
            '!docs/locales/??.json',
            '!docs/CNAME',
            '!docs/ads.txt'],
          verbose: false
        })
    ],
    optimizeDeps: {
      include: ['vue', 'vue-i18n']
    },
    resolve: {
      //extensions: [".ts", ".vue", ".json"],
      extensions: ['.ts', '.vue'],
      alias: {
        // Alias pour les modules non-Esbuild compatibles avec Vite
        'vue-i18n': 'vue-i18n/dist/vue-i18n.esm-browser.js',
        'vue': 'vue/dist/vue.cjs.js',
      },
    },
    build: {
      minify: false,
      outDir: 'docs',
      emptyOutDir: false,
      chunkSizeWarningLimit: 2000,
      rollupOptions: {
        output: {
          entryFileNames: '[name]-[hash].js',
          chunkFileNames: 'chunk-[name]-[hash].js',
          assetFileNames: '[name]-[hash][extname]'
        } /*,
      external: [
        'src/rules-pages.ts'
      ]*/
      },
    },
    server: {
      port: 8090,
      proxy: {
        '/dominion-content.js': {
          target: 'http://localhost:8090',
          rewrite: (path) => path.replace(/^\/dominion-content.js/, '/docs/dominion-content.js'),
        },
        '/normalize': {
          target: 'http://localhost:8090',
          rewrite: (path) => path.replace(/^\/normalize/, '/docs/normalize'),
        },
        '/favicon.ico': {
          target: 'http://localhost:8090',
          rewrite: (path) => path.replace(/^\/favicon.ico/, '/docs/favicon.ico'),
        },
        '/img': {
          target: 'http://localhost:8090',
          rewrite: (path) => path.replace(/^\/img/, '/docs/img'),
        },
        '/rules': {
          target: 'http://localhost:8090',
          rewrite: (path) => path.replace(/^\/rules/, '/docs/rules'),
        },
        '/locales': {
          target: 'http://localhost:8090',
          rewrite: (path) => path.replace(/^\/locales/, '/docs/locales'),
        },
      },
    },
    preview: {
      proxy:{}
    }
  }
});


// Fonction pour créer une struture de répertoire si elle n'existe pas
function testExistAndCreateDir(dirPath: string) {
  if (!fs.existsSync(dirPath)) {
    fs.mkdirSync(dirPath, { recursive: true });
  }
}

// Fonction pour lire le contenu d'un fichier JSON
function readJsonFile(filePath: string): any {
  const fileContents = fs.readFileSync(filePath, 'utf8');
  return JSON.parse(fileContents);
}

// Fonction pour écrire le contenu d'un objet dans un fichier JSON
function writeJsonFile(filePath: string, data: any) {
  const json = JSON.stringify(data, null, 2);
  fs.writeFileSync(filePath, json, 'utf8');
}

// Fonction pour merger ds JSON dans un seul fichier JSON
function mergeJSONLanguageFiles() {
  for (const lang of languages) {
    // Chemins vers les répertoires contenant les fichiers JSON à fusionner
    const messagesDir = path.join(__dirname, languageSourceDir, 'messages', lang);
    const cardsDir = path.join(__dirname, languageSourceDir, 'messages', lang, 'cards');
    //console.log(messagesDir)

    // Chemin vers le fichier de sortie
    const outputFile = path.join(__dirname, languageDestDir, `${lang}.json`);
    testExistAndCreateDir(path.join(__dirname, languageDestDir));


    // Fusionne tous les fichiers JSON dans le répertoire messagesDir
    const messagesFiles = fs.readdirSync(messagesDir)
      .filter(file => file.includes(`.${lang}.`) && file.endsWith('.json'))
      .map(file => path.join(messagesDir, file));
    //console.log(messagesFiles)
    const messages = messagesFiles.reduce((result, file) => {
      const data = readJsonFile(file);
      return { ...result, ...data };
    }, {});
    // Fusionne tous les fichiers JSON dans le répertoire cardsDir
    let cards 
    if (fs.existsSync(cardsDir)) {
    const cardsFiles = fs.readdirSync(cardsDir)
      .filter(file => file.includes(`.${lang}.`) && file.endsWith('.json'))
      .map(file => path.join(cardsDir, file));

      cards = cardsFiles.reduce((result, file) => {
      const data = readJsonFile(file);
      return { ...result, ...data };
    }, {});
  }
    // Fusionne les données et écrit le fichier de sortie
    const mergedData = { ...messages, ...cards };
    //console.log(outputFile)

    writeJsonFile(outputFile, mergedData);
  }
}