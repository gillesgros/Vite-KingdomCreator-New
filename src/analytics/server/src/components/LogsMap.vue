<template>
  <div class="map-panel" aria-label="Carte géographique des journaux filtrés">
    <div ref="mapElement" class="map" />
    <p v-if="mappedLogCount === 0" class="map-note">
      Aucune position connue pour les événements affichés.
    </p>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import * as L from 'leaflet';
import type { AnalyticsLogLocation } from '../types/analytics';

interface MapPoint {
  key: string;
  latitude: number;
  longitude: number;
  zoom: number;
  label: string;
  count: number;
}

const props = defineProps<{
  locations: AnalyticsLogLocation[];
}>();

const countryCenters: Record<string, [number, number, number]> = {
  US: [39.8, -98.5, 4], CA: [56.1, -106.3, 3], FR: [46.6, 2.2, 5], DE: [51.1, 10.4, 5],
  GB: [54.5, -2.5, 5], IT: [42.5, 12.5, 5], ES: [40.4, -3.7, 5], NL: [52.1, 5.3, 6],
  BE: [50.5, 4.4, 7], CH: [46.8, 8.2, 7], AT: [47.5, 14.5, 6], FI: [62.5, 26.0, 5],
  SE: [61.0, 15.0, 5], NO: [61.5, 8.5, 5], DK: [56.0, 10.0, 6], PL: [52.0, 19.0, 5],
  SG: [1.35, 103.8, 10], KR: [36.5, 127.8, 6], JP: [36.2, 138.2, 5], CN: [35.8, 104.1, 4],
  IN: [20.5, 78.9, 4], AU: [-25.2, 133.7, 4], NZ: [-41.0, 174.0, 5], ZA: [-29.0, 24.0, 5],
  BR: [-14.2, -51.9, 4], AR: [-34.0, -64.0, 4], MX: [23.6, -102.5, 5],
};

function getPosition(location: AnalyticsLogLocation): Omit<MapPoint, 'label' | 'count'> | null {
  const { latitude, longitude } = location;
  if (
    latitude !== null &&
    longitude !== null &&
    latitude >= -90 &&
    latitude <= 90 &&
    longitude >= -180 &&
    longitude <= 180
  ) {
    return {
      key: `coordinates:${latitude.toFixed(3)},${longitude.toFixed(3)}`,
      latitude,
      longitude,
      zoom: 6,
    };
  }

  const countryCode = location.country.toUpperCase();
  const center = countryCenters[countryCode];
  if (!center) return null;
  return {
    key: `country:${countryCode}`,
    latitude: center[0],
    longitude: center[1],
    zoom: center[2],
  };
}

const points = computed<MapPoint[]>(() => {
  const grouped = new Map<string, MapPoint>();

  for (const location of props.locations) {
    const position = getPosition(location);
    if (!position) continue;

    const label = [
      location.city,
      location.subdivision,
      location.country,
    ].filter(Boolean).join(' / ') || 'Localisation inconnue';
    const existing = grouped.get(position.key);

    if (existing) {
      existing.count++;
    } else {
      grouped.set(position.key, { ...position, label, count: 1 });
    }
  }

  return [...grouped.values()];
});

const mappedLogCount = computed(() =>
  points.value.reduce((total, point) => total + point.count, 0),
);

const mapElement = ref<HTMLDivElement | null>(null);
let mapInstance: L.Map | null = null;
let markersLayer: L.LayerGroup | null = null;

function renderPoints(): void {
  if (!mapInstance || !markersLayer) return;
  markersLayer.clearLayers();

  for (const point of points.value) {
    const popup = document.createElement('div');
    popup.textContent = `${point.label} — ${point.count} événement(s)`;
    L.circleMarker([point.latitude, point.longitude], {
      radius: Math.min(7 + Math.sqrt(point.count) * 2, 18),
      color: '#173f78',
      weight: 1.5,
      fillColor: '#4d8bd6',
      fillOpacity: 0.72,
    }).bindPopup(popup).addTo(markersLayer);
  }

  if (points.value.length === 1) {
    const point = points.value[0];
    mapInstance.setView([point.latitude, point.longitude], point.zoom);
  } else if (points.value.length > 1) {
    const bounds = L.latLngBounds(points.value.map((point) => [point.latitude, point.longitude]));
    mapInstance.fitBounds(bounds, { padding: [24, 24], maxZoom: 7 });
  } else {
    mapInstance.setView([22, 10], 2);
  }
  mapInstance.invalidateSize();
}

onMounted(() => {
  const element = mapElement.value;
  if (!element) return;

  mapInstance = L.map(element, { minZoom: 1.5 }).setView([22, 10], 2);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    minZoom: 1.5,
    maxZoom: 8,
    attribution: '© OpenStreetMap contributors',
  }).addTo(mapInstance);
  markersLayer = L.layerGroup().addTo(mapInstance);
  renderPoints();
});

watch(points, renderPoints);

onBeforeUnmount(() => {
  mapInstance?.remove();
  mapInstance = null;
  markersLayer = null;
});
</script>

<style scoped>
.map-panel {
  min-width: 0;
}

.map-note {
  color: #64748b;
  font-size: .82rem;
}

.map {
  z-index: 0;
  height: 550px;
  border: 1px solid #cbd5e1;
  border-radius: .4rem;
  background: #edf2f7;
}

.map-note {
  margin: .5rem 0 0;
}

@media (max-width: 600px) {
  .map {
    height: 320px;
  }
}
</style>
