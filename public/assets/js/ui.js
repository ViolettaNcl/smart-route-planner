/**
 * UI-надстройка поверх основного app.js: не трогает бизнес-логику расчёта
 * маршрута, а только переключает визуальные вещи, добавленные в новой
 * desktop-раскладке — тему (светлая/тёмная) и вкладки в панели результата.
 *
 * Тема применяется к <html data-theme="dark|light">, сохраняется в
 * localStorage и сообщается карте (app.js меняет подложку Leaflet-тайлов
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
            if (typeof leafletMap !== 'undefined' && leafletMap && typeof leafletMap.invalidateSize === 'function') {
                leafletMap.invalidateSize();
            }
        });
    });
}

document.addEventListener('DOMContentLoaded', () => {
    initTheme();
    initResultTabs();
});
