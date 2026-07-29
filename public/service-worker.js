/**
 * Service worker для PWA.
 *
 * Кэширует "оболочку" приложения (HTML/CSS/JS/иконки), чтобы:
 * - сайт можно было установить на телефон как приложение (см. manifest.webmanifest);
 * - интерфейс открывался мгновенно и работал в офлайне (без карты и расчёта
 *   маршрута — они требуют сети, но форма и статика доступны сразу).
 *
 * Стратегия: "cache first, then network" для статики оболочки,
 * "network only" для API (/api/*) — расчёт маршрута никогда не должен
 * отдавать устаревший кэшированный ответ.
 */

const CACHE_VERSION = 'srp-shell-v3';

const SHELL_ASSETS = [
    './',
    './index.php',
    './assets/css/route.css',
    './assets/js/app.js',
    './assets/js/ui.js',
    './assets/js/i18n.js',
    './assets/js/ml_boundary.js',
    './assets/icons/logo-source.svg',
    './assets/icons/favicon.ico',
    './assets/icons/favicon-16.png',
    './assets/icons/favicon-32.png',
    './assets/icons/apple-touch-icon.png',
    './assets/icons/icon-192.png',
    './assets/icons/icon-512.png',
    './manifest.webmanifest',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_VERSION).then((cache) => cache.addAll(SHELL_ASSETS)).catch(() => {
            // Если один из внешних (например, CDN Leaflet) ресурсов недоступен на
            // этапе install — не роняем установку всего service worker'а из-за этого.
        })
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(
            keys.filter((key) => key !== CACHE_VERSION).map((key) => caches.delete(key))
        ))
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);

    // Запросы к нашему API и к сторонним сервисам (Nominatim/OSRM/тайлы карты)
    // всегда идут в сеть напрямую — кэшировать динамические данные маршрута
    // нельзя, они должны быть актуальными.
    if (url.pathname.includes('/api/') || url.origin !== self.location.origin) {
        return;
    }

    if (event.request.method !== 'GET') {
        return;
    }

    event.respondWith(
        caches.match(event.request).then((cached) => {
            const networkFetch = fetch(event.request)
                .then((response) => {
                    if (response && response.ok) {
                        const clone = response.clone();
                        caches.open(CACHE_VERSION).then((cache) => cache.put(event.request, clone));
                    }
                    return response;
                })
                .catch(() => cached);

            // Cache-first для мгновенной загрузки оболочки, но с фоновым
            // обновлением кэша из сети ("stale-while-revalidate").
            return cached || networkFetch;
        })
    );
});
