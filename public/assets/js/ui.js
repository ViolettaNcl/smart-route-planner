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

document.addEventListener('DOMContentLoaded', () => {
    initTheme();
    initResultTabs();
});
