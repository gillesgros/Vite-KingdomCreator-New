import { defineStore } from 'pinia';
import { useGoogleSyncStore } from '@/pinia/google-sync-store';
import { fetchHistoryFromGoogle, saveHistoryToGoogle } from '@/utils/google-sync';
import type { Kingdom } from '@/randomizer/kingdom';
import { decodeKingdomHash, generateKingdomHash } from '@/randomizer/kingdom-hash';

export const useHistoryStore = defineStore('historyStore', {
  state: () => ({
    // Le dictionnaire : { "hash": timestamp }
    playedKingdoms: {} as Record<string, number>,
    isLoading: false,
    error: null as string | null,
  }),

  actions: {
    /**
     * 📥 Charge l'historique depuis Google Drive (si connecté)
     */
    async loadHistory() {
      const googleSyncStore = useGoogleSyncStore();
      if (!googleSyncStore.isSignedIn) return;

      this.isLoading = true;
      this.error = null;
      try {
        const token = await googleSyncStore.getValidToken();
        this.playedKingdoms = await fetchHistoryFromGoogle(token);
        console.log('History loaded from Google Drive:', this.playedKingdoms);
// 2. 🔍 ZONE DE TEST VIA CONSOLE.LOG
    console.log("--- 📂 HISTORIQUE TÉLÉCHARGÉ ET DÉCODÉ ---");
    
    // On boucle sur toutes les clés (les hashs) du fichier récupéré
    Object.keys(this.playedKingdoms).forEach((hash) => {
      try {
        const originalText = decodeKingdomHash(hash);
        const dateJouee = new Date(this.playedKingdoms[hash]).toLocaleString();
        
        console.log(`[Joué le ${dateJouee}] -> ${originalText}`);
      } catch (decodeError) {
        console.error(`Impossible de décoder le hash ${hash}:`, decodeError);
      }
    });
    
    console.log("-----------------------------------------");

       } catch (err) {
        this.error = err instanceof Error ? err.message : 'Failed to load history';
        console.error(err);
      } finally {
        this.isLoading = false;
      }
    },

    /**
     * 💾 Ajoute un royaume à l'historique local ET le sauvegarde sur le Drive
     */
    async addKingdomToHistory(kingdom: Kingdom) {
      const hash = generateKingdomHash(kingdom);
      console.log (`Adding kingdom to history with hash: ${hash}`);
      // S'il est déjà présent, inutile de fatiguer le réseau ou de réécrire
      if (this.playedKingdoms[hash]) {
        console.log(`Kingdom is already in history.`);
        return;
      }
      // Ajout local immédiat (UI réactive)
      this.playedKingdoms[hash] = Date.now();

      // Sauvegarde distante si connecté
      const googleSyncStore = useGoogleSyncStore();
      if (googleSyncStore.isSignedIn) {
        try {
          const token = await googleSyncStore.getValidToken();
          await saveHistoryToGoogle(token, this.playedKingdoms);
          console.log('History saved to Google Drive successfully.');
        } catch (err) {
          console.error('Failed to sync added kingdom to Google Drive:', err);
          alert('Failed to sync added kingdom to Google Drive');
          // On peut choisir de lever une erreur ou de gérer un mode hors-ligne ici
        }
      }
    },

    /**
     * 🔍 Vérifie instantanément en mémoire si un royaume a déjà été joué
     */
    isKingdomAlreadyPlayed(kingdom: Kingdom): boolean {
      const hash = generateKingdomHash(kingdom);
      return !!this.playedKingdoms[hash];
    },

    /**
     * 🧹 Vide l'historique local (par exemple lors d'une déconnexion)
     */
    clearLocalHistory() {
      this.playedKingdoms = {};
    }
  },
});