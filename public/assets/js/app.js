/**
 * Фронтенд smart-route-planner.
 *
 * Форма отправляется через fetch на api/route.php, без перезагрузки страницы.
 * Ответ — JSON с оптимизированным порядком точек, координатами, реальной
 * дорожной геометрией (если доступен OSRM), временем в пути, стоимостью
 * поездки и предсказанным транспортом. Карта строится через Leaflet.
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

let leafletMap = null;
let routeLayer = null;
let poiLayer = null;
let poiVisible = false;
let poiFetchedForRoute = null; // хранит JSON.stringify(coords) маршрута, для которого уже запрашивали POI
let lastRouteData = null;

// --- тайлы карты, зависящие от темы интерфейса (см. также ui.js) ---
let tileLayerRef = null;

const MAP_TILE_URLS = {
    dark: 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',
    light: 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png',
};

function currentMapTheme() {
    return document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
}

function addMapTileLayer(theme) {
    tileLayerRef = L.tileLayer(MAP_TILE_URLS[theme] || MAP_TILE_URLS.dark, {
        attribution: '© OpenStreetMap contributors © CARTO',
        maxZoom: 19,
        subdomains: 'abcd',
    }).addTo(leafletMap);
}

// Вызывается из ui.js при переключении светлой/тёмной темы, чтобы перекрасить
// подложку карты вслед за интерфейсом (тёмные тайлы на светлом фоне и наоборот
// выглядят чужеродно).
window.setMapTileTheme = function (theme) {
    if (!leafletMap) return;
    if (tileLayerRef) {
        leafletMap.removeLayer(tileLayerRef);
    }
    addMapTileLayer(theme === 'light' ? 'light' : 'dark');
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

    if (poiLayer && poiFetchedForRoute === routeKey) {
        // Уже загружали POI для этого маршрута — просто показываем заново.
        poiLayer.addTo(leafletMap);
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
    if (poiLayer) {
        leafletMap.removeLayer(poiLayer);
    }

    poiLayer = L.layerGroup();
    const categoryLabels = t('poiCategoryLabels');

    pointsWithPlaces.forEach((point) => {
        point.places.forEach((place) => {
            const icon = L.divIcon({
                html: '<span class="poi-marker-icon">' + place.icon + '</span>',
                className: 'poi-marker',
                iconSize: [26, 26],
            });

            L.marker([place.lat, place.lon], { icon })
                .bindPopup('<strong>' + escapeHtml(place.name) + '</strong><br>'
                    + (categoryLabels[place.category] || place.label_ru))
                .addTo(poiLayer);
        });
    });

    poiLayer.addTo(leafletMap);
}

function hidePoiLayer() {
    if (poiLayer) {
        leafletMap.removeLayer(poiLayer);
    }
    poiVisible = false;
    poiButton.textContent = t('poiButton');
}

function resetExplainState() {
    explainLoadedForRoute = null;
    hide(explainPanel);
    explainToggle.textContent = t('explainToggle');
}

function resetPoiState() {
    if (poiLayer) {
        leafletMap.removeLayer(poiLayer);
    }
    poiLayer = null;
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

function renderMap(coords, labels, routeGeometry) {
    hide(mapPlaceholder);
    show(mapContainer);

    if (!leafletMap) {
        // Тёмные тайлы (CartoDB Dark Matter, бесплатно, без ключа) — вместо
        // стандартных светлых тайлов OSM, которые визуально спорили с тёмной
        // темой интерфейса. Атрибуция OpenStreetMap обязательна по лицензии
        // ODbL и остаётся; убран только необязательный префикс "Leaflet"
        // (в него по умолчанию добавлен флаг Украины — решение мейнтейнера
        // библиотеки, а не требование лицензии), чтобы в UI не было
        // политической символики, никак не связанной с сутью проекта.
        leafletMap = L.map('map', { attributionControl: false });
        L.control.attribution({ prefix: false }).addTo(leafletMap);

        addMapTileLayer(currentMapTheme());
    }

    // Карта могла быть инициализирована до того, как секция получила
    // окончательные размеры в новом layout — пересчитываем размер тайлов.
    setTimeout(() => leafletMap.invalidateSize(), 0);

    if (routeLayer) {
        leafletMap.removeLayer(routeLayer);
    }

    const group = L.featureGroup();

    // Маркеры пронумерованы и оформлены так же, как список городов в
    // сайдбаре (янтарный кружок + моно-номер) — карта и список читаются
    // как единая система, а не два разных визуальных языка.
    coords.forEach((c, i) => {
        const icon = L.divIcon({
            html: '<span class="route-marker-badge">' + (i + 1) + '</span>',
            className: 'route-marker',
            iconSize: [26, 26],
            iconAnchor: [13, 13],
        });

        L.marker([c.lat, c.lon], { icon })
            .bindPopup((i + 1) + '. ' + escapeHtml(labels[i]))
            .addTo(group);
    });

    // route_geometry — либо реальная геометрия дороги от OSRM (много точек,
    // повторяет изгибы трассы), либо просто список городов (прямые линии),
    // если OSRM был недоступен — в обоих случаях это массив [lat, lon].
    const pathLatLngs = routeGeometry.map((p) => Array.isArray(p) ? p : [p.lat, p.lon]);

    // Двойная линия: широкая полупрозрачная подложка ("свечение" GPS-трека)
    // + узкая пунктирная поверх с CSS-анимацией "бегущей" разметки —
    // маршрут читается как активный, "живой" трек, а не статичная линия.
    L.polyline(pathLatLngs, { color: '#4fd1c5', weight: 9, opacity: 0.18 }).addTo(group);
    L.polyline(pathLatLngs, {
        color: '#f5a623',
        weight: 4,
        dashArray: '10 8',
        className: 'route-line-animated',
    }).addTo(group);

    group.addTo(leafletMap);
    routeLayer = group;

    leafletMap.fitBounds(group.getBounds(), { padding: [30, 30] });
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
