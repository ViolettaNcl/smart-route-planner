/**
 * Service worker для PWA.
 *
 * Кэширует "оболочку" приложения (HTML/CSS/JS/иконки), чтобы:
 * - сайт можно было установить на телефон как приложение (см. manifest.webmanifest);
 * - интерфейс открывался мгновенно и работал в офлайне (без карты и расчёта
 *   маршрута — они требуют сети, но форма и статика доступны сразу).
 *
 * Стратегия: "network first" для HTML-навигации и
 * "cache first, then network" для версионированной статики оболочки,
 * "network only" для API (/api/*) — расчёт маршрута никогда не должен
 * отдавать устаревший кэшированный ответ.
 */

const CACHE_VERSION = 'srp-shell-v13';

const SHELL_ASSETS = [
    './',
    './assets/css/route.css?v=13',
    './assets/js/app.js?v=13',
    './assets/js/ui.js?v=13',
    './assets/js/i18n.js?v=13',
    './assets/js/route-editor.js?v=13',
    './assets/js/product.js?v=13',
    './assets/js/ml_boundary.js?v=13',
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
            // Если один из внешних (например, CDN MapLibre) ресурсов недоступен на
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

    // Запросы к нашему API и к сторонним сервисам (Nominatim/OSRM/векторные тайлы)
    // всегда идут в сеть напрямую — кэшировать динамические данные маршрута
    // нельзя, они должны быть актуальными.
    if (url.pathname.includes('/api/') || url.origin !== self.location.origin) {
        return;
    }

    if (event.request.method !== 'GET') {
        return;
    }

    // HTML всегда проверяем в сети первым. Так установленное PWA не удерживает
    // старый index.php после нового deployment; при офлайне остаётся shell.
    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request)
                .then((response) => {
                    if (response && response.ok) {
                        const clone = response.clone();
                        caches.open(CACHE_VERSION).then((cache) => cache.put(event.request, clone));
                    }
                    return response;
                })
                .catch(() => caches.match(event.request).then((cached) => cached || caches.match('./')))
        );
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
