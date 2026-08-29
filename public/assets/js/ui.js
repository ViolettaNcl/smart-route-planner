/**
 * UI-надстройка поверх основного app.js: не трогает бизнес-логику расчёта
 * маршрута, а только переключает визуальные вещи, добавленные в новой
 * desktop-раскладке — тему (светлая/тёмная) и вкладки в панели результата.
 *
 * Тема применяется к <html data-theme="dark|light">, сохраняется в
 * localStorage и сообщается карте (app.js меняет векторный стиль MapLibre
 * через window.setMapTileTheme, см. app.js).
 */

const THEME_STORAGE_KEY = 'srp_theme';

function getTheme() {
    return document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
}

function applyThemeIcon(theme) {
    const icon = document.querySelector('.theme-toggle-icon');
    const button = document.getElementById('theme-toggle');
    if (icon) {
        // Показываем иконку того состояния, В КОТОРОЕ переключит клик (солнце — на
        // тёмной теме, луна — на светлой), а не текущее — так привычнее для этого паттерна.
        icon.textContent = theme === 'light' ? '🌙' : '☀️';
    }
    if (button) {
        const label = theme === 'light'
            ? (typeof t === 'function' ? t('themeToggleToDark') : 'Dark theme')
            : (typeof t === 'function' ? t('themeToggleToLight') : 'Light theme');
        button.setAttribute('aria-label', label);
        button.title = label;
    }
}

function setTheme(theme) {
    const normalized = theme === 'light' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', normalized);
    try {
        localStorage.setItem(THEME_STORAGE_KEY, normalized);
    } catch (e) {
        // localStorage недоступен (приватный режим и т.п.) — тема просто не запомнится.
    }
    applyThemeIcon(normalized);

    const themeColorMeta = document.querySelector('meta[name="theme-color"]');
    if (themeColorMeta) {
        themeColorMeta.setAttribute('content', normalized === 'light' ? '#eef0f4' : '#14171c');
    }

    // Перекрашиваем подложку карты вслед за темой интерфейса, если карта уже создана.
    if (typeof window.setMapTileTheme === 'function') {
        window.setMapTileTheme(normalized);
    }

    // Аналогично перекрашиваем уже открытую карту решений модели (ось/легенда/точки).
    if (typeof window.refreshBoundaryChartTheme === 'function') {
        window.refreshBoundaryChartTheme();
    }
}

function initTheme() {
    applyThemeIcon(getTheme());

    const button = document.getElementById('theme-toggle');
    if (button) {
        button.addEventListener('click', () => {
            setTheme(getTheme() === 'light' ? 'dark' : 'light');
        });
    }
}

// --- вкладки панели результата ---
function initResultTabs() {
    const navButtons = document.querySelectorAll('.tab-nav-btn');
    const panels = document.querySelectorAll('.tab-panel');

    navButtons.forEach((btn) => {
        btn.addEventListener('click', () => {
            const target = btn.dataset.tab;

            navButtons.forEach((b) => b.classList.toggle('active', b === btn));
            panels.forEach((panel) => {
                panel.classList.toggle('hidden', panel.dataset.tabPanel !== target);
            });

            // Карта могла подрасти/измениться в высоте при первом рендере
            // (например, если пользователь только что переключился с
            // мобильной раскладки) — на всякий случай пересчитываем размер.
            if (typeof routeMap !== 'undefined' && routeMap && typeof routeMap.resize === 'function') {
                routeMap.resize();
            }
        });
    });
}

/* ------------------------------------------------------------------ *
 * Более выразительный режим 3D для маршрутной карты.
 *
 * Базовая карта остаётся OpenFreeMap/MapLibre и по-прежнему не требует
 * API-ключа. Здесь поверх неё добавляются бесплатный глобальный рельеф
 * Mapterhorn, мягкая hillshade-подсветка и globe-проекция. В 2D всё это
 * отключается, чтобы пользователь получал обычную плоскую дорожную карту.
 *
 * Код живёт в ui.js намеренно как визуальная надстройка: расчёт маршрута,
 * OSRM-геометрия и маркеры остаются в app.js без изменений.
 * ------------------------------------------------------------------ */

const ROUTE_TERRAIN_SOURCE_ID = 'route-terrain-dem';
const ROUTE_TERRAIN_HILLSHADE_LAYER_ID = 'route-terrain-hillshade';
const ROUTE_TERRAIN_TILEJSON = 'https://tiles.mapterhorn.com/tilejson.json';

function ensureRouteTerrainLayers() {
    if (typeof routeMap === 'undefined' || !routeMap || !routeMap.isStyleLoaded()) {
        return;
    }

    if (!routeMap.getSource(ROUTE_TERRAIN_SOURCE_ID)) {
        routeMap.addSource(ROUTE_TERRAIN_SOURCE_ID, {
            type: 'raster-dem',
            url: ROUTE_TERRAIN_TILEJSON,
            tileSize: 512,
            attribution: 'Terrain © Mapterhorn',
        });
    }

    if (!routeMap.getLayer(ROUTE_TERRAIN_HILLSHADE_LAYER_ID)) {
        const dark = getTheme() === 'dark';
        const beforeId = typeof BUILDINGS_LAYER_ID !== 'undefined' && routeMap.getLayer(BUILDINGS_LAYER_ID)
            ? BUILDINGS_LAYER_ID
            : (typeof ROUTE_GLOW_LAYER_ID !== 'undefined' && routeMap.getLayer(ROUTE_GLOW_LAYER_ID)
                ? ROUTE_GLOW_LAYER_ID
                : (typeof findFirstLabelLayerId === 'function' ? findFirstLabelLayerId() : undefined));

        routeMap.addLayer({
            id: ROUTE_TERRAIN_HILLSHADE_LAYER_ID,
            type: 'hillshade',
            source: ROUTE_TERRAIN_SOURCE_ID,
            layout: {
                visibility: typeof currentMapMode !== 'undefined' && currentMapMode === '3d'
                    ? 'visible'
                    : 'none',
            },
            paint: {
                'hillshade-illumination-direction': 315,
                'hillshade-exaggeration': dark ? 0.34 : 0.26,
                'hillshade-shadow-color': dark ? 'rgba(5, 12, 20, 0.72)' : 'rgba(63, 67, 82, 0.32)',
                'hillshade-highlight-color': dark ? 'rgba(103, 232, 219, 0.24)' : 'rgba(255, 255, 255, 0.58)',
                'hillshade-accent-color': dark ? 'rgba(167, 139, 250, 0.26)' : 'rgba(124, 58, 237, 0.15)',
            },
        }, beforeId);
    }
}

function syncRouteMapDepth() {
    if (typeof routeMap === 'undefined' || !routeMap || !routeMap.isStyleLoaded()) {
        return;
    }

    const use3d = typeof currentMapMode !== 'undefined' && currentMapMode === '3d';
    const hasTerrain = Boolean(routeMap.getSource(ROUTE_TERRAIN_SOURCE_ID));

    if (typeof routeMap.setProjection === 'function') {
        try {
            routeMap.setProjection({ type: use3d ? 'globe' : 'mercator' });
        } catch (e) {
            // Старые/ограниченные WebGL-реализации всё равно сохраняют pitch + 3D buildings.
        }
    }

    if (hasTerrain && typeof routeMap.setTerrain === 'function') {
        try {
            routeMap.setTerrain(use3d
                ? { source: ROUTE_TERRAIN_SOURCE_ID, exaggeration: 1.16 }
                : null);
        } catch (e) {
            // Fallback: если конкретная версия MapLibre не принимает null,
            // оставляем DEM активным, но делаем его визуально плоским.
            if (!use3d) {
                try {
                    routeMap.setTerrain({ source: ROUTE_TERRAIN_SOURCE_ID, exaggeration: 0 });
                } catch (ignored) {
                    // Рельеф необязателен: базовая карта остаётся полностью рабочей.
                }
            }
        }
    }

    if (routeMap.getLayer(ROUTE_TERRAIN_HILLSHADE_LAYER_ID)) {
        routeMap.setLayoutProperty(
            ROUTE_TERRAIN_HILLSHADE_LAYER_ID,
            'visibility',
            use3d ? 'visible' : 'none'
        );
    }
}

// app.js загружен раньше ui.js, поэтому мы аккуратно расширяем уже существующие
// hooks, не дублируя расчёт маршрута и не меняя публичный API приложения.
if (typeof restoreMapLayers === 'function' && typeof applyMapMode === 'function') {
    const restoreMapLayersBase = restoreMapLayers;
    const applyMapModeBase = applyMapMode;

    restoreMapLayers = function () {
        restoreMapLayersBase();
        ensureRouteTerrainLayers();
        syncRouteMapDepth();
    };

    applyMapMode = function (animate = true) {
        applyMapModeBase(animate);
        ensureRouteTerrainLayers();
        syncRouteMapDepth();
    };
}

/* ------------------------------------------------------------------ *
 * Production hardening for route rendering.
 *
 * The route API can return HTTP 200 and valid JSON while a client-side map
 * rendering exception happens afterwards. app.js historically wrapped both
 * phases in one try/catch, so any UI exception was incorrectly shown as
 * "Could not reach the server". Keep transport/parsing/rendering failures
 * separate and make the map fall back to a minimal MapLibre renderer.
 * ------------------------------------------------------------------ */

function routeUiMessage(ru, en) {
    return typeof getLang === 'function' && getLang() === 'en' ? en : ru;
}

function parseRouteJson(raw) {
    const normalized = String(raw || '').replace(/^\uFEFF/, '').trim();
    if (!normalized) {
        throw new Error('Route API returned an empty body');
    }

    try {
        return JSON.parse(normalized);
    } catch (firstError) {
        // If a PHP runtime prepended a warning/notice despite production
        // settings, recover the JSON object instead of treating it as a
        // network outage. The original body is still logged for diagnostics.
        const start = normalized.indexOf('{');
        const end = normalized.lastIndexOf('}');
        if (start >= 0 && end > start) {
            return JSON.parse(normalized.slice(start, end + 1));
        }
        throw firstError;
    }
}

function renderMapFallback(coords, labels, routeGeometry) {
    if (typeof maplibregl === 'undefined') {
        throw new Error('MapLibre is not loaded');
    }

    try {
        if (typeof routeMap !== 'undefined' && routeMap && typeof routeMap.remove === 'function') {
            routeMap.remove();
        }
    } catch (ignored) {
        // A partially-created WebGL map may fail during remove(); rebuilding
        // the container is enough for the fallback instance.
    }

    routeMap = null;
    mapContainer.innerHTML = '';
    hide(mapPlaceholder);
    show(mapContainer);
    show(mapModeControl);

    const first = coords[0] || { lon: 14, lat: 48 };
    routeMap = new maplibregl.Map({
        container: 'map',
        style: MAP_STYLES[currentMapTheme()],
        center: [Number(first.lon), Number(first.lat)],
        zoom: 5,
        pitch: currentMapMode === '3d' ? 48 : 0,
        bearing: currentMapMode === '3d' ? -15 : 0,
        attributionControl: false,
        canvasContextAttributes: { antialias: true },
    });

    routeMap.addControl(new maplibregl.NavigationControl({ visualizePitch: true }), 'top-left');
    routeMap.addControl(new maplibregl.AttributionControl({
        compact: true,
        customAttribution: 'OpenFreeMap · OpenStreetMap',
    }), 'bottom-right');

    routeMap.on('load', () => {
        try {
            routeMap.addSource('route-fallback-geometry', {
                type: 'geojson',
                data: routeGeoJson(routeGeometry),
            });
            routeMap.addLayer({
                id: 'route-fallback-glow',
                type: 'line',
                source: 'route-fallback-geometry',
                paint: {
                    'line-color': '#43e3d4',
                    'line-width': 10,
                    'line-opacity': 0.2,
                    'line-blur': 4,
                },
            });
            routeMap.addLayer({
                id: 'route-fallback-line',
                type: 'line',
                source: 'route-fallback-geometry',
                layout: { 'line-cap': 'round', 'line-join': 'round' },
                paint: {
                    'line-color': '#61c7f2',
                    'line-width': 5,
                    'line-opacity': 0.98,
                },
            });

            renderRouteMarkers(coords, labels);
            const bounds = new maplibregl.LngLatBounds();
            coords.forEach((coord) => bounds.extend([Number(coord.lon), Number(coord.lat)]));
            routeMap.fitBounds(bounds, {
                padding: { top: 72, right: 64, bottom: 72, left: 64 },
                maxZoom: 14,
                duration: 900,
            });
        } catch (error) {
            console.error('[Smart Route Planner] Minimal map fallback failed:', error);
        }
    });

    return routeMap;
}

if (typeof renderMap === 'function') {
    const renderMapBase = renderMap;
    renderMap = function (coords, labels, routeGeometry) {
        try {
            return renderMapBase(coords, labels, routeGeometry);
        } catch (error) {
            console.error('[Smart Route Planner] Primary map renderer failed, using fallback:', error);
            return renderMapFallback(coords, labels, routeGeometry);
        }
    };
}

if (typeof calculateRoute === 'function') {
    calculateRoute = async function (points) {
        hide(errorBanner);
        hide(warningBanner);
        setLoading(true);

        const body = new URLSearchParams();
        body.set('points', points);
        body.set('fuel_price_per_liter', costFuelPrice.value);
        body.set('fuel_consumption_l_100km', costFuelConsumption.value);
        body.set('ticket_price_per_km', costTicketPrice.value);
        body.set('model_variant', getAbVariant());

        let response;
        try {
            response = await fetch('/api/route.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
            });
        } catch (error) {
            console.error('[Smart Route Planner] Route request failed:', error);
            showError(t('genericNetworkError'));
            setLoading(false);
            return;
        }

        let raw;
        try {
            raw = await response.text();
        } catch (error) {
            console.error('[Smart Route Planner] Could not read route response:', error);
            showError(t('genericNetworkError'));
            setLoading(false);
            return;
        }

        let data;
        try {
            data = parseRouteJson(raw);
        } catch (error) {
            console.error('[Smart Route Planner] Route API returned invalid JSON:', error, raw);
            showError(routeUiMessage(
                'Сервер ответил, но вернул некорректные данные. Обновите страницу и попробуйте ещё раз.',
                'The server responded, but returned invalid data. Refresh the page and try again.'
            ));
            setLoading(false);
            return;
        }

        if (!response.ok || !data.ok) {
            showError(translateError(data.error_code, data.error));
            setLoading(false);
            return;
        }

        try {
            renderResult(data);
        } catch (error) {
            console.error('[Smart Route Planner] Route calculated, UI rendering failed:', error, data);
            showError(routeUiMessage(
                'Маршрут рассчитан, но интерфейс карты столкнулся с ошибкой. Попробуйте переключить 2D/3D или обновить страницу.',
                'The route was calculated, but the map UI hit an error. Try switching 2D/3D or refresh the page.'
            ));
        } finally {
            setLoading(false);
        }
    };
}

document.addEventListener('DOMContentLoaded', () => {
    initTheme();
    initResultTabs();
});
