<template>
  <div class="map-panel" aria-label="Carte géographique des journaux filtrés">
    <div ref="mapElement" class="map" />
    <p class="map-note">
      Affichage limité à 2 000 localisations. Les points des pays et subdivisions sont indicatifs.
      <span v-if="approximate">Les comptes sont estimatifs lorsque la limite est dépassée.</span>
    </p>
    <p v-if="mappedLogCount === 0" class="map-note">
      Aucune position connue pour les événements affichés.
    </p>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import * as L from 'leaflet';
import type { AnalyticsLogLocation, AnalyticsTrafficLocation } from '../types/analytics';
import {
  countryCenters,
  countryNames,
  getSubdivisionCenter,
  getSubdivisionKey,
} from '../mapGeography';

interface MapPoint {
  key: string;
  latitude: number;
  longitude: number;
  zoom: number;
  label: string;
  count: number;
}

const props = defineProps<{
  locations: AnalyticsTrafficLocation[];
  approximate?: boolean;
  subdivisionMode?: boolean;
}>();

function getPosition(location: AnalyticsLogLocation): Omit<MapPoint, 'label' | 'count'> | null {
  const countryCode = location.country.toUpperCase();
  if (props.subdivisionMode && location.subdivision) {
    const subdivisionCenter = getSubdivisionCenter(countryCode, location.subdivision);
    if (subdivisionCenter) {
      return {
        key: `subdivision:${getSubdivisionKey(countryCode, location.subdivision)}`,
        latitude: subdivisionCenter[0],
        longitude: subdivisionCenter[1],
        zoom: subdivisionCenter[2],
      };
    }
  }

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

    const countryCode = location.country.toUpperCase();
    const label = [
      location.city,
      location.subdivision,
      countryNames[countryCode] ?? location.country,
    ].filter(Boolean).join(' / ') || 'Localisation inconnue';
    const existing = grouped.get(position.key);

    if (existing) {
      existing.count += location.count;
    } else {
      grouped.set(position.key, { ...position, label, count: location.count });
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
