/**
 * Фронтенд smart-route-planner.
 *
 * Форма отправляется через fetch на api/route.php, без перезагрузки страницы.
 * Ответ — JSON с оптимизированным порядком точек, координатами, реальной
 * дорожной геометрией (если доступен OSRM), временем в пути, стоимостью
 * поездки и предсказанным транспортом. Карта строится через MapLibre GL JS
 * на бесплатных векторных данных OpenFreeMap — без API-ключа.
 *
 * Дополнительно:
 * - ссылка-шеринг кодирует структурированные остановки и координаты прямо
 *   в URL (base64) — без базы данных. Legacy-ссылки со строкой городов также
 *   остаются совместимыми;
 * - структурированный редактор остановок (route-editor.js);
 * - переключатель языка (RU/EN) — см. i18n.js;
 * - PWA: регистрация service worker + подсказка "установить приложение".
 */

const form = document.getElementById('route-form');
const submitButton = document.getElementById('submit-button');
const resultSection = document.getElementById('result-section');
const errorBanner = document.getElementById('error-banner');
const warningBanner = document.getElementById('warning-banner');
const mapPlaceholder = document.getElementById('map-placeholder');
const mapContainer = document.getElementById('map');
const shareButton = document.getElementById('share-button');
const shareToast = document.getElementById('share-toast');
const pointsInput = document.getElementById('points');
const costFuelPrice = document.getElementById('cost-fuel-price');
const costFuelConsumption = document.getElementById('cost-fuel-consumption');
const costTicketPrice = document.getElementById('cost-ticket-price');
const mapModeControl = document.getElementById('map-mode-control');
const mapPanel = document.querySelector('.map-panel');
const routePanel = document.querySelector('.panel');
const mapSceneStatus = document.getElementById('map-scene-status');
const mapSceneStatusText = document.getElementById('map-scene-status-text');
const mapTripSummary = document.getElementById('map-trip-summary');
const routePointCount = document.getElementById('route-point-count');
const mapStyleChip = document.getElementById('map-style-chip');
const mapStyleIndicator = document.getElementById('map-style-indicator');

let routeMap = null;
let routeMarkers = [];
let poiMarkers = [];
let poiVisible = false;
let poiFetchedForRoute = null; // хранит JSON.stringify(coords) маршрута, для которого уже запрашивали POI
let lastRouteData = null;
let lastMapRender = null;
let routeAnimationFrame = null;
let routeSceneToken = 0;
let routeScenePhase = 'idle';
let mapStyleFallbackTimer = null;
let routeDrawingRetryTimer = null;

const ROUTE_DRAWING_RETRY_DELAY_MS = 120;
const ROUTE_DRAWING_RETRY_LIMIT = 25;

const MAP_MODE_STORAGE_KEY = 'srp_map_mode';
const ROUTE_SOURCE_ID = 'route-geometry';
const ROUTE_CASING_LAYER_ID = 'route-casing';
const ROUTE_GLOW_LAYER_ID = 'route-glow';
const ROUTE_LINE_LAYER_ID = 'route-line';
const ROUTE_HIGHLIGHT_LAYER_ID = 'route-highlight';
const BUILDINGS_SOURCE_ID = 'openfreemap-buildings';
const BUILDINGS_LAYER_ID = 'route-map-3d-buildings';

let currentMapMode = (() => {
    try {
        return localStorage.getItem(MAP_MODE_STORAGE_KEY) === '2d' ? '2d' : '3d';
    } catch (e) {
        return '3d';
    }
})();

// Векторные стили OpenFreeMap работают без регистрации и API-ключа.
// Они заменяют старые raster-тайлы, на которых провайдер начал показывать
// водяной знак API KEY REQUIRED.
const MAP_STYLES = {
    dark: 'https://tiles.openfreemap.org/styles/fiord',
    light: 'https://tiles.openfreemap.org/styles/liberty',
};

function currentMapTheme() {
    return document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
}

function updateMapStyleChip(theme = currentMapTheme()) {
    if (!mapStyleChip) return;
    mapStyleChip.textContent = theme === 'light' ? t('mapStyleLiberty') : t('mapStyleFiord');
}

function prefersReducedMotion() {
    return typeof window.matchMedia === 'function'
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

function supportsWebGlMap() {
    if (typeof maplibregl === 'undefined' || typeof maplibregl.Map !== 'function') {
        return false;
    }

    if (typeof maplibregl.supported === 'function') {
        try {
            return maplibregl.supported();
        } catch (e) {
            // Some privacy-focused browsers throw during the capability probe.
        }
    }

    try {
        const canvas = document.createElement('canvas');
        return Boolean(canvas.getContext('webgl2', {
            failIfMajorPerformanceCaveat: false,
            antialias: true,
        }) || canvas.getContext('webgl'));
    } catch (e) {
        return false;
    }
}

function setRouteScenePhase(phase) {
    routeScenePhase = phase;

    if (!mapSceneStatus || !mapSceneStatusText) {
        return;
    }

    const phaseKeys = {
        calculating: 'mapStatusCalculating',
        framing: 'mapStatusFraming',
        drawing: 'mapStatusDrawing',
        ready: 'mapStatusReady',
    };
    const key = phaseKeys[phase];

    if (!key) {
        hide(mapSceneStatus);
        return;
    }

    mapSceneStatusText.textContent = t(key);
    show(mapSceneStatus);
}

function populateMapSummary(data) {
    if (!data || !mapTripSummary) return;

    document.getElementById('map-summary-distance').textContent = data.distance_km + ' км';
    document.getElementById('map-summary-time').textContent = data.duration.label;

    const modeLabels = t('transportModes');
    document.getElementById('map-summary-mode').textContent = modeLabels[data.transport.mode]
        || data.transport.mode_ru
        || data.transport.mode;
    document.getElementById('map-summary-source').textContent = routingSourceLabel(data, true);
}

function routingProviderLabel(provider) {
    const keys = {
        osrm_public_demo: 'routingProviderProject',
        osrm_fossgis_public: 'routingProviderFossgis',
        osrm_configured: 'routingProviderConfigured',
        osrm_failover_chain: 'routingProviderFailover',
    };
    return t(keys[provider] || 'routingProviderGeneric');
}

function routingSourceLabel(data, compact = false) {
    if (!data || data.routing_source !== 'osrm_road') {
        return compact ? t('mapSummaryFallbackSource') : t('routingStraight');
    }

    const provider = routingProviderLabel(data.routing_provider);
    const base = compact
        ? t('mapSummaryRoadSourceProvider')(provider)
        : t('routingRoadProvider')(provider);
    const details = [];
    if (data.routing_cache_status === 'stale') {
        details.push(t('routingStaleCache'));
    } else if (data.routing_cached) {
        details.push(t('routingFreshCache'));
    }
    if (data.routing_failover_used) {
        details.push(t('routingBackupUsed'));
    }

    return details.length > 0 ? base + ' · ' + details.join(' · ') : base;
}

function updateRoutePointCount() {
    if (!routePointCount || !pointsInput) return;
    const count = pointsInput.value.split(';').filter((point) => point.trim() !== '').length;
    routePointCount.textContent = t('routePointCount')(count);
}

window.refreshRouteUiLanguage = function () {
    updateRoutePointCount();
    updateMapModeButtons();
    updateMapStyleChip();
    const timelineItems = document.querySelectorAll('#points-list li');
    timelineItems.forEach((item, index) => {
        const role = item.querySelector('.point-role');
        if (!role) return;
        role.textContent = index === 0
            ? t('timelineStart')
            : (index === timelineItems.length - 1 ? t('timelineFinish') : t('timelineStop'));
    });
    if (routeScenePhase !== 'idle') {
        setRouteScenePhase(routeScenePhase);
    }
    if (lastRouteData) {
        populateMapSummary(lastRouteData);
    }
};

// Вызывается из ui.js при переключении светлой/тёмной темы, чтобы перекрасить
// карту вслед за интерфейсом. После смены стиля восстанавливаются 3D-здания
// и линия уже рассчитанного маршрута.
window.setMapTileTheme = function (theme) {
    const normalized = theme === 'light' ? 'light' : 'dark';
    updateMapStyleChip(normalized);

    if (routeMap) {
        try {
            routeMap.setStyle(MAP_STYLES[normalized]);
        } catch (error) {
            console.warn('[Smart Route Planner] Could not switch map style:', error);
        }
        return;
    }

    if (lastMapRender && mapPanel && mapPanel.classList.contains('map-static')) {
        renderStaticRouteMap(lastMapRender.coords, lastMapRender.labels, lastMapRender.routeGeometry);
    }
};

/**
 * A/B-тест MLP vs Softmax: каждому визитёру назначается вариант один раз
 * (50/50) и хранится в localStorage — весь визит человек видит предсказания
 * от одной и той же модели, что и нужно для честного сравнения.
 */
function getAbVariant() {
    let variant = localStorage.getItem('ab_model_variant');
    if (variant !== 'mlp' && variant !== 'softmax') {
        variant = Math.random() < 0.5 ? 'mlp' : 'softmax';
        localStorage.setItem('ab_model_variant', variant);
    }
    return variant;
}

form.addEventListener('submit', (event) => {
    event.preventDefault();
    calculateRoute(form.elements['points'].value);
});

pointsInput.addEventListener('input', updateRoutePointCount);

shareButton.addEventListener('click', () => {
    const urlObject = new URL(location.origin + location.pathname);
    const stops = window.routeEditor?.getStops();
    if (Array.isArray(stops) && stops.length >= 2) {
        urlObject.searchParams.set('s', utf8ToBase64(JSON.stringify(stops)));
    } else {
        urlObject.searchParams.set('r', utf8ToBase64(form.elements['points'].value));
    }
    const url = urlObject.toString();

    if (typeof navigator.share === 'function') {
        navigator.share({ title: document.title, text: dataShareText(), url }).then(() => {
            showShareToast(t('shareCopied'));
        }).catch(() => {});
        return;
    }

    navigator.clipboard.writeText(url).then(() => {
        showShareToast(t('shareCopied'));
    }).catch(() => {
        showShareToast(t('shareCopyFailed')(url));
    });
});

function dataShareText() {
    if (!lastRouteData) return 'Smart Route Planner';
    return lastRouteData.points.join(' → ') + ' · ' + lastRouteData.distance_km + ' км';
}

// ?s= хранит структурированные точки с координатами; старый ?r= со строкой
// городов остаётся совместимым. Оба варианта запускают расчёт автоматически.
window.addEventListener('DOMContentLoaded', () => {
    const params = new URLSearchParams(location.search);
    const structured = params.get('s');
    const shared = params.get('r');

    if (structured) {
        try {
            const stops = JSON.parse(base64ToUtf8(structured));
            if (!Array.isArray(stops) || stops.length < 2) throw new Error('Invalid shared stops');
            window.routeEditor?.setStops(stops);
            calculateRoute(form.elements['points'].value);
        } catch (e) {
            showError(t('shareLinkBroken'));
        }
    } else if (shared) {
        try {
            const points = base64ToUtf8(shared);
            form.elements['points'].value = points;
            window.routeEditor?.setFromLegacy(points);
            calculateRoute(points);
        } catch (e) {
            showError(t('shareLinkBroken'));
        }
    }
});

async function calculateRoute(points) {
    hide(errorBanner);
    hide(warningBanner);
    setLoading(true);

    try {
        const body = new URLSearchParams();
        body.set('points', points);
        if (window.routeEditor) body.set('stops_json', window.routeEditor.serialize());
        body.set('optimize_order', document.getElementById('optimize-order')?.checked ? '1' : '0');
        body.set('fuel_price_per_liter', costFuelPrice.value);
        body.set('fuel_consumption_l_100km', costFuelConsumption.value);
        body.set('ticket_price_per_km', costTicketPrice.value);
        body.set('model_variant', getAbVariant());

        const controller = new AbortController();
        const timeout = window.setTimeout(() => controller.abort(), 45_000);
        const response = await fetch('api/route.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
            signal: controller.signal,
        });
        window.clearTimeout(timeout);

        const data = await response.json();

        if (!data.ok) {
            showError(translateError(data.error_code, data.error));
            return;
        }

        renderResult(data);
    } catch (e) {
        showError(t('genericNetworkError'));
    } finally {
        setLoading(false);
    }
}

function setLoading(isLoading) {
    submitButton.disabled = isLoading;
    submitButton.textContent = isLoading ? t('submitLoading') : t('submitIdle');
    submitButton.setAttribute('aria-busy', isLoading ? 'true' : 'false');
    routePanel?.classList.toggle('is-calculating', isLoading);

    if (isLoading) {
        hide(mapTripSummary);
        setRouteScenePhase('calculating');
    } else if (routeScenePhase === 'calculating') {
        setRouteScenePhase('idle');
    }
}

function showError(message) {
    errorBanner.textContent = '⚠️ ' + message;
    show(errorBanner);
    if (routeScenePhase === 'calculating') {
        setRouteScenePhase('idle');
    }
}

function showShareToast(message) {
    shareToast.textContent = message;
    show(shareToast);
    setTimeout(() => hide(shareToast), 3500);
}

function renderResult(data) {
    lastRouteData = data;
    populateMapSummary(data);
    show(resultSection);
    resultSection.classList.remove('result-entering');
    // Restart the entrance choreography when a different route is calculated.
    void resultSection.offsetWidth;
    resultSection.classList.add('result-entering');

    document.getElementById('stat-stops').textContent = data.stops;
    document.getElementById('stat-distance').textContent = data.distance_km + ' км';

    const modeLabels = t('transportModes');
    document.getElementById('stat-transport').textContent = modeLabels[data.transport.mode] || data.transport.mode_ru;
    document.getElementById('stat-time').textContent = data.duration.label;

    const currency = t('currency');
    document.getElementById('stat-cost').textContent = data.cost.amount > 0
        ? formatNumber(data.cost.amount) + ' ' + currency
        : '0 ' + currency;
    document.getElementById('cost-note').textContent = t('costNote')(data.transport.mode);

    const co2 = data.emissions;
    document.getElementById('stat-emissions').textContent = co2.co2_kg + ' кг';
    const compareEntries = Object.entries(co2.comparison)
        .filter(([mode]) => mode !== data.transport.mode)
        .map(([mode, kg]) => modeLabelFor(mode) + ': ' + kg + ' кг');
    document.getElementById('emissions-compare').textContent = compareEntries.length
        ? t('emissionsNote') + ' (' + compareEntries.join(', ') + ')'
        : t('emissionsNote');

    const routingNote = document.getElementById('routing-source-note');
    routingNote.textContent = routingSourceLabel(data);

    const confidenceFill = document.getElementById('confidence-fill');
    confidenceFill.style.width = data.transport.confidence + '%';
    document.getElementById('confidence-label').textContent = t('confidenceLabel')(data.transport.confidence);

    const list = document.getElementById('points-list');
    list.innerHTML = '';
    data.points.forEach((p, i) => {
        const li = document.createElement('li');
        li.dataset.pointIndex = String(i);
        const pointRole = i === 0
            ? t('timelineStart')
            : (i === data.points.length - 1 ? t('timelineFinish') : t('timelineStop'));
        li.innerHTML = '<span class="point-index">' + (i + 1) + '</span>'
            + '<span class="point-copy"><span class="point-label">' + escapeHtml(p) + '</span>'
            + '<small class="point-role">' + escapeHtml(pointRole) + '</small></span>'
            + '<span class="point-weather" data-weather-slot="' + i + '"></span>';
        list.appendChild(li);
    });

    document.getElementById('link-google').href = data.maps.google;
    document.getElementById('link-yandex').href = data.maps.yandex;

    if (data.skipped && data.skipped.length > 0) {
        warningBanner.textContent = t('skippedWarning')(data.skipped);
        show(warningBanner);
    }

    renderMap(data.coords, data.points, data.route_geometry);

    // Сбрасываем состояние доп.фич (погода/AI-совет/POI) для нового маршрута —
    // иначе на экране могла бы остаться карточка от предыдущего расчёта.
    resetPoiState();
    window.updateMlLabForRoute?.(data);
    resetDayPlanState();
    loadWeatherAndAssistant(data);
}

/**
 * Погода по точкам маршрута (Open-Meteo) + AI-комментарий к поездке.
 * Запрашиваются отдельно от основного расчёта маршрута, чтобы медленные
 * внешние API (LLM, погода) не блокировали показ уже посчитанного маршрута.
 */
async function loadWeatherAndAssistant(data) {
    const assistantCard = document.getElementById('assistant-card');
    const assistantText = document.getElementById('assistant-text');
    const assistantSource = document.getElementById('assistant-source');

    assistantText.textContent = t('assistantLoading');
    assistantSource.textContent = '';
    show(assistantCard);

    const weatherPoints = data.coords.map((c, i) => ({ lat: c.lat, lon: c.lon, label: data.points[i] }));

    let forecast = [];
    try {
        const body = new URLSearchParams();
        body.set('points', JSON.stringify(weatherPoints));

        const response = await fetch('api/weather.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        });
        const weatherData = await response.json();

        if (weatherData.ok) {
            forecast = weatherData.forecast;
            renderWeatherBadges(forecast);
        }
    } catch (e) {
        // Погода недоступна (сеть/внешний API) — просто не показываем бейджи,
        // основной сценарий (маршрут) уже отображён и не пострадал.
    }

    try {
        const body = new URLSearchParams();
        body.set('route', JSON.stringify(data));
        body.set('weather', JSON.stringify(forecast));

        const response = await fetch('api/assistant.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        });
        const assistantData = await response.json();

        if (assistantData.ok) {
            assistantText.textContent = assistantData.narrative.text;
            assistantSource.textContent = assistantData.narrative.source === 'llm'
                ? t('assistantSourceLlm')
                : t('assistantSourceFallback');
        } else {
            assistantText.textContent = t('assistantError');
        }
    } catch (e) {
        assistantText.textContent = t('assistantError');
    }
}

function renderWeatherBadges(forecast) {
    forecast.forEach((w, i) => {
        const slot = document.querySelector('[data-weather-slot="' + i + '"]');
        if (!slot || !w || w.temperature_c === null) {
            return;
        }

        const warnPrefix = w.warning ? t('weatherWarningPrefix') + ' ' : '';
        slot.textContent = warnPrefix + w.icon + ' ' + Math.round(w.temperature_c) + '°C';
        slot.title = w.description_ru + (w.warning ? ' — ' + w.warning_reason : '');
        if (w.warning) {
            slot.classList.add('weather-warning');
        }
    });
}

/* ------------------------------------------------------------------ *
 * Точки интереса рядом с маршрутом (Overpass API): АЗС/кафе/рестораны/отели.
 * Запрашиваются только по клику — бесплатный публичный API, не стоит
 * дёргать его на каждый расчёт маршрута, если пользователю это не нужно.
 * ------------------------------------------------------------------ */

const poiButton = document.getElementById('poi-button');
const poiEmptyNote = document.getElementById('poi-empty-note');

poiButton.addEventListener('click', async () => {
    if (!lastRouteData) {
        return;
    }

    if (poiVisible) {
        hidePoiLayer();
        return;
    }

    const routeKey = JSON.stringify(lastRouteData.coords);

    if (poiMarkers.length > 0 && poiFetchedForRoute === routeKey) {
        // Уже загружали POI для этого маршрута — просто показываем заново.
        poiMarkers.forEach((marker) => marker.addTo(routeMap));
        poiVisible = true;
        poiButton.textContent = t('poiButtonHide');
        return;
    }

    poiButton.disabled = true;
    poiButton.textContent = t('poiButtonLoading');
    hide(poiEmptyNote);

    try {
        const points = lastRouteData.coords.map((c, i) => ({
            lat: c.lat, lon: c.lon, label: lastRouteData.points[i],
        }));

        const body = new URLSearchParams();
        body.set('points', JSON.stringify(points));

        const response = await fetch('api/poi.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        });
        const data = await response.json();

        if (data.ok) {
            renderPoiMarkers(data.points);
            poiFetchedForRoute = routeKey;
            poiVisible = true;
            poiButton.textContent = t('poiButtonHide');

            const totalPlaces = data.points.reduce((sum, p) => sum + p.places.length, 0);
            if (totalPlaces === 0) {
                show(poiEmptyNote);
            }
        } else {
            show(poiEmptyNote);
            poiButton.textContent = t('poiButton');
        }
    } catch (e) {
        show(poiEmptyNote);
        poiButton.textContent = t('poiButton');
    } finally {
        poiButton.disabled = false;
    }
});

function renderPoiMarkers(pointsWithPlaces) {
    poiMarkers.forEach((marker) => marker.remove());
    poiMarkers = [];
    const categoryLabels = t('poiCategoryLabels');

    pointsWithPlaces.forEach((point) => {
        point.places.forEach((place) => {
            const element = document.createElement('div');
            element.className = 'poi-marker';
            element.innerHTML = '<span class="poi-marker-icon">' + place.icon + '</span>';

            const popup = new maplibregl.Popup({ offset: 18, closeButton: false })
                .setHTML('<strong>' + escapeHtml(place.name) + '</strong><br>'
                    + escapeHtml(categoryLabels[place.category] || place.label_ru));

            const marker = new maplibregl.Marker({ element, anchor: 'center' })
                .setLngLat([place.lon, place.lat])
                .setPopup(popup)
                .addTo(routeMap);
            poiMarkers.push(marker);
        });
    });
}

function hidePoiLayer() {
    poiMarkers.forEach((marker) => marker.remove());
    poiVisible = false;
    poiButton.textContent = t('poiButton');
}

function resetPoiState() {
    poiMarkers.forEach((marker) => marker.remove());
    poiMarkers = [];
    poiVisible = false;
    poiFetchedForRoute = null;
    poiButton.disabled = false;
    poiButton.textContent = t('poiButton');
    hide(poiEmptyNote);
}

function modeLabelFor(mode) {
    const labels = t('transportModes');
    return labels[mode] || mode;
}

function formatNumber(n) {
    return new Intl.NumberFormat(getLang() === 'en' ? 'en-US' : 'ru-RU').format(n);
}

function findFirstLabelLayerId() {
    if (!routeMap || !routeMap.getStyle()) return undefined;
    const layers = routeMap.getStyle().layers || [];
    const labelLayer = layers.find((layer) => layer.type === 'symbol' && layer.layout && layer.layout['text-field']);
    return labelLayer ? labelLayer.id : undefined;
}

function add3dBuildingsLayer() {
    if (!routeMap || routeMap.getLayer(BUILDINGS_LAYER_ID)) return;

    try {
        if (!routeMap.getSource(BUILDINGS_SOURCE_ID)) {
            routeMap.addSource(BUILDINGS_SOURCE_ID, {
                type: 'vector',
                url: 'https://tiles.openfreemap.org/planet',
            });
        }

        const dark = currentMapTheme() === 'dark';
        routeMap.addLayer({
            id: BUILDINGS_LAYER_ID,
            source: BUILDINGS_SOURCE_ID,
            'source-layer': 'building',
            type: 'fill-extrusion',
            minzoom: 13.2,
            filter: ['!=', ['get', 'hide_3d'], true],
            layout: {
                visibility: currentMapMode === '3d' ? 'visible' : 'none',
            },
            paint: {
                'fill-extrusion-color': [
                    'interpolate', ['linear'], ['coalesce', ['get', 'render_height'], 0],
                    0, dark ? '#252d40' : '#d9dbea',
                    70, dark ? '#485476' : '#b9b6d8',
                    180, dark ? '#7565ae' : '#9a8cca',
                    320, dark ? '#67cbd3' : '#5da8b0',
                ],
                'fill-extrusion-height': [
                    'interpolate', ['linear'], ['zoom'],
                    13.2, 0,
                    15, ['coalesce', ['get', 'render_height'], 8],
                ],
                'fill-extrusion-base': ['coalesce', ['get', 'render_min_height'], 0],
                'fill-extrusion-opacity': dark ? 0.82 : 0.72,
                'fill-extrusion-vertical-gradient': true,
            },
        }, findFirstLabelLayerId());
    } catch (error) {
        // Buildings are an optional 3D enhancement. Route layers must still load.
        console.warn('[Smart Route Planner] 3D buildings are unavailable:', error);
    }
}

function routeCoordinates(routeGeometry) {
    if (!Array.isArray(routeGeometry)) return [];

    return routeGeometry.map((point) => Array.isArray(point)
        ? [Number(point[1]), Number(point[0])]
        : [Number(point.lon), Number(point.lat)])
        .filter((point) => Number.isFinite(point[0]) && Number.isFinite(point[1]));
}

function routeGeoJsonFromCoordinates(coordinates) {
    const safeCoordinates = coordinates.length === 1
        ? [coordinates[0], coordinates[0]]
        : coordinates;

    return {
        type: 'Feature',
        properties: {},
        geometry: {
            type: 'LineString',
            coordinates: safeCoordinates,
        },
    };
}

function routeGeoJson(routeGeometry) {
    return routeGeoJsonFromCoordinates(routeCoordinates(routeGeometry));
}

function routePoint(point) {
    if (Array.isArray(point)) {
        return { lat: Number(point[0]), lon: Number(point[1]) };
    }
    return { lat: Number(point.lat), lon: Number(point.lon) };
}

function renderStaticRouteMap(coords, labels, routeGeometry) {
    if (routeAnimationFrame !== null) {
        cancelAnimationFrame(routeAnimationFrame);
        routeAnimationFrame = null;
    }
    clearTimeout(mapStyleFallbackTimer);
    clearTimeout(routeDrawingRetryTimer);

    try {
        if (routeMap && typeof routeMap.remove === 'function') {
            routeMap.remove();
        }
    } catch (ignored) {
        // Replacing the container is enough when a partial WebGL instance cannot be removed.
    }
    routeMap = null;
    routeMarkers = [];

    const sourcePoints = (Array.isArray(routeGeometry) && routeGeometry.length > 1
        ? routeGeometry
        : coords).map(routePoint)
        .filter((point) => Number.isFinite(point.lat) && Number.isFinite(point.lon));

    if (sourcePoints.length < 2) {
        mapContainer.innerHTML = '<div class="map-webgl-error">' + escapeHtml(t('mapWebglError')) + '</div>';
        show(mapContainer);
        hide(mapPlaceholder);
        hide(mapModeControl);
        hide(mapStyleIndicator);
        return null;
    }

    const maxSvgPoints = 520;
    const sampleEvery = Math.max(1, Math.ceil(sourcePoints.length / maxSvgPoints));
    const sampled = sourcePoints.filter((point, index) => index % sampleEvery === 0);
    const finalPoint = sourcePoints[sourcePoints.length - 1];
    if (sampled[sampled.length - 1] !== finalPoint) sampled.push(finalPoint);

    let minLon = Infinity;
    let maxLon = -Infinity;
    let minLat = Infinity;
    let maxLat = -Infinity;
    sourcePoints.forEach((point) => {
        minLon = Math.min(minLon, point.lon);
        maxLon = Math.max(maxLon, point.lon);
        minLat = Math.min(minLat, point.lat);
        maxLat = Math.max(maxLat, point.lat);
    });

    const lonSpan = Math.max(maxLon - minLon, 0.02);
    const latSpan = Math.max(maxLat - minLat, 0.02);
    const width = 1000;
    const height = 620;
    const padding = 84;
    const project = (point) => ({
        x: padding + ((point.lon - minLon) / lonSpan) * (width - padding * 2),
        y: height - padding - ((point.lat - minLat) / latSpan) * (height - padding * 2),
    });

    const path = sampled.map((point, index) => {
        const projected = project(point);
        return (index === 0 ? 'M' : 'L') + projected.x.toFixed(1) + ' ' + projected.y.toFixed(1);
    }).join(' ');

    const markerSvg = coords.map((coord, index) => {
        const point = project(routePoint(coord));
        const markerClass = index === 0 ? ' static-marker-start'
            : (index === coords.length - 1 ? ' static-marker-end' : '');
        const shortLabel = index === 0 ? 'A' : (index === coords.length - 1 ? 'B' : String(index + 1));
        return '<g class="static-route-marker' + markerClass + '" transform="translate('
            + point.x.toFixed(1) + ' ' + point.y.toFixed(1) + ')">'
            + '<circle r="18"></circle><circle class="static-route-marker-ring" r="25"></circle>'
            + '<text text-anchor="middle" dominant-baseline="central">' + shortLabel + '</text></g>';
    }).join('');

    const endpointLabels = [0, coords.length - 1]
        .filter((index, position, values) => values.indexOf(index) === position)
        .map((index) => {
            const point = project(routePoint(coords[index]));
            const label = escapeHtml(labels[index] || '');
            const anchor = index === 0 ? 'start' : 'end';
            const offset = index === 0 ? 30 : -30;
            return '<text class="static-route-label" x="' + (point.x + offset).toFixed(1) + '" y="'
                + (point.y - 27).toFixed(1) + '" text-anchor="' + anchor + '">' + label + '</text>';
        }).join('');

    const dark = currentMapTheme() === 'dark';
    const backgroundStart = dark ? '#0d1320' : '#e9e8f2';
    const backgroundEnd = dark ? '#171b30' : '#d9e4eb';
    const gridStroke = dark ? 'rgba(146,164,194,.12)' : 'rgba(70,64,105,.13)';

    mapContainer.innerHTML = '<div class="static-route-map">'
        + '<svg viewBox="0 0 1000 620" role="img" aria-label="' + escapeHtml(t('mapStaticMode')) + '">'
        + '<defs><linearGradient id="static-map-bg" x1="0" y1="0" x2="1" y2="1">'
        + '<stop offset="0" stop-color="' + backgroundStart + '"></stop>'
        + '<stop offset="1" stop-color="' + backgroundEnd + '"></stop></linearGradient>'
        + '<linearGradient id="static-route-line" x1="0" y1="0" x2="1" y2="0">'
        + '<stop offset="0" stop-color="#9d7bff"></stop><stop offset=".56" stop-color="#48dbe3"></stop>'
        + '<stop offset="1" stop-color="#ffb547"></stop></linearGradient>'
        + '<filter id="static-route-glow"><feGaussianBlur stdDeviation="9" result="blur"></feGaussianBlur>'
        + '<feMerge><feMergeNode in="blur"></feMergeNode><feMergeNode in="SourceGraphic"></feMergeNode></feMerge></filter>'
        + '<pattern id="static-grid" width="72" height="72" patternUnits="userSpaceOnUse">'
        + '<path d="M72 0H0V72" fill="none" stroke="' + gridStroke + '" stroke-width="1"></path></pattern></defs>'
        + '<rect width="1000" height="620" fill="url(#static-map-bg)"></rect>'
        + '<rect width="1000" height="620" fill="url(#static-grid)"></rect>'
        + '<path d="M-40 420C170 320 310 500 510 374S810 250 1060 334V660H-40Z" fill="rgba(77,91,135,.11)"></path>'
        + '<path class="static-route-glow" d="' + path + '"></path>'
        + '<path class="static-route-path" d="' + path + '"></path>'
        + '<path class="static-route-highlight" d="' + path + '"></path>'
        + markerSvg + endpointLabels + '</svg>'
        + '<div class="static-route-note">' + escapeHtml(t('mapStaticMode')) + '</div></div>';

    hide(mapPlaceholder);
    show(mapContainer);
    hide(mapModeControl);
    hide(mapStyleIndicator);
    mapPanel?.classList.remove('map-preview', 'map-framing', 'map-drawing');
    mapPanel?.classList.add('map-route-active', 'map-static', 'map-ready');
    show(mapTripSummary);
    setRouteScenePhase('ready');
    window.setTimeout(() => {
        if (routeScenePhase === 'ready') setRouteScenePhase('idle');
    }, prefersReducedMotion() ? 0 : 900);
    return null;
}

function clearRouteLayers() {
    if (!routeMap || !routeMap.isStyleLoaded()) return;
    [ROUTE_HIGHLIGHT_LAYER_ID, ROUTE_LINE_LAYER_ID, ROUTE_GLOW_LAYER_ID, ROUTE_CASING_LAYER_ID]
        .forEach((layerId) => {
            if (routeMap.getLayer(layerId)) routeMap.removeLayer(layerId);
        });
    if (routeMap.getSource(ROUTE_SOURCE_ID)) routeMap.removeSource(ROUTE_SOURCE_ID);
}

function addRouteLayers() {
    if (!routeMap || !lastMapRender || routeMap.getSource(ROUTE_SOURCE_ID)) return;

    routeMap.addSource(ROUTE_SOURCE_ID, {
        type: 'geojson',
        data: routeGeoJson(lastMapRender.routeGeometry),
        lineMetrics: true,
    });

    const beforeId = findFirstLabelLayerId();
    routeMap.addLayer({
        id: ROUTE_CASING_LAYER_ID,
        type: 'line',
        source: ROUTE_SOURCE_ID,
        layout: {
            'line-cap': 'round',
            'line-join': 'round',
        },
        paint: {
            'line-color': currentMapTheme() === 'dark' ? '#080d16' : '#ffffff',
            'line-width': ['interpolate', ['linear'], ['zoom'], 3, 7, 10, 12, 16, 18],
            'line-opacity': 0.9,
        },
    }, beforeId);

    routeMap.addLayer({
        id: ROUTE_GLOW_LAYER_ID,
        type: 'line',
        source: ROUTE_SOURCE_ID,
        layout: {
            'line-cap': 'round',
            'line-join': 'round',
        },
        paint: {
            'line-color': '#48dbe3',
            'line-width': ['interpolate', ['linear'], ['zoom'], 3, 11, 10, 18, 16, 28],
            'line-opacity': 0.26,
            'line-blur': 7,
        },
    }, beforeId);

    routeMap.addLayer({
        id: ROUTE_LINE_LAYER_ID,
        type: 'line',
        source: ROUTE_SOURCE_ID,
        layout: {
            'line-cap': 'round',
            'line-join': 'round',
        },
        paint: {
            'line-width': ['interpolate', ['linear'], ['zoom'], 3, 3, 10, 5, 16, 8],
            'line-opacity': 0.98,
            'line-gradient': [
                'interpolate', ['linear'], ['line-progress'],
                0, '#9d7bff',
                0.48, '#48dbe3',
                0.78, '#73e4e9',
                1, '#ffb547',
            ],
        },
    }, beforeId);

    routeMap.addLayer({
        id: ROUTE_HIGHLIGHT_LAYER_ID,
        type: 'line',
        source: ROUTE_SOURCE_ID,
        layout: {
            'line-cap': 'round',
            'line-join': 'round',
        },
        paint: {
            'line-color': 'rgba(255,255,255,0.82)',
            'line-width': ['interpolate', ['linear'], ['zoom'], 3, 0.6, 10, 1.05, 16, 1.55],
            'line-opacity': 0.66,
            'line-dasharray': [0.7, 2.4],
        },
    }, beforeId);
}

function restoreMapLayers() {
    if (!routeMap || !routeMap.isStyleLoaded()) return;
    try {
        add3dBuildingsLayer();
    } catch (error) {
        console.warn('[Smart Route Planner] Could not restore 3D buildings:', error);
    }
    try {
        addRouteLayers();
    } catch (error) {
        console.warn('[Smart Route Planner] Could not restore route layers:', error);
    }
    try {
        applyMapMode(false);
    } catch (error) {
        console.warn('[Smart Route Planner] Could not restore map mode:', error);
    }
}

function updateMapModeButtons() {
    document.querySelectorAll('[data-map-mode]').forEach((button) => {
        const active = button.dataset.mapMode === currentMapMode;
        button.classList.toggle('active', active);
        button.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
}

function applyMapMode(animate = true) {
    updateMapModeButtons();
    if (!routeMap) return;

    try {
        if (routeMap.getLayer(BUILDINGS_LAYER_ID)) {
            routeMap.setLayoutProperty(
                BUILDINGS_LAYER_ID,
                'visibility',
                currentMapMode === '3d' ? 'visible' : 'none'
            );
        }
    } catch (error) {
        console.warn('[Smart Route Planner] Could not toggle 3D buildings:', error);
    }

    const motionAllowed = animate && !prefersReducedMotion();
    try {
        routeMap.easeTo({
            pitch: currentMapMode === '3d' ? 58 : 0,
            bearing: currentMapMode === '3d' ? -22 : 0,
            duration: motionAllowed ? 850 : 0,
            essential: false,
        });
    } catch (error) {
        console.warn('[Smart Route Planner] Could not animate map mode:', error);
    }
}

function setMapMode(mode) {
    currentMapMode = mode === '2d' ? '2d' : '3d';
    try {
        localStorage.setItem(MAP_MODE_STORAGE_KEY, currentMapMode);
    } catch (e) {
        // Карта продолжает работать, даже если браузер запретил localStorage.
    }
    applyMapMode(true);
}

document.querySelectorAll('[data-map-mode]').forEach((button) => {
    button.addEventListener('click', () => setMapMode(button.dataset.mapMode));
});
updateMapModeButtons();

function createRouteMap() {
    if (!supportsWebGlMap()) {
        hide(mapModeControl);
        hide(mapStyleIndicator);
        return null;
    }

    try {
        mapContainer.innerHTML = '';
        routeMap = new maplibregl.Map({
            container: 'map',
            style: MAP_STYLES[currentMapTheme()],
            center: [13.5, 47.2],
            zoom: 3.6,
            pitch: currentMapMode === '3d' ? 48 : 0,
            bearing: currentMapMode === '3d' ? -18 : 0,
            maxPitch: 70,
            attributionControl: false,
            canvasContextAttributes: { antialias: true },
        });
    } catch (error) {
        console.warn('[Smart Route Planner] WebGL map creation failed:', error);
        routeMap = null;
        return null;
    }

    show(mapStyleIndicator);
    routeMap.addControl(new maplibregl.NavigationControl({ visualizePitch: true }), 'top-left');
    routeMap.addControl(new maplibregl.AttributionControl({
        compact: true,
        customAttribution: 'OpenFreeMap · OpenMapTiles · OpenStreetMap',
    }), 'bottom-right');
    if (typeof maplibregl.ScaleControl === 'function') {
        routeMap.addControl(new maplibregl.ScaleControl({
            maxWidth: 110,
            unit: 'metric',
        }), 'bottom-right');
    }

    routeMap.on('style.load', () => {
        restoreMapLayers();
    });
    routeMap.on('error', (event) => {
        if (event && event.error) {
            console.warn('[Smart Route Planner] MapLibre:', event.error.message || event.error);
        }
    });

    return routeMap;
}

function initRouteMapPreview() {
    updateRoutePointCount();
    updateMapStyleChip();
    if (!supportsWebGlMap()) {
        hide(mapStyleIndicator);
        return;
    }

    show(mapContainer);
    show(mapModeControl);
    show(mapStyleIndicator);
    mapPanel?.classList.add('map-preview');

    if (!routeMap) createRouteMap();
    if (routeMap) {
        window.requestAnimationFrame(() => routeMap?.resize());
    }
}

document.addEventListener('DOMContentLoaded', initRouteMapPreview);

function renderRouteMarkers(coords, labels) {
    routeMarkers.forEach((marker) => marker.remove());
    routeMarkers = [];

    coords.forEach((coord, index) => {
        const isStart = index === 0;
        const isEnd = index === coords.length - 1 && coords.length > 1;
        let badgeClass = 'route-marker-badge';
        let content = String(index + 1);

        if (isStart) {
            badgeClass += ' route-marker-start';
            content = '🚩';
        } else if (isEnd) {
            badgeClass += ' route-marker-end';
            content = '🏁';
        }

        const element = document.createElement('div');
        element.className = 'route-marker';
        element.innerHTML = '<span class="' + badgeClass + '" style="animation-delay:'
            + (index * 55) + 'ms">' + content + '</span>';

        const popup = new maplibregl.Popup({ offset: 22, closeButton: false })
            .setHTML('<div class="route-popup"><span class="route-popup-index">' + (index + 1)
                + '</span><span class="route-popup-label">' + escapeHtml(labels[index]) + '</span></div>');

        const marker = new maplibregl.Marker({ element, anchor: 'center' })
            .setLngLat([coord.lon, coord.lat])
            .setPopup(popup)
            .addTo(routeMap);
        routeMarkers.push(marker);
    });
}

function sampledRouteCoordinates(coordinates, maxPoints = 720) {
    if (coordinates.length <= maxPoints) return coordinates;
    const step = Math.ceil(coordinates.length / maxPoints);
    const sampled = coordinates.filter((point, index) => index % step === 0);
    const finalPoint = coordinates[coordinates.length - 1];
    if (sampled[sampled.length - 1] !== finalPoint) sampled.push(finalPoint);
    return sampled;
}

function animateRouteLine(routeGeometry, token, onComplete) {
    if (!routeMap || token !== routeSceneToken) return;
    const source = routeMap.getSource(ROUTE_SOURCE_ID);
    const fullCoordinates = routeCoordinates(routeGeometry);
    if (!source || fullCoordinates.length < 2 || typeof source.setData !== 'function') {
        onComplete();
        return;
    }

    if (prefersReducedMotion()) {
        source.setData(routeGeoJsonFromCoordinates(fullCoordinates));
        onComplete();
        return;
    }

    const coordinates = sampledRouteCoordinates(fullCoordinates);
    const duration = Math.min(2200, 1250 + coordinates.length * 1.35);
    const startedAt = performance.now();
    let lastPaintAt = 0;

    const drawFrame = (now) => {
        if (!routeMap || token !== routeSceneToken) return;

        const progress = Math.min(1, (now - startedAt) / duration);
        const eased = 1 - Math.pow(1 - progress, 3);
        if (progress === 1 || now - lastPaintAt >= 30) {
            lastPaintAt = now;
            const scaledIndex = eased * (coordinates.length - 1);
            const wholeIndex = Math.floor(scaledIndex);
            const partial = coordinates.slice(0, wholeIndex + 1);
            const fraction = scaledIndex - wholeIndex;

            if (wholeIndex < coordinates.length - 1) {
                const current = coordinates[wholeIndex];
                const next = coordinates[wholeIndex + 1];
                partial.push([
                    current[0] + (next[0] - current[0]) * fraction,
                    current[1] + (next[1] - current[1]) * fraction,
                ]);
            }

            try {
                source.setData(routeGeoJsonFromCoordinates(partial));
            } catch (error) {
                console.warn('[Smart Route Planner] Route animation stopped:', error);
                try {
                    source.setData(routeGeoJsonFromCoordinates(fullCoordinates));
                } catch (ignored) {
                    // A simultaneous style change can invalidate the old source object.
                }
                onComplete();
                return;
            }
        }

        if (progress < 1) {
            routeAnimationFrame = requestAnimationFrame(drawFrame);
            return;
        }

        try {
            source.setData(routeGeoJsonFromCoordinates(fullCoordinates));
        } catch (ignored) {
            // A theme switch can replace the style on the animation's final frame.
        }
        routeAnimationFrame = null;
        onComplete();
    };

    routeAnimationFrame = requestAnimationFrame(drawFrame);
}

function finishRouteScene(token) {
    if (token !== routeSceneToken) return;
    clearTimeout(mapStyleFallbackTimer);
    clearTimeout(routeDrawingRetryTimer);
    mapPanel?.classList.remove('map-framing', 'map-drawing', 'map-static');
    mapPanel?.classList.add('map-ready');
    show(mapTripSummary);
    setRouteScenePhase('ready');

    window.setTimeout(() => {
        if (token === routeSceneToken && routeScenePhase === 'ready') {
            setRouteScenePhase('idle');
        }
    }, prefersReducedMotion() ? 0 : 900);
}

function mapStyleCanAcceptRouteLayers() {
    if (!routeMap) return false;

    try {
        const style = routeMap.getStyle();
        // `isStyleLoaded()` also becomes false while optional terrain/source
        // data is loading. The style JSON itself is already safe to extend as
        // soon as its layer collection exists.
        return Boolean(style && Array.isArray(style.layers) && style.layers.length > 0);
    } catch (error) {
        return false;
    }
}

function beginRouteDrawing(coords, labels, routeGeometry, token, attempt = 0) {
    if (!routeMap || token !== routeSceneToken) return;

    if (!mapStyleCanAcceptRouteLayers()) {
        if (attempt >= ROUTE_DRAWING_RETRY_LIMIT) {
            console.warn('[Smart Route Planner] Map style did not become editable; using the static route view.');
            renderStaticRouteMap(coords, labels, routeGeometry);
            return;
        }

        clearTimeout(routeDrawingRetryTimer);
        routeDrawingRetryTimer = window.setTimeout(
            () => beginRouteDrawing(coords, labels, routeGeometry, token, attempt + 1),
            ROUTE_DRAWING_RETRY_DELAY_MS
        );
        return;
    }

    clearTimeout(routeDrawingRetryTimer);
    clearTimeout(mapStyleFallbackTimer);
    mapPanel?.classList.remove('map-framing');
    mapPanel?.classList.add('map-drawing');
    setRouteScenePhase('drawing');

    try {
        clearRouteLayers();
        addRouteLayers();
        const source = routeMap.getSource(ROUTE_SOURCE_ID);
        const coordinates = routeCoordinates(routeGeometry);
        if (source && coordinates.length > 0) {
            source.setData(routeGeoJsonFromCoordinates([coordinates[0], coordinates[0]]));
        }
        renderRouteMarkers(coords, labels);
        animateRouteLine(routeGeometry, token, () => finishRouteScene(token));
    } catch (error) {
        console.error('[Smart Route Planner] Interactive route drawing failed:', error);
        renderStaticRouteMap(coords, labels, routeGeometry);
    }
}

function renderMap(coords, labels, routeGeometry) {
    hide(mapPlaceholder);
    show(mapContainer);
    lastMapRender = { coords, labels, routeGeometry };
    const token = ++routeSceneToken;
    clearTimeout(mapStyleFallbackTimer);
    clearTimeout(routeDrawingRetryTimer);

    if (routeAnimationFrame !== null) {
        cancelAnimationFrame(routeAnimationFrame);
        routeAnimationFrame = null;
    }

    mapPanel?.classList.remove('map-preview', 'map-ready', 'map-drawing', 'map-static');
    mapPanel?.classList.add('map-route-active', 'map-framing');
    hide(mapTripSummary);
    setRouteScenePhase('framing');

    if (!routeMap && !createRouteMap()) {
        renderStaticRouteMap(coords, labels, routeGeometry);
        return;
    }
    show(mapModeControl);

    routeMap.resize();
    routeMarkers.forEach((marker) => marker.remove());
    routeMarkers = [];
    if (routeMap.isStyleLoaded()) {
        try {
            clearRouteLayers();
        } catch (error) {
            console.warn('[Smart Route Planner] Could not clear the previous route:', error);
        }
    }

    const bounds = new maplibregl.LngLatBounds();
    coords.forEach((coord) => bounds.extend([Number(coord.lon), Number(coord.lat)]));
    const compact = window.innerWidth < 700;
    const cameraDuration = prefersReducedMotion() ? 0 : 1150;
    let drawingStarted = false;
    const startDrawing = () => {
        if (drawingStarted || token !== routeSceneToken) return;
        drawingStarted = true;
        beginRouteDrawing(coords, labels, routeGeometry, token);
    };

    routeMap.once('moveend', startDrawing);
    try {
        routeMap.fitBounds(bounds, {
            padding: compact
                ? { top: 100, right: 34, bottom: 122, left: 34 }
                : { top: 96, right: 78, bottom: 130, left: 78 },
            maxZoom: 15.5,
            pitch: currentMapMode === '3d' ? 58 : 0,
            bearing: currentMapMode === '3d' ? -22 : 0,
            duration: cameraDuration,
            essential: false,
        });
    } catch (error) {
        console.warn('[Smart Route Planner] Camera framing failed:', error);
        startDrawing();
    }

    window.setTimeout(startDrawing, cameraDuration + 180);
    mapStyleFallbackTimer = window.setTimeout(() => {
        if (token === routeSceneToken && routeMap && routeScenePhase !== 'ready' && routeScenePhase !== 'idle') {
            console.warn('[Smart Route Planner] Route scene timed out; using the static route view.');
            renderStaticRouteMap(coords, labels, routeGeometry);
        }
    }, 9000);
}

function show(el) {
    el.classList.remove('hidden');
}

function hide(el) {
    el.classList.add('hidden');
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// UTF-8-безопасные base64 хелперы (обычные btoa/atob ломаются на кириллице).
function utf8ToBase64(str) {
    return btoa(unescape(encodeURIComponent(str)));
}

function base64ToUtf8(str) {
    return decodeURIComponent(escape(atob(str)));
}

/* ------------------------------------------------------------------ *
 * PWA: регистрация service worker + подсказка "Установить приложение"
 * ------------------------------------------------------------------ */

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        const upgradingExistingWorker = Boolean(navigator.serviceWorker.controller);
        let refreshingForUpdate = false;

        if (upgradingExistingWorker) {
            navigator.serviceWorker.addEventListener('controllerchange', () => {
                if (refreshingForUpdate) return;
                refreshingForUpdate = true;
                window.location.reload();
            });
        }

        navigator.serviceWorker.register('service-worker.js?v=14').catch(() => {
            // Если что-то пошло не так (например, http без TLS) — сайт всё равно
            // должен нормально работать, просто без офлайн-кэша.
        });
    });
}

let deferredInstallPrompt = null;
const installButton = document.getElementById('install-button');

window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    deferredInstallPrompt = event;
    show(installButton);
});

installButton?.addEventListener('click', async () => {
    if (!deferredInstallPrompt) {
        return;
    }
    deferredInstallPrompt.prompt();
    await deferredInstallPrompt.userChoice;
    deferredInstallPrompt = null;
    hide(installButton);
});

window.addEventListener('appinstalled', () => {
    hide(installButton);
});

/* ------------------------------------------------------------------ *
 * AI-планировщик поездки по дням (K-Means, App\ML\KMeansDaySplitter)
 *
 * Кластеризует уже посчитанный маршрут на сбалансированные дни вождения —
 * без учителя, без размеченных примеров, только структура самих данных
 * (кумулятивная дистанция вдоль маршрута). См. api/day_plan.php.
 * ------------------------------------------------------------------ */

const dayPlanButton = document.getElementById('day-plan-button');
const dayPlanControls = document.getElementById('day-plan-controls');
const dayPlanDaysInput = document.getElementById('day-plan-days');
const dayPlanApplyButton = document.getElementById('day-plan-apply');
const dayPlanIntro = document.getElementById('day-plan-intro');
const dayPlanList = document.getElementById('day-plan-list');

let dayPlanVisible = false;

function resetDayPlanState() {
    dayPlanVisible = false;
    hide(dayPlanControls);
    hide(dayPlanIntro);
    dayPlanList.innerHTML = '';
    dayPlanButton.disabled = false;
    dayPlanButton.textContent = t('dayPlanButton');
}

dayPlanButton.addEventListener('click', async () => {
    if (!lastRouteData) {
        return;
    }

    if (dayPlanVisible) {
        dayPlanVisible = false;
        hide(dayPlanControls);
        hide(dayPlanIntro);
        dayPlanList.innerHTML = '';
        dayPlanButton.textContent = t('dayPlanButton');
        return;
    }

    await fetchDayPlan(null); // null -> пусть сервер сам предложит число дней
});

dayPlanApplyButton.addEventListener('click', () => {
    const days = parseInt(dayPlanDaysInput.value, 10);
    fetchDayPlan(Number.isFinite(days) && days > 0 ? days : null);
});

async function fetchDayPlan(days) {
    if (!lastRouteData) {
        return;
    }

    dayPlanButton.disabled = true;
    dayPlanButton.textContent = t('dayPlanButtonLoading');

    try {
        const points = lastRouteData.coords.map((c, i) => ({
            lat: c.lat, lon: c.lon, label: lastRouteData.points[i],
        }));

        const body = new URLSearchParams();
        body.set('points', JSON.stringify(points));
        if (days !== null) {
            body.set('days', String(days));
        }

        const response = await fetch('api/day_plan.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        });
        const data = await response.json();

        if (!data.ok) {
            throw new Error(data.error || 'day plan error');
        }

        renderDayPlan(data);
        dayPlanVisible = true;
        dayPlanButton.textContent = t('dayPlanButtonHide');
        show(dayPlanControls);
        show(dayPlanIntro);
        dayPlanDaysInput.value = String(data.days_requested);
    } catch (e) {
        dayPlanList.innerHTML = '<li class="day-plan-error">' + t('dayPlanError') + '</li>';
        dayPlanButton.textContent = t('dayPlanButton');
    } finally {
        dayPlanButton.disabled = false;
    }
}

function renderDayPlan(data) {
    dayPlanList.innerHTML = '';

    data.days.forEach((day) => {
        const li = document.createElement('li');
        li.className = 'day-plan-item';

        const header = document.createElement('div');
        header.className = 'day-plan-item-header';
        header.textContent = t('dayPlanDayLabel')(day.day) + ' — ' + formatNumber(day.distance_km) + ' км';

        const route = document.createElement('div');
        route.className = 'day-plan-item-route';
        route.textContent = day.waypoints.map(escapeHtml).join(' → ');

        li.appendChild(header);
        li.appendChild(route);
        dayPlanList.appendChild(li);
    });
}
