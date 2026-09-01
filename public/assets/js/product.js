(function () {
    'use strict';

    const HISTORY_KEY = 'srp_route_history_v2';
    const CURRENT_FAVORITE_CLASS = 'is-favorite';
    const STEP_SOURCE_ID = 'navigation-step-geometry';
    const STEP_LAYER_ID = 'navigation-step-line';
    let currentHistoryId = null;
    let draftMarkers = [];
    let mapPickEnabled = false;
    let mapClickBound = false;

    function isEnglish() {
        return typeof getLang === 'function' && getLang() === 'en';
    }

    function text(ru, en) {
        return isEnglish() ? en : ru;
    }

    function normalizeHistoryRecord(record) {
        if (!record || typeof record !== 'object' || !Array.isArray(record.stops)) return null;
        const stops = record.stops.map((stop) => {
            if (!stop || typeof stop !== 'object') return null;
            const label = typeof stop.label === 'string' ? stop.label.trim().slice(0, 180) : '';
            const lat = Number(stop.lat);
            const lon = Number(stop.lon);
            if (!label || !Number.isFinite(lat) || !Number.isFinite(lon)) return null;
            if (lat < -90 || lat > 90 || lon < -180 || lon > 180) return null;
            return { label, lat, lon };
        }).filter(Boolean).slice(0, 12);
        if (stops.length < 2) return null;

        const signature = routeSignature(stops);
        const createdAt = Number.isFinite(Date.parse(record.createdAt))
            ? record.createdAt
            : new Date().toISOString();
        return {
            id: typeof record.id === 'string' && record.id ? record.id : 'route-imported-' + signature.length,
            signature,
            stops,
            distanceKm: Number.isFinite(Number(record.distanceKm)) ? Number(record.distanceKm) : 0,
            durationLabel: typeof record.durationLabel === 'string' ? record.durationLabel : '',
            createdAt,
            favorite: Boolean(record.favorite),
        };
    }

    function readHistory() {
        try {
            const parsed = JSON.parse(localStorage.getItem(HISTORY_KEY) || '[]');
            return Array.isArray(parsed)
                ? parsed.map(normalizeHistoryRecord).filter(Boolean).slice(0, 16)
                : [];
        } catch (error) {
            return [];
        }
    }

    function writeHistory(items) {
        try {
            localStorage.setItem(HISTORY_KEY, JSON.stringify(items));
        } catch (error) {
            // Storage is an enhancement; private mode must not block routing.
        }
    }

    function routeSignature(stops) {
        return stops.map((stop) => [stop.label, stop.lat ?? null, stop.lon ?? null].join(':')).join('|');
    }

    function historyRecord(data) {
        const stops = Array.isArray(data.route_stops)
            ? data.route_stops.map((stop) => ({
                label: stop.label,
                lat: Number(stop.lat),
                lon: Number(stop.lon),
            }))
            : data.points.map((label, index) => ({
                label,
                lat: Number(data.coords[index]?.lat),
                lon: Number(data.coords[index]?.lon),
            }));
        const signature = routeSignature(stops);
        return {
            id: 'route-' + Date.now().toString(36),
            signature,
            stops,
            distanceKm: Number(data.distance_km),
            durationLabel: String(data.duration?.label || ''),
            createdAt: new Date().toISOString(),
            favorite: false,
        };
    }

    function saveRecentRoute(data) {
        const record = historyRecord(data);
        const items = readHistory();
        const previous = items.find((item) => item.signature === record.signature);
        if (previous) {
            record.id = previous.id;
            record.favorite = Boolean(previous.favorite);
        }
        const next = [record, ...items.filter((item) => item.signature !== record.signature)];
        const favorites = next.filter((item) => item.favorite);
        const recent = next.filter((item) => !item.favorite).slice(0, 8);
        writeHistory([...favorites, ...recent].slice(0, 16));
        currentHistoryId = record.id;
        renderSavedRoutes();
        refreshFavoriteButton();
    }

    function routeTitle(record) {
        const labels = record.stops.map((stop) => stop.label).filter(Boolean);
        if (labels.length <= 2) return labels.join(' → ');
        return labels[0] + ' → ' + labels[labels.length - 1] + ' · +' + (labels.length - 2);
    }

    function formatDate(value) {
        try {
            return new Intl.DateTimeFormat(isEnglish() ? 'en' : 'ru', {
                day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit',
            }).format(new Date(value));
        } catch (error) {
            return '';
        }
    }

    function renderSavedRoutes() {
        const container = document.getElementById('saved-routes-list');
        if (!container) return;
        const items = readHistory();
        container.innerHTML = '';
        if (items.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'saved-routes-empty';
            empty.textContent = text('Маршруты появятся после первого расчёта.', 'Routes will appear after your first calculation.');
            container.appendChild(empty);
            return;
        }

        items.forEach((record) => {
            const item = document.createElement('article');
            item.className = 'saved-route-item';
            item.dataset.historyId = record.id;

            const open = document.createElement('button');
            open.type = 'button';
            open.className = 'saved-route-open';
            const title = document.createElement('strong');
            title.textContent = routeTitle(record);
            const meta = document.createElement('small');
            meta.textContent = record.distanceKm + ' ' + text('км', 'km') + ' · '
                + record.durationLabel + ' · ' + formatDate(record.createdAt);
            open.append(title, meta);

            const favorite = document.createElement('button');
            favorite.type = 'button';
            favorite.className = 'saved-route-star' + (record.favorite ? ' active' : '');
            favorite.dataset.historyAction = 'favorite';
            favorite.setAttribute('aria-label', text('Избранное', 'Favourite'));
            favorite.textContent = record.favorite ? '★' : '☆';

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'saved-route-remove';
            remove.dataset.historyAction = 'remove';
            remove.setAttribute('aria-label', text('Удалить из истории', 'Remove from history'));
            remove.textContent = '×';

            item.append(open, favorite, remove);
            container.appendChild(item);
        });
    }

    function toggleHistoryFavorite(id) {
        const items = readHistory().map((item) => item.id === id
            ? { ...item, favorite: !item.favorite }
            : item);
        writeHistory(items);
        renderSavedRoutes();
        refreshFavoriteButton();
    }

    function refreshFavoriteButton() {
        const button = document.getElementById('favorite-route-button');
        if (!button) return;
        const active = readHistory().some((item) => item.id === currentHistoryId && item.favorite);
        button.classList.toggle(CURRENT_FAVORITE_CLASS, active);
        button.textContent = active
            ? text('★ В избранном', '★ Favourited')
            : text('☆ В избранное', '☆ Add to favourites');
        button.setAttribute('aria-pressed', active ? 'true' : 'false');
    }

    function optionBadge(option, options, index) {
        if (index === 0) return text('Основной', 'Primary');
        const distances = options.map((item) => Number(item.distance_km));
        const durations = options.map((item) => Number(item.driving_duration_min));
        if (Number.isFinite(durations[index]) && durations[index] === Math.min(...durations.filter(Number.isFinite))) {
            return text('Быстрее', 'Fastest');
        }
        if (distances[index] === Math.min(...distances)) return text('Короче', 'Shortest');
        return text('Альтернатива', 'Alternative');
    }

    function renderRouteOptions(data) {
        const container = document.getElementById('route-options');
        const note = document.getElementById('route-options-note');
        if (!container) return;
        const options = Array.isArray(data.route_options) && data.route_options.length
            ? data.route_options
            : [{
                id: 'route-1', distance_km: data.distance_km, duration: data.duration,
                cost: data.cost, emissions: data.emissions, geometry: data.route_geometry,
                legs: data.legs || [], source: data.routing_source,
            }];
        const selectedId = data.selected_route_id || options[0].id;
        container.innerHTML = '';
        options.forEach((option, index) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'route-option-card' + (option.id === selectedId ? ' active' : '');
            button.dataset.routeOptionId = option.id;
            button.setAttribute('role', 'radio');
            button.setAttribute('aria-checked', option.id === selectedId ? 'true' : 'false');
            button.tabIndex = option.id === selectedId ? 0 : -1;

            const top = document.createElement('span');
            top.className = 'route-option-top';
            const name = document.createElement('strong');
            name.textContent = text('Маршрут ', 'Route ') + (index + 1);
            const badge = document.createElement('span');
            badge.className = 'route-option-badge';
            badge.textContent = optionBadge(option, options, index);
            top.append(name, badge);

            const metrics = document.createElement('span');
            metrics.className = 'route-option-metrics';
            const duration = option.duration?.label || '—';
            const amount = Number(option.cost?.amount || 0);
            metrics.innerHTML = '<span><b>' + escapeHtml(String(option.distance_km)) + '</b> км</span>'
                + '<span><b>' + escapeHtml(duration) + '</b></span>'
                + '<span><b>' + escapeHtml(String(amount)) + '</b> ₽</span>';
            const nav = document.createElement('small');
            nav.textContent = option.navigation_available
                ? text('Пошаговая навигация доступна', 'Turn-by-turn available')
                : text('Без пошаговых манёвров', 'No turn-by-turn maneuvers');
            button.append(top, metrics, nav);
            container.appendChild(button);
        });

        if (note) {
            note.textContent = typeof routingSourceLabel === 'function'
                ? routingSourceLabel(data, true)
                : (data.routing_source === 'osrm_road'
                    ? text('Данные дорожной сети · OSRM', 'Road network data · OSRM')
                    : text('OSRM недоступен: показан честный прямолинейный fallback', 'OSRM unavailable: showing the honest straight-line fallback'));
        }
    }

    function modifierText(modifier) {
        const ru = {
            'uturn': 'развернитесь', 'sharp right': 'резко направо', 'right': 'направо',
            'slight right': 'плавно направо', 'straight': 'прямо', 'slight left': 'плавно налево',
            'left': 'налево', 'sharp left': 'резко налево',
        };
        const en = {
            'uturn': 'make a U-turn', 'sharp right': 'turn sharply right', 'right': 'turn right',
            'slight right': 'bear right', 'straight': 'continue straight', 'slight left': 'bear left',
            'left': 'turn left', 'sharp left': 'turn sharply left',
        };
        return (isEnglish() ? en : ru)[modifier] || text('продолжайте движение', 'continue');
    }

    function maneuverInstruction(step) {
        const maneuver = step.maneuver || {};
        const type = maneuver.type || 'continue';
        const road = [step.name, step.ref].filter(Boolean).join(' · ');
        let instruction;
        if (type === 'depart') instruction = text('Начните движение', 'Start');
        else if (type === 'arrive') instruction = text('Вы прибыли в пункт назначения', 'You have arrived');
        else if (type === 'roundabout' || type === 'rotary') {
            instruction = maneuver.exit
                ? text('На круге выберите съезд №' + maneuver.exit, 'At the roundabout take exit ' + maneuver.exit)
                : text('Въезжайте на круговое движение', 'Enter the roundabout');
        } else if (type === 'merge') instruction = text('Встройтесь в поток: ', 'Merge: ') + modifierText(maneuver.modifier);
        else if (type === 'fork') instruction = text('На развилке держитесь: ', 'At the fork: ') + modifierText(maneuver.modifier);
        else if (type === 'on ramp') instruction = text('Въезжайте на съезд: ', 'Take the ramp: ') + modifierText(maneuver.modifier);
        else if (type === 'off ramp') instruction = text('Съезжайте: ', 'Take the exit: ') + modifierText(maneuver.modifier);
        else if (type === 'end of road') instruction = text('В конце дороги ', 'At the end of the road ') + modifierText(maneuver.modifier);
        else if (type === 'turn') instruction = text('Поверните: ', '') + modifierText(maneuver.modifier);
        else instruction = modifierText(maneuver.modifier);
        return road && type !== 'arrive' ? instruction + text(' на ', ' onto ') + road : instruction;
    }

    function maneuverIcon(step) {
        const maneuver = step.maneuver || {};
        if (maneuver.type === 'arrive') return '●';
        if (maneuver.type === 'depart') return '◆';
        if ((maneuver.type || '').includes('roundabout') || maneuver.type === 'rotary') return '↻';
        const icons = { left: '↰', 'slight left': '↖', 'sharp left': '↶', right: '↱', 'slight right': '↗', 'sharp right': '↷', uturn: '↶' };
        return icons[maneuver.modifier] || '↑';
    }

    function distanceLabel(meters) {
        const value = Number(meters || 0);
        if (value >= 1000) return (value / 1000).toLocaleString(isEnglish() ? 'en' : 'ru', { maximumFractionDigits: 1 }) + ' км';
        return Math.max(0, Math.round(value)) + ' м';
    }

    function flattenSteps(legs) {
        const steps = [];
        (Array.isArray(legs) ? legs : []).forEach((leg, legIndex) => {
            (Array.isArray(leg.steps) ? leg.steps : []).forEach((step) => steps.push({ ...step, legIndex }));
        });
        return steps;
    }

    function renderNavigation(data) {
        const list = document.getElementById('navigation-steps');
        const empty = document.getElementById('navigation-empty');
        if (!list || !empty) return;
        const steps = flattenSteps(data.legs || []);
        list.innerHTML = '';
        empty.classList.toggle('hidden', steps.length > 0);
        if (steps.length === 0) return;

        steps.forEach((step, index) => {
            const item = document.createElement('li');
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'navigation-step';
            button.dataset.navigationStep = String(index);
            const icon = document.createElement('span');
            icon.className = 'navigation-step-icon';
            icon.textContent = maneuverIcon(step);
            const copy = document.createElement('span');
            copy.className = 'navigation-step-copy';
            const instruction = document.createElement('strong');
            instruction.textContent = maneuverInstruction(step);
            const detail = document.createElement('small');
            detail.textContent = distanceLabel(step.distance_m) + (step.duration_min ? ' · ' + step.duration_min + ' мин' : '');
            copy.append(instruction, detail);
            button.append(icon, copy);
            button.addEventListener('click', () => highlightNavigationStep(step, button));
            item.appendChild(button);
            list.appendChild(item);
        });
    }

    function highlightNavigationStep(step, button) {
        document.querySelectorAll('.navigation-step.active').forEach((item) => item.classList.remove('active'));
        button.classList.add('active');
        const geometry = Array.isArray(step.geometry) ? step.geometry : [];
        const mapReady = typeof mapStyleCanAcceptRouteLayers === 'function'
            ? mapStyleCanAcceptRouteLayers()
            : Boolean(routeMap && routeMap.isStyleLoaded());
        if (!routeMap || geometry.length < 2 || !mapReady) return;
        const coordinates = geometry.map((point) => [Number(point[1]), Number(point[0])]);
        try {
            const source = routeMap.getSource(STEP_SOURCE_ID);
            const geojson = { type: 'Feature', properties: {}, geometry: { type: 'LineString', coordinates } };
            if (source) source.setData(geojson);
            else {
                routeMap.addSource(STEP_SOURCE_ID, { type: 'geojson', data: geojson });
                routeMap.addLayer({
                    id: STEP_LAYER_ID, type: 'line', source: STEP_SOURCE_ID,
                    layout: { 'line-cap': 'round', 'line-join': 'round' },
                    paint: { 'line-color': '#ffb547', 'line-width': 9, 'line-opacity': 0.94 },
                });
            }
            const bounds = new maplibregl.LngLatBounds();
            coordinates.forEach((coordinate) => bounds.extend(coordinate));
            routeMap.fitBounds(bounds, { padding: 100, maxZoom: 16, duration: prefersReducedMotion() ? 0 : 650 });
        } catch (error) {
            console.warn('[Smart Route Planner] Step highlight unavailable:', error);
        }
    }

    function applySelectedOption(optionId) {
        if (!lastRouteData || !Array.isArray(lastRouteData.route_options)) return;
        const option = lastRouteData.route_options.find((item) => item.id === optionId);
        if (!option) return;
        lastRouteData = {
            ...lastRouteData,
            selected_route_id: option.id,
            distance_km: option.distance_km,
            duration: option.duration,
            cost: option.cost,
            emissions: option.emissions,
            route_geometry: option.geometry,
            legs: option.legs || [],
        };
        populateMapSummary(lastRouteData);
        document.getElementById('stat-distance').textContent = option.distance_km + ' км';
        document.getElementById('stat-time').textContent = option.duration?.label || '—';
        const amount = Number(option.cost?.amount || 0);
        document.getElementById('stat-cost').textContent = (typeof formatNumber === 'function' ? formatNumber(amount) : amount) + ' ' + t('currency');
        document.getElementById('stat-emissions').textContent = Number(option.emissions?.co2_kg || 0) + ' кг';
        const comparison = Object.entries(option.emissions?.comparison || {})
            .filter(([mode]) => mode !== lastRouteData.transport.mode)
            .map(([mode, kg]) => modeLabelFor(mode) + ': ' + kg + ' кг');
        document.getElementById('emissions-compare').textContent = comparison.length
            ? t('emissionsNote') + ' (' + comparison.join(', ') + ')'
            : t('emissionsNote');
        renderRouteOptions(lastRouteData);
        renderNavigation(lastRouteData);
        renderMap(lastRouteData.coords, lastRouteData.points, option.geometry);
        window.updateMlLabForRoute?.(lastRouteData);
        saveRecentRoute(lastRouteData);
    }

    function clearDraftMarkers() {
        draftMarkers.forEach((marker) => marker.remove());
        draftMarkers = [];
    }

    function renderDraftStops(stops) {
        if (!routeMap || typeof maplibregl === 'undefined') return;
        clearDraftMarkers();
        stops.filter((stop) => Number.isFinite(stop.lat) && Number.isFinite(stop.lon)).forEach((stop, index) => {
            const element = document.createElement('span');
            element.className = 'draft-stop-marker';
            element.textContent = String(index + 1);
            const marker = new maplibregl.Marker({ element }).setLngLat([stop.lon, stop.lat]).addTo(routeMap);
            draftMarkers.push(marker);
        });
    }

    function bindMapClick() {
        if (!routeMap || mapClickBound) return;
        routeMap.on('click', (event) => {
            if (!mapPickEnabled) return;
            const accepted = window.routeEditor?.receiveMapPoint(event.lngLat.lat, event.lngLat.lng);
            if (accepted) window.enableRouteMapPick(false);
        });
        mapClickBound = true;
    }

    window.enableRouteMapPick = function (enabled) {
        const wasMapPickEnabled = mapPickEnabled;
        mapPickEnabled = Boolean(enabled);
        const pickHint = document.getElementById('map-pick-hint');
        hide(mapPlaceholder);
        show(mapContainer);
        show(mapModeControl);
        if (!routeMap && typeof createRouteMap === 'function') createRouteMap();
        if (routeMap) {
            bindMapClick();
            routeMap.getCanvas().style.cursor = mapPickEnabled ? 'crosshair' : '';
            routeMap.resize();
        }
        mapPanel?.classList.toggle('map-picking', mapPickEnabled);
        if (pickHint) {
            if (mapPickEnabled) show(pickHint);
            else hide(pickHint);
        }
        if (mapPickEnabled) setSheetState('peek');
        else if (wasMapPickEnabled && window.innerWidth <= 700) setSheetState('full');
    };

    function setSheetState(state) {
        const panel = document.querySelector('.panel');
        if (!panel) return;
        const mobile = window.innerWidth <= 700;
        const normalized = state === 'peek' ? 'peek' : 'full';
        const collapsed = mobile && normalized === 'peek';
        const sheetContent = document.getElementById('route-editor-sheet-content');
        panel.classList.remove('sheet-peek', 'sheet-half', 'sheet-full');
        panel.classList.add('sheet-' + normalized);
        panel.dataset.sheetState = normalized;
        document.getElementById('panel-sheet-handle')?.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        const expandedModal = mobile && normalized === 'full';
        document.body.classList.toggle('route-sheet-open', expandedModal);

        const backgroundTargets = [
            ...document.querySelectorAll('.container > :not(.layout):not(script)'),
            ...document.querySelectorAll('.layout > :not(.panel)'),
        ];
        backgroundTargets.forEach((element) => {
            element.inert = expandedModal;
        });

        if (sheetContent) {
            sheetContent.inert = collapsed;
            if (collapsed) sheetContent.setAttribute('aria-hidden', 'true');
            else sheetContent.removeAttribute('aria-hidden');
        }

        if (expandedModal) {
            panel.setAttribute('role', 'dialog');
            panel.setAttribute('aria-modal', 'true');
        } else {
            panel.removeAttribute('role');
            panel.removeAttribute('aria-modal');
        }

        if (collapsed) panel.scrollTop = 0;
    }

    function escapeXml(value) {
        return String(value).replace(/[<>&'\"]/g, (char) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;', "'": '&apos;', '"': '&quot;' }[char]));
    }

    function downloadFile(filename, type, content) {
        const url = URL.createObjectURL(new Blob([content], { type }));
        const anchor = document.createElement('a');
        anchor.href = url;
        anchor.download = filename;
        document.body.appendChild(anchor);
        anchor.click();
        anchor.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    }

    function routeCoordinates() {
        return (lastRouteData?.route_geometry || []).map((point) => [Number(point[1]), Number(point[0])]);
    }

    function exportGeoJson() {
        if (!lastRouteData) return;
        const features = [{
            type: 'Feature', properties: { name: lastRouteData.points.join(' → '), distance_km: lastRouteData.distance_km },
            geometry: { type: 'LineString', coordinates: routeCoordinates() },
        }];
        lastRouteData.coords.forEach((coord, index) => features.push({
            type: 'Feature', properties: { name: lastRouteData.points[index], order: index + 1 },
            geometry: { type: 'Point', coordinates: [Number(coord.lon), Number(coord.lat)] },
        }));
        downloadFile('smart-route.geojson', 'application/geo+json', JSON.stringify({ type: 'FeatureCollection', features }, null, 2));
    }

    function exportGpx() {
        if (!lastRouteData) return;
        const waypoints = lastRouteData.coords.map((coord, index) => '<wpt lat="' + coord.lat + '" lon="' + coord.lon + '"><name>' + escapeXml(lastRouteData.points[index]) + '</name></wpt>').join('');
        const track = routeCoordinates().map((coord) => '<trkpt lat="' + coord[1] + '" lon="' + coord[0] + '"></trkpt>').join('');
        downloadFile('smart-route.gpx', 'application/gpx+xml', '<?xml version="1.0" encoding="UTF-8"?><gpx version="1.1" creator="Smart Route Planner" xmlns="http://www.topografix.com/GPX/1/1">' + waypoints + '<trk><name>Smart Route</name><trkseg>' + track + '</trkseg></trk></gpx>');
    }

    function exportKml() {
        if (!lastRouteData) return;
        const coordinates = routeCoordinates().map((coord) => coord[0] + ',' + coord[1] + ',0').join(' ');
        downloadFile('smart-route.kml', 'application/vnd.google-earth.kml+xml', '<?xml version="1.0" encoding="UTF-8"?><kml xmlns="http://www.opengis.net/kml/2.2"><Document><Placemark><name>' + escapeXml(lastRouteData.points.join(' → ')) + '</name><LineString><tessellate>1</tessellate><coordinates>' + coordinates + '</coordinates></LineString></Placemark></Document></kml>');
    }

    function enhanceResult(data) {
        clearDraftMarkers();
        renderRouteOptions(data);
        renderNavigation(data);
        saveRecentRoute(data);
        if (window.innerWidth <= 700) setSheetState('peek');
    }

    if (typeof renderResult === 'function') {
        const baseRenderResult = renderResult;
        renderResult = function (data) {
            baseRenderResult(data);
            enhanceResult(data);
        };
    }

    const previousLanguageRefresh = window.refreshRouteUiLanguage;
    window.refreshRouteUiLanguage = function () {
        if (typeof previousLanguageRefresh === 'function') previousLanguageRefresh();
        window.routeEditor?.refreshLabels();
        window.refreshMlLabLanguage?.();
        renderSavedRoutes();
        refreshFavoriteButton();
        if (lastRouteData) {
            renderRouteOptions(lastRouteData);
            renderNavigation(lastRouteData);
        }
    };

    document.addEventListener('DOMContentLoaded', () => {
        renderSavedRoutes();
        refreshFavoriteButton();
        bindMapClick();

        if (new URLSearchParams(location.search).get('demo') === '1') {
            window.routeEditor?.loadDemo();
        }

        document.getElementById('route-options')?.addEventListener('click', (event) => {
            const button = event.target.closest('[data-route-option-id]');
            if (button) applySelectedOption(button.dataset.routeOptionId);
        });
        document.getElementById('route-options')?.addEventListener('keydown', (event) => {
            if (!['ArrowRight', 'ArrowDown', 'ArrowLeft', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
            const buttons = Array.from(document.querySelectorAll('[data-route-option-id]'));
            if (buttons.length < 2) return;
            const activeIndex = Math.max(0, buttons.indexOf(document.activeElement));
            let nextIndex = activeIndex;
            if (event.key === 'ArrowRight' || event.key === 'ArrowDown') nextIndex = (activeIndex + 1) % buttons.length;
            if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') nextIndex = (activeIndex - 1 + buttons.length) % buttons.length;
            if (event.key === 'Home') nextIndex = 0;
            if (event.key === 'End') nextIndex = buttons.length - 1;
            event.preventDefault();
            applySelectedOption(buttons[nextIndex].dataset.routeOptionId);
            document.querySelector('[data-route-option-id="' + CSS.escape(buttons[nextIndex].dataset.routeOptionId) + '"]')?.focus();
        });

        document.getElementById('saved-routes-list')?.addEventListener('click', (event) => {
            const item = event.target.closest('[data-history-id]');
            if (!item) return;
            const id = item.dataset.historyId;
            if (event.target.closest('[data-history-action="favorite"]')) {
                toggleHistoryFavorite(id);
                return;
            }
            if (event.target.closest('[data-history-action="remove"]')) {
                writeHistory(readHistory().filter((record) => record.id !== id));
                if (currentHistoryId === id) currentHistoryId = null;
                renderSavedRoutes();
                refreshFavoriteButton();
                return;
            }
            const record = readHistory().find((entry) => entry.id === id);
            if (record) {
                window.routeEditor?.setStops(record.stops);
                document.getElementById('route-form')?.requestSubmit();
            }
        });

        document.getElementById('favorite-route-button')?.addEventListener('click', () => {
            if (currentHistoryId) toggleHistoryFavorite(currentHistoryId);
        });
        document.getElementById('clear-history-button')?.addEventListener('click', () => {
            writeHistory(readHistory().filter((item) => item.favorite));
            if (!readHistory().some((item) => item.id === currentHistoryId)) currentHistoryId = null;
            renderSavedRoutes();
            refreshFavoriteButton();
        });
        document.getElementById('export-geojson-button')?.addEventListener('click', exportGeoJson);
        document.getElementById('export-gpx-button')?.addEventListener('click', exportGpx);
        document.getElementById('export-kml-button')?.addEventListener('click', exportKml);
        document.getElementById('print-route-button')?.addEventListener('click', () => window.print());

        document.addEventListener('route-editor:change', (event) => renderDraftStops(event.detail.stops));

        const handle = document.getElementById('panel-sheet-handle');
        handle?.addEventListener('click', () => {
            const current = document.querySelector('.panel')?.dataset.sheetState || 'peek';
            setSheetState(current === 'peek' ? 'full' : 'peek');
        });
        document.getElementById('map-focus-toggle')?.addEventListener('click', () => setSheetState('peek'));
        document.getElementById('route-stop-list')?.addEventListener('focusin', () => {
            if (window.innerWidth <= 700) setSheetState('full');
        });
        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') return;
            window.routeEditor?.cancelMapPick();
            if (window.innerWidth <= 700) setSheetState('peek');
        });

        let mobileSheetViewport = window.innerWidth <= 700;
        setSheetState(mobileSheetViewport ? 'peek' : 'full');
        window.addEventListener('resize', () => {
            const mobile = window.innerWidth <= 700;
            if (mobile === mobileSheetViewport) return;
            mobileSheetViewport = mobile;
            setSheetState(mobile ? 'peek' : 'full');
        }, { passive: true });
    });
})();
