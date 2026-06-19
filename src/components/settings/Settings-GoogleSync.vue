<template>
  <div class="setting-panel">
    <div class="SettingTitle">Google Sync</div>
    <div class="sets-description">Save and restore your settings across browsers using Google Drive.</div>

    <div v-if="!isGoogleConfigured" class="google-sync-message google-sync-warning">
      Google Drive sync is not configured yet. Set <code>VITE_GOOGLE_CLIENT_ID</code> in your environment to enable it.
    </div>

    <div v-else class="google-sync-actions">
<!-- 🛠️ Nouveau bouton personnalisé à la place du composant GoogleLogin -->
        <button 
          v-if="!googleSyncStore.isSignedIn && googleClientId" 
          class="custom-google-btn" 
          @click="googleSyncStore.signInAndInit()"
          :disabled="googleSyncStore.isLoading"
        >
          <svg class="google-icon" viewBox="0 0 24 24">
            <path fill="#EA4335" d="M12.24 10.285V14.4h6.887c-.275 1.565-1.88 4.604-6.887 4.604-4.33 0-7.866-3.577-7.866-8s3.536-8 7.866-8c2.46 0 4.105 1.025 5.047 1.926l3.227-3.11C18.436 2.064 15.62 1 12.24 1 5.92 1 1 5.92 1 12.24s4.92 11.24 11.24 11.24c6.6 0 11-4.63 11-11.24 0-.756-.08-1.333-.178-1.955H12.24z"/>
          </svg>
          <span>Se connecter avec Google</span>
        </button>
        <button v-else class="settingsButton" @click="googleSyncStore.signOut()">
          Disconnect Google
        </button>
      <button class="settingsButton" @click="googleSyncStore.saveToGoogle()" :disabled="googleSyncStore.isLoading || !googleSyncStore.isSignedIn">
        Save to Drive
      </button>
      <button class="settingsButton" @click="googleSyncStore.restoreFromGoogle()" :disabled="googleSyncStore.isLoading || !googleSyncStore.isSignedIn">
        Restore from Drive
      </button>
    </div>

    <div v-if="googleSyncStore.profile" class="google-sync-profile">
      <img v-if="googleSyncStore.profile.picture" :src="googleSyncStore.profile.picture" alt="Google profile" />
      <span>{{ googleSyncStore.profile.name || googleSyncStore.profile.email }}</span>
    </div>

    <div v-if="googleSyncStore.lastMessage" class="google-sync-message">
      {{ googleSyncStore.lastMessage }}
    </div>
  </div>
</template>

<script lang="ts">
import { computed, defineComponent } from 'vue';
import { useGoogleSyncStore } from '@/pinia/google-sync-store';
import { getGoogleClientId, isGoogleSyncAvailable } from '@/utils/google-sync';

export default defineComponent({
  name: 'GoogleSyncSettings',
  setup() {
    const googleSyncStore = useGoogleSyncStore();
    const isGoogleConfigured = computed(() => isGoogleSyncAvailable());
    const googleClientId = computed(() => getGoogleClientId());

    return {
      googleSyncStore,
      isGoogleConfigured,
      googleClientId
      };
  },
});
</script>

<style scoped>
.setting-panel {
  background: #f7f7f7;
  border: 1px solid #ddd;
  border-radius: 6px;
  padding: 16px;
  margin: 12px;
  min-width: 320px;
  flex: 1 1 320px;
}

.google-sync-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 12px;
}

.google-sync-profile {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 12px;
}

.google-sync-profile img {
  width: 32px;
  height: 32px;
  border-radius: 50%;
}

.google-sync-message {
  margin-top: 10px;
  font-size: 0.9rem;
  color: #555;
}

.google-sync-warning {
  color: #8a4b00;
  background: #fff7e6;
  border: 1px solid #f0c36d;
  padding: 10px;
  border-radius: 4px;
}


.settingsButton:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.custom-google-btn {
  display: flex;
  align-items: center;
  background-color: #1a73e8;
  color: white;
  border: none;
  border-radius: 4px;
  padding: 0 12px 0 0;
  font-family: 'Roboto', sans-serif;
  font-size: 14px;
  font-weight: 500;
  cursor: pointer;
  height: 40px;
  box-shadow: 0 1px 2px rgba(0,0,0,0.2);
  transition: background-color 0.2s;
}

.custom-google-btn:hover {
  background-color: #1557b0;
}

.custom-google-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.google-icon {
  background: white;
  padding: 8px;
  border-radius: 3px 0 0 3px;
  margin-right: 12px;
  width: 24px;
  height: 24px;
}
</style>
