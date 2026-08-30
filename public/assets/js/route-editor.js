(function () {
    'use strict';

    const MAX_STOPS = 12;
    let serial = 0;

    function uid() {
        serial += 1;
        return 'editor-stop-' + Date.now().toString(36) + '-' + serial;
    }

    function copyStop(stop) {
        const lat = Number(stop && stop.lat);
        const lon = Number(stop && stop.lon);
        return {
            id: String((stop && stop.id) || uid()),
            label: String((stop && stop.label) || '').trim(),
            lat: Number.isFinite(lat) && lat >= -90 && lat <= 90 ? lat : null,
            lon: Number.isFinite(lon) && lon >= -180 && lon <= 180 ? lon : null,
        };
    }

    function createRouteEditor() {
        const list = document.getElementById('route-stop-list');
        const legacyInput = document.getElementById('points');
        const jsonInput = document.getElementById('stops-json');
        const count = document.getElementById('route-point-count');
        const addButton = document.getElementById('add-stop-button');
        const reverseButton = document.getElementById('reverse-route-button');
        const mapPickButton = document.getElementById('map-pick-button');
        const demoButton = document.getElementById('demo-route-button');

        if (!list || !legacyInput || !jsonInput) return null;

        let stops = [copyStop({}), copyStop({})];
        let draggingId = null;
        let mapTargetId = null;

        function language() {
            return typeof getLang === 'function' ? getLang() : 'ru';
        }

        function roleLabel(index) {
            if (index === 0) return language() === 'en' ? 'Start' : 'Старт';
            if (index === stops.length - 1) return language() === 'en' ? 'Finish' : 'Финиш';
            return language() === 'en' ? 'Stop' : 'Остановка';
        }

        function placeholder(index) {
            if (index === 0) return language() === 'en' ? 'Starting point or address' : 'Откуда начинаем';
            if (index === stops.length - 1) return language() === 'en' ? 'Destination' : 'Куда едем';
            return language() === 'en' ? 'Intermediate stop' : 'Промежуточная точка';
        }

        function compactStop(stop) {
            const result = { id: stop.id, label: stop.label };
            if (Number.isFinite(stop.lat) && Number.isFinite(stop.lon)) {
                result.lat = Number(stop.lat.toFixed(6));
                result.lon = Number(stop.lon.toFixed(6));
            }
            return result;
        }

        function sync(announce = true) {
            legacyInput.value = stops.map((stop) => stop.label.trim()).filter(Boolean).join(';');
            jsonInput.value = JSON.stringify(stops.map(compactStop));
            const filled = stops.filter((stop) => stop.label.trim() || (stop.lat !== null && stop.lon !== null)).length;
            if (count) {
                const formatter = typeof t === 'function' ? t('routePointCount') : null;
                count.textContent = typeof formatter === 'function' ? formatter(filled) : String(filled);
            }
            if (announce) {
                document.dispatchEvent(new CustomEvent('route-editor:change', {
                    detail: { stops: getStops() },
                }));
            }
        }

        function iconButton(action, label, text, disabled) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'stop-icon-button';
            button.dataset.action = action;
            button.setAttribute('aria-label', label);
            button.title = label;
            button.textContent = text;
            button.disabled = Boolean(disabled);
            return button;
        }

        function render(focusId = null) {
            list.innerHTML = '';
            stops.forEach((stop, index) => {
                const row = document.createElement('div');
                row.className = 'route-stop-row';
                row.dataset.stopId = stop.id;
                row.setAttribute('role', 'listitem');
                row.draggable = true;

                const rail = document.createElement('span');
                rail.className = 'stop-rail';
                rail.innerHTML = '<span class="stop-number">' + (index + 1) + '</span>'
                    + (index < stops.length - 1 ? '<span class="stop-rail-line"></span>' : '');

                const body = document.createElement('div');
                body.className = 'stop-field-body';
                const meta = document.createElement('div');
                meta.className = 'stop-field-meta';
                const role = document.createElement('span');
                role.className = 'stop-role';
                role.textContent = roleLabel(index);
                const coordinate = document.createElement('span');
                coordinate.className = 'stop-coordinate';
                coordinate.textContent = stop.lat !== null && stop.lon !== null
                    ? stop.lat.toFixed(4) + ', ' + stop.lon.toFixed(4)
                    : (language() === 'en' ? 'address search on submit' : 'поиск адреса при расчёте');
                meta.append(role, coordinate);

                const input = document.createElement('input');
                input.type = 'text';
                input.className = 'stop-label-input';
                input.value = stop.label;
                input.placeholder = placeholder(index);
                input.autocomplete = 'street-address';
                input.maxLength = 180;
                input.dataset.stopInput = stop.id;
                input.setAttribute('aria-label', roleLabel(index));
                body.append(meta, input);

                const controls = document.createElement('div');
                controls.className = 'stop-row-actions';
                controls.append(
                    iconButton('pick', language() === 'en' ? 'Pick this stop on map' : 'Выбрать эту точку на карте', '⌖'),
                    iconButton('up', language() === 'en' ? 'Move up' : 'Переместить выше', '↑', index === 0),
                    iconButton('down', language() === 'en' ? 'Move down' : 'Переместить ниже', '↓', index === stops.length - 1),
                    iconButton('remove', language() === 'en' ? 'Remove stop' : 'Удалить точку', '×', stops.length <= 2)
                );

                row.append(rail, body, controls);
                list.appendChild(row);
            });
            if (addButton) {
                addButton.disabled = stops.length >= MAX_STOPS;
                addButton.setAttribute('aria-disabled', addButton.disabled ? 'true' : 'false');
            }
            sync(false);
            if (focusId) {
                list.querySelector('[data-stop-input="' + CSS.escape(focusId) + '"]')?.focus();
            }
        }

        function move(id, delta) {
            const index = stops.findIndex((stop) => stop.id === id);
            const next = index + delta;
            if (index < 0 || next < 0 || next >= stops.length) return;
            [stops[index], stops[next]] = [stops[next], stops[index]];
            render(id);
            sync();
        }

        function addStop(stop = {}, focus = true) {
            if (stops.length >= MAX_STOPS) return false;
            const next = copyStop(stop);
            stops.splice(Math.max(1, stops.length - 1), 0, next);
            render(focus ? next.id : null);
            sync();
            return true;
        }

        function deactivateMapPick() {
            mapTargetId = null;
            mapPickButton?.classList.remove('active');
            mapPickButton?.setAttribute('aria-pressed', 'false');
            if (typeof window.enableRouteMapPick === 'function') {
                window.enableRouteMapPick(false);
            }
        }

        function activateMapPick(targetId = null) {
            if (mapPickButton?.classList.contains('active') && mapTargetId === targetId) {
                deactivateMapPick();
                return;
            }
            mapTargetId = targetId;
            mapPickButton?.classList.add('active');
            mapPickButton?.setAttribute('aria-pressed', 'true');
            if (typeof window.enableRouteMapPick === 'function') {
                window.enableRouteMapPick(true);
            }
        }

        function receiveMapPoint(lat, lon, label) {
            let target = mapTargetId ? stops.find((stop) => stop.id === mapTargetId) : null;
            if (!target) {
                target = stops.find((stop) => !stop.label.trim() && stop.lat === null);
            }
            if (!target) {
                if (!addStop({}, false)) return false;
                target = stops[stops.length - 2];
            }
            target.lat = Number(lat);
            target.lon = Number(lon);
            target.label = label || (language() === 'en' ? 'Map point' : 'Точка на карте')
                + ' · ' + target.lat.toFixed(5) + ', ' + target.lon.toFixed(5);
            deactivateMapPick();
            render(target.id);
            sync();
            return true;
        }

        function getStops() {
            return stops.map(compactStop);
        }

        function setStops(nextStops) {
            const normalized = Array.isArray(nextStops) ? nextStops.slice(0, MAX_STOPS).map(copyStop) : [];
            deactivateMapPick();
            stops = normalized.length >= 2 ? normalized : [copyStop(normalized[0] || {}), copyStop({})];
            render();
            sync();
        }

        function setFromLegacy(value) {
            const labels = String(value || '').split(';').map((item) => item.trim()).filter(Boolean);
            if (labels.length === 0) return;
            setStops(labels.map((label) => ({ label })));
        }

        function loadDemo() {
            setStops([
                { label: 'Berlin, Deutschland', lat: 52.520008, lon: 13.404954 },
                { label: 'Dresden, Deutschland', lat: 51.050409, lon: 13.737262 },
                { label: 'Leipzig, Deutschland', lat: 51.339695, lon: 12.373075 },
                { label: 'Praha, Česko', lat: 50.075539, lon: 14.437800 },
            ]);
            document.getElementById('route-form')?.requestSubmit();
        }

        list.addEventListener('input', (event) => {
            const input = event.target.closest('[data-stop-input]');
            if (!input) return;
            const stop = stops.find((item) => item.id === input.dataset.stopInput);
            if (!stop) return;
            if (input.value !== stop.label) {
                stop.lat = null;
                stop.lon = null;
            }
            stop.label = input.value;
            sync();
        });

        list.addEventListener('click', (event) => {
            const button = event.target.closest('[data-action]');
            const row = event.target.closest('[data-stop-id]');
            if (!button || !row) return;
            const id = row.dataset.stopId;
            if (button.dataset.action === 'up') move(id, -1);
            if (button.dataset.action === 'down') move(id, 1);
            if (button.dataset.action === 'pick') activateMapPick(id);
            if (button.dataset.action === 'remove' && stops.length > 2) {
                if (mapTargetId === id) deactivateMapPick();
                stops = stops.filter((stop) => stop.id !== id);
                render();
                sync();
            }
        });

        list.addEventListener('dragstart', (event) => {
            const row = event.target.closest('[data-stop-id]');
            if (!row) return;
            draggingId = row.dataset.stopId;
            row.classList.add('dragging');
            event.dataTransfer.effectAllowed = 'move';
        });
        list.addEventListener('dragover', (event) => {
            if (!draggingId) return;
            event.preventDefault();
            const row = event.target.closest('[data-stop-id]');
            if (!row || row.dataset.stopId === draggingId) return;
            list.querySelectorAll('.drag-target').forEach((item) => item.classList.remove('drag-target'));
            row.classList.add('drag-target');
        });
        list.addEventListener('drop', (event) => {
            event.preventDefault();
            const row = event.target.closest('[data-stop-id]');
            if (!draggingId || !row || row.dataset.stopId === draggingId) return;
            const from = stops.findIndex((stop) => stop.id === draggingId);
            const to = stops.findIndex((stop) => stop.id === row.dataset.stopId);
            const moved = stops.splice(from, 1)[0];
            stops.splice(to, 0, moved);
            render();
            sync();
        });
        list.addEventListener('dragend', () => {
            draggingId = null;
            render();
            sync();
        });

        addButton?.addEventListener('click', () => addStop());
        reverseButton?.addEventListener('click', () => {
            stops.reverse();
            render();
            sync();
        });
        mapPickButton?.addEventListener('click', () => activateMapPick());
        demoButton?.addEventListener('click', loadDemo);

        render();

        return {
            getStops,
            serialize: () => JSON.stringify(getStops()),
            setStops,
            setFromLegacy,
            receiveMapPoint,
            cancelMapPick: deactivateMapPick,
            refreshLabels: () => render(),
            loadDemo,
            maxStops: MAX_STOPS,
        };
    }

    document.addEventListener('DOMContentLoaded', () => {
        window.routeEditor = createRouteEditor();
    });
})();
