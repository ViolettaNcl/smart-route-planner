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
 * - ссылка-шеринг маршрута кодирует введённые города прямо в URL (base64) —
 *   без базы данных и без сервера. Получатель ссылки просто открывает её,
 *   JS декодирует параметр и сам запускает расчёт;
 * - автоподсказки городов при вводе (api/suggest.php -> Nominatim);
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
const suggestionsList = document.getElementById('suggestions');
const costFuelPrice = document.getElementById('cost-fuel-price');
const costFuelConsumption = document.getElementById('cost-fuel-consumption');
const costTicketPrice = document.getElementById('cost-ticket-price');
const mapModeControl = document.getElementById('map-mode-control');

let routeMap = null;
let routeMarkers = [];
let poiMarkers = [];
let poiVisible = false;
let poiFetchedForRoute = null; // хранит JSON.stringify(coords) маршрута, для которого уже запрашивали POI
let lastRouteData = null;
let lastMapRender = null;

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

function supportsWebGlMap() {
    if (typeof maplibregl === 'undefined' || typeof maplibregl.Map !== 'function') {
        return false;
    }

    // The `supported()` helper is not present in every MapLibre browser bundle.
    // Guard it instead of turning a successful route response into a UI error.
    if (typeof maplibregl.supported === 'function') {
        try {
            return maplibregl.supported();
        } catch (e) {
            // Fall through to a direct WebGL2 capability check.
        }
    }

    try {
        const canvas = document.createElement('canvas');
        const context = canvas.getContext('webgl2', {
            failIfMajorPerformanceCaveat: false,
            antialias: true,
        });
        return Boolean(context);
    } catch (e) {
        return false;
    }
}

// Вызывается из ui.js при переключении светлой/тёмной темы, чтобы перекрасить
// карту вслед за интерфейсом. После смены стиля восстанавливаются 3D-здания
// и линия уже рассчитанного маршрута.
window.setMapTileTheme = function (theme) {
    if (!routeMap) return;
    const normalized = theme === 'light' ? 'light' : 'dark';
    routeMap.setStyle(MAP_STYLES[normalized]);
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

shareButton.addEventListener('click', () => {
    const points = form.elements['points'].value;
    const encoded = utf8ToBase64(points);
    const url = location.origin + location.pathname + '?r=' + encoded;

    navigator.clipboard.writeText(url).then(() => {
        showShareToast(t('shareCopied'));
    }).catch(() => {
        showShareToast(t('shareCopyFailed')(url));
    });
});

// Если в URL есть ?r=... — это открытая по ссылке шеринга страница:
// декодируем города и сразу считаем маршрут, без ожидания клика пользователя.
window.addEventListener('DOMContentLoaded', () => {
    const params = new URLSearchParams(location.search);
    const shared = params.get('r');

    if (shared) {
        try {
            const points = base64ToUtf8(shared);
            form.elements['points'].value = points;
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
        body.set('fuel_price_per_liter', costFuelPrice.value);
        body.set('fuel_consumption_l_100km', costFuelConsumption.value);
        body.set('ticket_price_per_km', costTicketPrice.value);
        body.set('model_variant', getAbVariant());

        const response = await fetch('api/route.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        });

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
}

function showError(message) {
    errorBanner.textContent = '⚠️ ' + message;
    show(errorBanner);
}

function showShareToast(message) {
    shareToast.textContent = message;
    show(shareToast);
    setTimeout(() => hide(shareToast), 3500);
}

function renderResult(data) {
    show(resultSection);

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
    routingNote.textContent = data.routing_source === 'osrm_road' ? t('routingOsrm') : t('routingStraight');

    const confidenceFill = document.getElementById('confidence-fill');
    confidenceFill.style.width = data.transport.confidence + '%';
    document.getElementById('confidence-label').textContent = t('confidenceLabel')(data.transport.confidence);

    const list = document.getElementById('points-list');
    list.innerHTML = '';
    data.points.forEach((p, i) => {
        const li = document.createElement('li');
        li.dataset.pointIndex = String(i);
        li.innerHTML = '<span class="point-index">' + (i + 1) + '</span>'
            + '<span class="point-label">' + escapeHtml(p) + '</span>'
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
    lastRouteData = data;
    resetPoiState();
    resetExplainState();
    resetAbFeedbackState(data.transport.model);
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

function resetExplainState() {
    explainLoadedForRoute = null;
    hide(explainPanel);
    explainToggle.textContent = t('explainToggle');
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

function setLayerPaint(layerId, property, value) {
    try {
        routeMap.setPaintProperty(layerId, property, value);
    } catch (e) {
        // OpenFreeMap styles evolve independently. Unsupported paint properties
        // are optional polish and must never stop route rendering.
    }
}

function applyNaturePalette() {
    if (!routeMap || !routeMap.getStyle()) return;

    const dark = currentMapTheme() === 'dark';
    const palette = dark ? {
        background: '#071813',
        water: '#0c3b43',
        waterway: '#39b9b1',
        forest: '#113b29',
        grass: '#1a4930',
        building: '#24463a',
        buildingOutline: '#3c6858',
        label: '#dff7e9',
        labelHalo: '#071813',
    } : {
        background: '#eef7ea',
        water: '#bce7e1',
        waterway: '#3b9f9b',
        forest: '#c9e6bd',
        grass: '#dcefd0',
        building: '#dce8d7',
        buildingOutline: '#afc8aa',
        label: '#244536',
        labelHalo: '#f6fbf3',
    };

    (routeMap.getStyle().layers || []).forEach((layer) => {
        const descriptor = (layer.id + ' ' + (layer['source-layer'] || '')).toLowerCase();

        if (layer.type === 'background') {
            setLayerPaint(layer.id, 'background-color', palette.background);
            return;
        }

        if (layer.type === 'fill') {
            if (/water|ocean|lake|river/.test(descriptor)) {
                setLayerPaint(layer.id, 'fill-color', palette.water);
            } else if (/forest|wood|tree|scrub/.test(descriptor)) {
                setLayerPaint(layer.id, 'fill-color', palette.forest);
                setLayerPaint(layer.id, 'fill-opacity', dark ? 0.82 : 0.88);
            } else if (/park|grass|meadow|garden|green|nature/.test(descriptor)) {
                setLayerPaint(layer.id, 'fill-color', palette.grass);
                setLayerPaint(layer.id, 'fill-opacity', dark ? 0.76 : 0.84);
            } else if (/building/.test(descriptor)) {
                setLayerPaint(layer.id, 'fill-color', palette.building);
                setLayerPaint(layer.id, 'fill-outline-color', palette.buildingOutline);
            }
            return;
        }

        if (layer.type === 'line' && /water|river|stream|canal/.test(descriptor)) {
            setLayerPaint(layer.id, 'line-color', palette.waterway);
        }

        if (layer.type === 'symbol' && layer.layout && layer.layout['text-field']) {
            setLayerPaint(layer.id, 'text-color', palette.label);
            setLayerPaint(layer.id, 'text-halo-color', palette.labelHalo);
            setLayerPaint(layer.id, 'text-halo-width', dark ? 1.35 : 1.1);
        }
    });
}

function add3dBuildingsLayer() {
    if (!routeMap || routeMap.getLayer(BUILDINGS_LAYER_ID)) return;

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
        minzoom: 13.5,
        filter: ['!=', ['get', 'hide_3d'], true],
        layout: {
            visibility: currentMapMode === '3d' ? 'visible' : 'none',
        },
        paint: {
            'fill-extrusion-color': [
                'interpolate', ['linear'], ['coalesce', ['get', 'render_height'], 0],
                0, dark ? '#173b2e' : '#dcebd6',
                80, dark ? '#37775f' : '#9fc593',
                240, dark ? '#b6ee72' : '#4d9361',
            ],
            'fill-extrusion-height': [
                'interpolate', ['linear'], ['zoom'],
                13.5, 0,
                15, ['coalesce', ['get', 'render_height'], 8],
            ],
            'fill-extrusion-base': ['coalesce', ['get', 'render_min_height'], 0],
            'fill-extrusion-opacity': dark ? 0.86 : 0.8,
            'fill-extrusion-vertical-gradient': true,
        },
    }, findFirstLabelLayerId());
}

function routeGeoJson(routeGeometry) {
    return {
        type: 'Feature',
        properties: {},
        geometry: {
            type: 'LineString',
            coordinates: routeGeometry.map((point) => Array.isArray(point)
                ? [Number(point[1]), Number(point[0])]
                : [Number(point.lon), Number(point.lat)]),
        },
    };
}

function routePoint(point) {
    if (Array.isArray(point)) {
        return { lat: Number(point[0]), lon: Number(point[1]) };
    }
    return { lat: Number(point.lat), lon: Number(point.lon) };
}

function renderStaticRouteMap(coords, labels, routeGeometry) {
    const panel = mapContainer.closest('.map-panel');
    const sourcePoints = (Array.isArray(routeGeometry) && routeGeometry.length > 1
        ? routeGeometry
        : coords).map(routePoint).filter((point) => Number.isFinite(point.lat) && Number.isFinite(point.lon));

    if (sourcePoints.length < 2) {
        mapContainer.innerHTML = '<div class="map-webgl-error">' + escapeHtml(t('mapWebglError')) + '</div>';
        show(mapContainer);
        hide(mapPlaceholder);
        hide(mapModeControl);
        return null;
    }

    const maxSvgPoints = 420;
    const sampleEvery = Math.max(1, Math.ceil(sourcePoints.length / maxSvgPoints));
    const sampled = sourcePoints.filter((point, index) => index % sampleEvery === 0);
    if (sampled[sampled.length - 1] !== sourcePoints[sourcePoints.length - 1]) {
        sampled.push(sourcePoints[sourcePoints.length - 1]);
    }

    const allLons = sourcePoints.map((point) => point.lon);
    const allLats = sourcePoints.map((point) => point.lat);
    const minLon = Math.min(...allLons);
    const maxLon = Math.max(...allLons);
    const minLat = Math.min(...allLats);
    const maxLat = Math.max(...allLats);
    const lonSpan = Math.max(maxLon - minLon, 0.02);
    const latSpan = Math.max(maxLat - minLat, 0.02);
    const width = 1000;
    const height = 560;
    const padding = 76;

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

    const endpointLabels = [0, coords.length - 1].filter((index, position, array) => array.indexOf(index) === position)
        .map((index) => {
            const point = project(routePoint(coords[index]));
            const label = escapeHtml(labels[index] || '');
            const anchor = index === 0 ? 'start' : 'end';
            const offset = index === 0 ? 30 : -30;
            return '<text class="static-route-label" x="' + (point.x + offset).toFixed(1) + '" y="'
                + (point.y - 27).toFixed(1) + '" text-anchor="' + anchor + '">' + label + '</text>';
        }).join('');

    mapContainer.innerHTML = '<div class="static-route-map">'
        + '<svg viewBox="0 0 1000 560" role="img" aria-label="' + escapeHtml(t('mapStaticMode')) + '">'
        + '<defs><linearGradient id="static-map-bg" x1="0" y1="0" x2="1" y2="1">'
        + '<stop offset="0" stop-color="#0a261d"></stop><stop offset="0.55" stop-color="#123b2e"></stop>'
        + '<stop offset="1" stop-color="#0d3940"></stop></linearGradient>'
        + '<linearGradient id="static-route-line" x1="0" y1="0" x2="1" y2="0">'
        + '<stop offset="0" stop-color="#99ef72"></stop><stop offset="0.52" stop-color="#42d6b1"></stop>'
        + '<stop offset="1" stop-color="#ffc85b"></stop></linearGradient>'
        + '<filter id="static-route-glow"><feGaussianBlur stdDeviation="9" result="blur"></feGaussianBlur>'
        + '<feMerge><feMergeNode in="blur"></feMergeNode><feMergeNode in="SourceGraphic"></feMergeNode></feMerge></filter>'
        + '<pattern id="static-contours" width="120" height="90" patternUnits="userSpaceOnUse">'
        + '<path d="M-20 52 C18 12 82 13 140 46 M-28 74 C26 36 80 40 148 67" fill="none" stroke="rgba(189,247,187,.13)" stroke-width="2"></path>'
        + '</pattern></defs><rect width="1000" height="560" fill="url(#static-map-bg)"></rect>'
        + '<rect width="1000" height="560" fill="url(#static-contours)"></rect>'
        + '<path class="static-route-glow" d="' + path + '"></path>'
        + '<path class="static-route-path" d="' + path + '"></path>' + markerSvg + endpointLabels + '</svg>'
        + '<div class="static-route-note"><span aria-hidden="true">🌿</span><span>'
        + escapeHtml(t('mapStaticMode')) + '</span></div></div>';

    hide(mapPlaceholder);
    show(mapContainer);
    hide(mapModeControl);
    if (panel) {
        panel.classList.add('map-ready', 'map-static');
    }
    return null;
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
            'line-color': currentMapTheme() === 'dark' ? '#061d16' : '#f8fff4',
            'line-width': ['interpolate', ['linear'], ['zoom'], 3, 8, 10, 13, 16, 19],
            'line-opacity': 0.92,
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
            'line-color': '#69e69b',
            'line-width': ['interpolate', ['linear'], ['zoom'], 3, 12, 10, 20, 16, 30],
            'line-opacity': 0.28,
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
                0, '#9bef6d',
                0.38, '#45d7ad',
                0.68, '#4fc9dc',
                0.86, '#ffd15c',
                1, '#ff8c70',
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
            'line-color': 'rgba(255,255,255,0.9)',
            'line-width': ['interpolate', ['linear'], ['zoom'], 3, 0.7, 10, 1.15, 16, 1.8],
            'line-opacity': 0.7,
            'line-dasharray': [0.7, 2.2],
        },
    }, beforeId);
}

function restoreMapLayers() {
    if (!routeMap || !routeMap.isStyleLoaded()) return;
    applyNaturePalette();
    add3dBuildingsLayer();
    addRouteLayers();
    applyMapMode(false);
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

    if (routeMap.getLayer(BUILDINGS_LAYER_ID)) {
        routeMap.setLayoutProperty(
            BUILDINGS_LAYER_ID,
            'visibility',
            currentMapMode === '3d' ? 'visible' : 'none'
        );
    }

    routeMap.easeTo({
        pitch: currentMapMode === '3d' ? 58 : 0,
        bearing: currentMapMode === '3d' ? -24 : 0,
        duration: animate ? 900 : 0,
        essential: true,
    });
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
        return null;
    }

    routeMap = new maplibregl.Map({
        container: 'map',
        style: MAP_STYLES[currentMapTheme()],
        center: [14, 48],
        zoom: 4,
        pitch: currentMapMode === '3d' ? 58 : 0,
        bearing: currentMapMode === '3d' ? -24 : 0,
        maxPitch: 72,
        attributionControl: false,
        canvasContextAttributes: { antialias: true },
    });

    routeMap.addControl(new maplibregl.NavigationControl({ visualizePitch: true }), 'top-left');
    routeMap.addControl(new maplibregl.AttributionControl({
        compact: true,
        customAttribution: 'OpenFreeMap · OpenMapTiles · OpenStreetMap',
    }), 'bottom-right');

    routeMap.on('style.load', restoreMapLayers);
    routeMap.on('error', (event) => {
        if (event && event.error) {
            console.warn('MapLibre:', event.error.message || event.error);
        }
    });

    return routeMap;
}

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

function renderMap(coords, labels, routeGeometry) {
    hide(mapPlaceholder);
    show(mapContainer);
    show(mapModeControl);
    lastMapRender = { coords, labels, routeGeometry };

    const panel = mapContainer.closest('.map-panel');
    if (panel) {
        panel.classList.add('map-ready');
        panel.classList.remove('map-static');
    }

    if (!routeMap && !createRouteMap()) {
        renderStaticRouteMap(coords, labels, routeGeometry);
        return;
    }

    routeMap.resize();
    renderRouteMarkers(coords, labels);

    if (routeMap.isStyleLoaded()) {
        if (routeMap.getLayer(ROUTE_HIGHLIGHT_LAYER_ID)) routeMap.removeLayer(ROUTE_HIGHLIGHT_LAYER_ID);
        if (routeMap.getLayer(ROUTE_LINE_LAYER_ID)) routeMap.removeLayer(ROUTE_LINE_LAYER_ID);
        if (routeMap.getLayer(ROUTE_GLOW_LAYER_ID)) routeMap.removeLayer(ROUTE_GLOW_LAYER_ID);
        if (routeMap.getLayer(ROUTE_CASING_LAYER_ID)) routeMap.removeLayer(ROUTE_CASING_LAYER_ID);
        if (routeMap.getSource(ROUTE_SOURCE_ID)) routeMap.removeSource(ROUTE_SOURCE_ID);
        addRouteLayers();
    }

    const bounds = new maplibregl.LngLatBounds();
    coords.forEach((coord) => bounds.extend([coord.lon, coord.lat]));
    routeMap.fitBounds(bounds, {
        padding: { top: 72, right: 64, bottom: 72, left: 64 },
        maxZoom: 15.5,
        pitch: currentMapMode === '3d' ? 58 : 0,
        bearing: currentMapMode === '3d' ? -24 : 0,
        duration: 1250,
        essential: true,
    });
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
 * Автоподсказки городов при вводе (Nominatim через api/suggest.php)
 *
 * Работаем только с "текущим" (последним) сегментом textarea — той частью
 * текста после последней «;», которую пользователь ещё печатает. Так проще
 * всего сочетать автоподсказку с полем, где вводится сразу несколько точек.
 * ------------------------------------------------------------------ */

let suggestDebounceTimer = null;
let suggestAbortController = null;
let activeSuggestionIndex = -1;

pointsInput.addEventListener('input', () => {
    const segment = currentSegment();

    clearTimeout(suggestDebounceTimer);

    if (segment.trim().length < 2) {
        hideSuggestions();
        return;
    }

    suggestDebounceTimer = setTimeout(() => fetchSuggestions(segment.trim()), 350);
});

pointsInput.addEventListener('blur', () => {
    // Небольшая задержка, чтобы клик по подсказке успел сработать раньше blur.
    setTimeout(hideSuggestions, 150);
});

pointsInput.addEventListener('keydown', (event) => {
    const items = suggestionsList.querySelectorAll('li');
    if (suggestionsList.classList.contains('hidden') || items.length === 0) {
        return;
    }

    if (event.key === 'ArrowDown') {
        event.preventDefault();
        activeSuggestionIndex = Math.min(activeSuggestionIndex + 1, items.length - 1);
        highlightSuggestion(items);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        activeSuggestionIndex = Math.max(activeSuggestionIndex - 1, 0);
        highlightSuggestion(items);
    } else if (event.key === 'Enter' && activeSuggestionIndex >= 0) {
        event.preventDefault();
        items[activeSuggestionIndex].click();
    } else if (event.key === 'Escape') {
        hideSuggestions();
    }
});

function currentSegment() {
    const value = pointsInput.value;
    const cursor = pointsInput.selectionStart ?? value.length;
    const uptoCursor = value.slice(0, cursor);
    const lastSemicolon = uptoCursor.lastIndexOf(';');
    return uptoCursor.slice(lastSemicolon + 1);
}

async function fetchSuggestions(query) {
    if (suggestAbortController) {
        suggestAbortController.abort();
    }
    suggestAbortController = new AbortController();

    try {
        const response = await fetch('api/suggest.php?q=' + encodeURIComponent(query), {
            signal: suggestAbortController.signal,
        });
        const data = await response.json();

        if (data.ok && Array.isArray(data.suggestions)) {
            renderSuggestions(data.suggestions);
        }
    } catch (e) {
        // Тихо игнорируем — автоподсказки необязательны, форма продолжает работать.
    }
}

function renderSuggestions(suggestions) {
    suggestionsList.innerHTML = '';
    activeSuggestionIndex = -1;

    if (suggestions.length === 0) {
        hideSuggestions();
        return;
    }

    suggestions.forEach((s) => {
        const li = document.createElement('li');
        li.textContent = s.display_name;
        li.addEventListener('mousedown', (e) => e.preventDefault()); // не терять фокус до click
        li.addEventListener('click', () => applySuggestion(s.display_name));
        suggestionsList.appendChild(li);
    });

    show(suggestionsList);
}

function highlightSuggestion(items) {
    items.forEach((li, i) => li.classList.toggle('active', i === activeSuggestionIndex));
    if (items[activeSuggestionIndex]) {
        items[activeSuggestionIndex].scrollIntoView({ block: 'nearest' });
    }
}

function applySuggestion(displayName) {
    const value = pointsInput.value;
    const cursor = pointsInput.selectionStart ?? value.length;
    const uptoCursor = value.slice(0, cursor);
    const afterCursor = value.slice(cursor);
    const lastSemicolon = uptoCursor.lastIndexOf(';');
    const before = uptoCursor.slice(0, lastSemicolon + 1);
    const prefix = before && !before.endsWith(' ') ? before + ' ' : before;

    pointsInput.value = prefix + displayName + '; ' + afterCursor.replace(/^\s*/, '');
    const newCursor = (prefix + displayName + '; ').length;
    pointsInput.focus();
    pointsInput.setSelectionRange(newCursor, newCursor);

    hideSuggestions();
}

function hideSuggestions() {
    hide(suggestionsList);
    suggestionsList.innerHTML = '';
    activeSuggestionIndex = -1;
}

/* ------------------------------------------------------------------ *
 * PWA: регистрация service worker + подсказка "Установить приложение"
 * ------------------------------------------------------------------ */

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('service-worker.js').catch(() => {
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
 * Объяснение предсказания ("почему такой транспорт?") — App\ML\*::explain()
 * ------------------------------------------------------------------ */

const explainToggle = document.getElementById('explain-toggle');
const explainPanel = document.getElementById('explain-panel');
let explainLoadedForRoute = null;

explainToggle.addEventListener('click', async () => {
    const isHidden = explainPanel.classList.contains('hidden');

    if (!isHidden) {
        hide(explainPanel);
        explainToggle.textContent = t('explainToggle');
        return;
    }

    show(explainPanel);
    explainToggle.textContent = t('explainToggleHide');

    if (!lastRouteData) {
        return;
    }

    const routeKey = lastRouteData.distance_km + ':' + lastRouteData.stops;
    if (explainLoadedForRoute === routeKey) {
        return; // уже загружали для этого же маршрута
    }

    document.getElementById('explain-intro').textContent = t('explainLoading');
    document.getElementById('explain-bars').innerHTML = '';

    try {
        const url = 'api/explain.php?distance_km=' + lastRouteData.distance_km + '&stops=' + lastRouteData.stops;
        const response = await fetch(url);
        const data = await response.json();

        if (!data.ok) {
            throw new Error(data.error || 'explain error');
        }

        renderExplainPanel(data);
        explainLoadedForRoute = routeKey;
    } catch (e) {
        document.getElementById('explain-intro').textContent = t('explainError');
    }
});

function renderExplainPanel(data) {
    const { explanation, model } = data;
    const intro = document.getElementById('explain-intro');
    const bars = document.getElementById('explain-bars');

    intro.textContent = model === 'mlp' ? t('explainMlpIntro') : t('explainSoftmaxIntro');
    bars.innerHTML = '';

    const contributions = explanation.contributions;
    const maxAbs = Math.max(...Object.values(contributions).map(Math.abs), 0.001);
    const featureNames = t('explainFeatureNames');

    Object.entries(contributions).forEach(([key, value]) => {
        const row = document.createElement('div');
        row.className = 'explain-row';

        const label = document.createElement('span');
        label.className = 'explain-row-label';
        label.textContent = featureNames[key] || key.replace('neuron_', 'нейрон ');

        const barTrack = document.createElement('span');
        barTrack.className = 'explain-bar-track';

        const bar = document.createElement('span');
        bar.className = 'explain-bar-fill ' + (value >= 0 ? 'positive' : 'negative');
        bar.style.width = (Math.abs(value) / maxAbs * 100) + '%';
        barTrack.appendChild(bar);

        const valueEl = document.createElement('span');
        valueEl.className = 'explain-row-value';
        valueEl.textContent = value.toFixed(3);

        row.appendChild(label);
        row.appendChild(barTrack);
        row.appendChild(valueEl);
        bars.appendChild(row);
    });
}

/* ------------------------------------------------------------------ *
 * "Живое" дообучение — пользователь поправляет предсказание модели.
 * ------------------------------------------------------------------ */

const learnToast = document.getElementById('learn-toast');

document.querySelectorAll('.learn-btn').forEach((btn) => {
    btn.addEventListener('click', async () => {
        if (!lastRouteData) {
            return;
        }

        const correctLabel = btn.dataset.label;
        document.querySelectorAll('.learn-btn').forEach((b) => (b.disabled = true));

        try {
            const body = new URLSearchParams();
            body.set('distance_km', lastRouteData.distance_km);
            body.set('stops', lastRouteData.stops);
            body.set('correct_label', correctLabel);

            const response = await fetch('api/learn.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
            });
            const data = await response.json();

            if (!data.ok) {
                throw new Error(data.error || 'learn error');
            }

            if (!data.applied) {
                showLearnToast(t('learnNotSupported'));
                return;
            }

            showLearnToast(t('learnApplied')(data.before, data.after));

            // Обновляем отображаемое предсказание, чтобы пользователь сразу
            // увидел эффект своей правки на этом же маршруте.
            document.getElementById('stat-transport').textContent = modeLabelFor(data.after.mode);
            document.getElementById('confidence-fill').style.width = data.after.confidence + '%';
            document.getElementById('confidence-label').textContent = t('confidenceLabel')(data.after.confidence);
            lastRouteData.transport = data.after;

            // Объяснение уже неактуально — веса поменялись.
            explainLoadedForRoute = null;
            if (!explainPanel.classList.contains('hidden')) {
                explainToggle.click();
                explainToggle.click();
            }
        } catch (e) {
            showLearnToast(t('learnError'));
        } finally {
            document.querySelectorAll('.learn-btn').forEach((b) => (b.disabled = false));
        }
    });
});

function showLearnToast(message) {
    learnToast.textContent = message;
    show(learnToast);
    setTimeout(() => hide(learnToast), 4500);
}

/* ------------------------------------------------------------------ *
 * A/B-тест MLP vs Softmax: отзыв "угадала ли модель" для варианта,
 * назначенного этому визиту (см. getAbVariant()).
 * ------------------------------------------------------------------ */

const abFeedbackPrompt = document.getElementById('ab-feedback-prompt');
const abFeedbackYes = document.getElementById('ab-feedback-yes');
const abFeedbackNo = document.getElementById('ab-feedback-no');
const abFeedbackToast = document.getElementById('ab-feedback-toast');
let abFeedbackGivenForRoute = null;

function resetAbFeedbackState(variant) {
    abFeedbackGivenForRoute = null;
    abFeedbackPrompt.textContent = t('abFeedbackPrompt')(modelVariantLabel(variant));
    abFeedbackYes.disabled = false;
    abFeedbackNo.disabled = false;
    hide(abFeedbackToast);
}

function modelVariantLabel(variant) {
    return variant === 'softmax' ? t('boundaryModelSoftmax') : t('boundaryModelMlp');
}

async function sendAbFeedback(isCorrect) {
    if (!lastRouteData || abFeedbackGivenForRoute !== null) {
        return;
    }

    const variant = lastRouteData.transport.model;
    abFeedbackYes.disabled = true;
    abFeedbackNo.disabled = true;

    try {
        const body = new URLSearchParams();
        body.set('variant', variant);
        body.set('is_correct', isCorrect ? '1' : '0');

        const response = await fetch('api/feedback.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        });
        const data = await response.json();

        if (!data.ok) {
            throw new Error(data.error || 'feedback error');
        }

        abFeedbackGivenForRoute = isCorrect;
        abFeedbackToast.textContent = t('abFeedbackThanks');
        show(abFeedbackToast);
        renderAbStats(data.stats);
    } catch (e) {
        abFeedbackToast.textContent = t('abFeedbackError');
        show(abFeedbackToast);
        abFeedbackYes.disabled = false;
        abFeedbackNo.disabled = false;
    }
}

abFeedbackYes.addEventListener('click', () => sendAbFeedback(true));
abFeedbackNo.addEventListener('click', () => sendAbFeedback(false));

async function loadAbStats() {
    const container = document.getElementById('ab-stats-content');
    if (!container) {
        return;
    }

    container.textContent = t('abStatsLoading');

    try {
        const response = await fetch('api/ab_stats.php');
        const data = await response.json();

        if (!data.ok) {
            throw new Error(data.error || 'ab stats error');
        }

        renderAbStats(data.stats);
    } catch (e) {
        container.textContent = t('abStatsError');
    }
}

function renderAbStats(stats) {
    const container = document.getElementById('ab-stats-content');
    if (!container) {
        return;
    }

    const totalResponses = (stats.mlp?.total || 0) + (stats.softmax?.total || 0);

    if (totalResponses === 0) {
        container.textContent = t('abStatsEmpty')(0);
        return;
    }

    container.innerHTML = '';
    ['mlp', 'softmax'].forEach((variant) => {
        const s = stats[variant];
        const row = document.createElement('div');
        row.className = 'ab-stats-row';
        row.textContent = s.total > 0
            ? t('abStatsRow')(modelVariantLabel(variant), s.correct, s.total, s.accuracy)
            : modelVariantLabel(variant) + ': ' + t('abStatsEmpty')(0);
        container.appendChild(row);
    });
}

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
