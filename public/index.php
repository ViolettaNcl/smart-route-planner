<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Smart Route Planner</title>

    <!-- Применяем сохранённую тему ДО отрисовки CSS, чтобы не было "вспышки"
         тёмного/светлого интерфейса при перезагрузке страницы. -->
    <script>
        (function () {
            try {
                var saved = localStorage.getItem('srp_theme');
                var theme = saved === 'light' ? 'light' : 'dark';
                document.documentElement.setAttribute('data-theme', theme);
            } catch (e) {
                document.documentElement.setAttribute('data-theme', 'dark');
            }
        })();
    </script>

    <!-- PWA: манифест + мета-теги, чтобы сайт можно было установить на телефон -->
    <link rel="manifest" href="manifest.webmanifest">
    <meta name="theme-color" content="#0d1118">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="RoutePlanner">
    <link rel="apple-touch-icon" href="assets/icons/apple-touch-icon.png">
    <link rel="icon" href="assets/icons/logo-source.svg" type="image/svg+xml">
    <link rel="icon" href="assets/icons/favicon.ico" sizes="any">
    <link rel="icon" href="assets/icons/favicon-32.png" type="image/png" sizes="32x32">
    <link rel="icon" href="assets/icons/favicon-16.png" type="image/png" sizes="16x16">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://tiles.openfreemap.org" crossorigin>
    <link rel="preconnect" href="https://tiles.mapterhorn.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@500;600;700&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="assets/css/route.css?v=9">
    <link rel="stylesheet" href="https://unpkg.com/maplibre-gl@5.24.0/dist/maplibre-gl.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
</head>
<body>

<div class="container">
    <header class="top-bar">
        <div class="brand-lockup">
            <span class="logo-mark" aria-hidden="true">
                <svg viewBox="0 0 40 40" width="34" height="34" role="img">
                    <defs>
                        <linearGradient id="logoGrad" x1="2" y1="2" x2="38" y2="38" gradientUnits="userSpaceOnUse">
                            <stop offset="0%" stop-color="#c9b8ff" />
                            <stop offset="55%" stop-color="#8b5cf6" />
                            <stop offset="100%" stop-color="#6425c9" />
                        </linearGradient>
                    </defs>
                    <rect x="1.5" y="1.5" width="37" height="37" rx="11" fill="url(#logoGrad)" />
                    <rect x="1.5" y="1.5" width="37" height="37" rx="11" fill="none" stroke="rgba(255,255,255,0.25)" stroke-width="1" />
                    <path d="M8.5 28.5 C 13 20, 15 26, 19.5 19 S 27 11, 31.5 12.5"
                        fill="none" stroke="rgba(255,255,255,0.92)" stroke-width="2.4"
                        stroke-linecap="round" stroke-dasharray="0.4 5.6" />
                    <circle cx="8.5" cy="28.5" r="2.6" fill="#ffffff" />
                    <circle cx="31.5" cy="12.5" r="3.6" fill="#ffffff" />
                    <circle class="logo-pulse-ring" cx="31.5" cy="12.5" r="3.6" fill="none" stroke="#ffffff" stroke-width="1.3" opacity="0.55" />
                </svg>
            </span>
            <div class="brand-copy">
                <span class="brand-eyebrow" data-i18n="brandEyebrow">Route intelligence</span>
                <h1 data-i18n="heading">Smart Route Planner</h1>
            </div>
        </div>
        <div class="top-bar-controls">
            <div class="system-status" data-i18n-title="mapKeylessStatusHint">
                <span class="system-status-dot" aria-hidden="true"></span>
                <span data-i18n="mapKeylessStatus">Карта без API-ключа</span>
            </div>
            <button type="button" id="theme-toggle" class="theme-toggle" aria-label="Theme switch">
                <span class="theme-toggle-icon">🌙</span>
            </button>
            <div class="lang-switch" role="group" aria-label="Language switch">
                <button type="button" data-lang="ru">RU</button>
                <button type="button" data-lang="en">EN</button>
            </div>
        </div>
    </header>

    <div class="layout">
        <aside class="panel" aria-labelledby="route-form-title">
            <div class="panel-heading">
                <span class="section-kicker" data-i18n="routeSetupKicker">01 · Маршрут</span>
                <h2 id="route-form-title" data-i18n="routeSetupTitle">Куда отправимся?</h2>
                <p data-i18n="routeSetupSubtitle">Добавьте минимум две точки — порядок будет оптимизирован автоматически.</p>
            </div>
            <form id="route-form">
                <label for="points" class="field-label">
                    <span data-i18n="pointsLabel">Введите точки через «;»</span>
                    <span class="required-mark" aria-hidden="true">*</span>
                </label>
                <div class="autocomplete-wrap">
                    <textarea id="points" name="points" autocomplete="off"
                        aria-describedby="route-input-hint route-point-count"
                        data-i18n-placeholder="pointsPlaceholder"
                        placeholder="Например: Волгоград, Россия;Ростов-на-Дону, Россия;Воронеж, Россия;Москва, Россия"></textarea>
                    <ul id="suggestions" class="suggestions hidden"></ul>
                </div>
                <div class="route-input-meta">
                    <span id="route-input-hint" data-i18n="routeInputHint">Разделяйте города точкой с запятой</span>
                    <span id="route-point-count" aria-live="polite">0 точек</span>
                </div>

                <details class="cost-settings">
                    <summary data-i18n="costSettingsSummary">⚙️ Настройки стоимости поездки</summary>
                    <div class="cost-settings-grid">
                        <label>
                            <span data-i18n="fuelPriceLabel">Цена топлива, ₽/л</span>
                            <input type="number" id="cost-fuel-price" min="1" step="0.1" value="60">
                        </label>
                        <label>
                            <span data-i18n="fuelConsumptionLabel">Расход, л/100км</span>
                            <input type="number" id="cost-fuel-consumption" min="1" step="0.1" value="8">
                        </label>
                        <label>
                            <span data-i18n="ticketPriceLabel">Цена билета, ₽/км</span>
                            <input type="number" id="cost-ticket-price" min="0.1" step="0.1" value="3">
                        </label>
                    </div>
                </details>

                <button type="submit" id="submit-button" data-i18n="submitIdle">Рассчитать маршрут</button>
            </form>

            <div id="error-banner" class="error-banner hidden" role="alert" aria-live="assertive"></div>
            <div id="warning-banner" class="warning-banner hidden" role="status" aria-live="polite"></div>

            <div class="panel-highlights">
                <div class="panel-highlights-title" data-i18n="highlightsTitle">Как это работает</div>
                <div class="highlight-item">
                    <span class="highlight-icon">🛣️</span>
                    <div class="highlight-text">
                        <strong data-i18n="highlightRoadsTitle">Реальные дороги</strong>
                        <span data-i18n="highlightRoadsText">Дистанция и время — по дорожной сети (OSRM), а не по прямой</span>
                    </div>
                </div>
                <div class="highlight-item">
                    <span class="highlight-icon">🧠</span>
                    <div class="highlight-text">
                        <strong data-i18n="highlightAiTitle">Нейросеть учится на лету</strong>
                        <span data-i18n="highlightAiText">Предсказывает транспорт и подстраивается под ваши правки</span>
                    </div>
                </div>
                <div class="highlight-item">
                    <span class="highlight-icon">💰</span>
                    <div class="highlight-text">
                        <strong data-i18n="highlightCostTitle">Стоимость и CO2</strong>
                        <span data-i18n="highlightCostText">Расход топлива или билет плюс экологический след поездки</span>
                    </div>
                </div>
            </div>
        </aside>

        <section class="map-panel" aria-labelledby="map-canvas-title">
            <div class="map-identity">
                <span class="map-identity-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="18" height="18">
                        <path d="M3 7.5 8.2 4l7.1 3.6L21 4.5v12L15.3 20l-7.1-3.6L3 19.5z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>
                        <path d="M8.2 4v12.4m7.1-8.8V20" fill="none" stroke="currentColor" stroke-width="1.4" opacity=".7"/>
                    </svg>
                </span>
                <span class="map-identity-copy">
                    <strong id="map-canvas-title" data-i18n="mapCanvasTitle">Навигационная сцена</strong>
                    <small data-i18n="mapCanvasSubtitle">Fiord / Liberty · OpenStreetMap · без ключа</small>
                </span>
            </div>
            <div id="map-mode-control" class="map-mode-control hidden" role="group" data-i18n-aria-label="mapModeLabel">
                <button type="button" class="map-mode-btn" data-map-mode="2d" aria-pressed="false">
                    <span class="map-mode-icon map-mode-icon-2d" aria-hidden="true"></span>
                    <span>2D</span>
                </button>
                <button type="button" class="map-mode-btn" data-map-mode="3d" aria-pressed="true">
                    <span class="map-mode-icon map-mode-icon-3d" aria-hidden="true"></span>
                    <span>3D</span>
                </button>
            </div>

            <div id="map-scene-status" class="map-scene-status hidden" role="status" aria-live="polite">
                <span class="map-scene-pulse" aria-hidden="true"></span>
                <span id="map-scene-status-text"></span>
            </div>

            <div id="map-style-indicator" class="map-style-chip hidden" aria-hidden="true">
                <span class="map-style-chip-dot"></span>
                <span id="map-style-chip">FIORD · фирменный тёмный</span>
            </div>

            <div id="map-trip-summary" class="map-trip-summary hidden" aria-live="polite">
                <div class="map-summary-metric">
                    <span data-i18n="mapSummaryDistance">Дистанция</span>
                    <strong id="map-summary-distance">—</strong>
                </div>
                <div class="map-summary-metric">
                    <span data-i18n="mapSummaryTime">В пути</span>
                    <strong id="map-summary-time">—</strong>
                </div>
                <div class="map-summary-metric">
                    <span data-i18n="mapSummaryMode">Режим</span>
                    <strong id="map-summary-mode">—</strong>
                </div>
                <small id="map-summary-source"></small>
            </div>

            <div id="map-placeholder" class="map-placeholder">
                <div class="map-placeholder-card">
                    <div class="map-placeholder-visual" aria-hidden="true">
                        <svg viewBox="0 0 220 96">
                            <defs>
                                <linearGradient id="previewRouteGradient" x1="0" y1="0" x2="1" y2="0">
                                    <stop offset="0" stop-color="#9d7bff"/>
                                    <stop offset=".5" stop-color="#48dbe3"/>
                                    <stop offset="1" stop-color="#ffb547"/>
                                </linearGradient>
                                <filter id="previewRouteGlow" x="-30%" y="-80%" width="160%" height="260%">
                                    <feGaussianBlur stdDeviation="4"/>
                                </filter>
                            </defs>
                            <path class="preview-network" d="M8 72 42 46 74 58 112 24 150 42 210 13M14 21l40 20 42-18 37 50 72-30M28 90l48-36 52 30 41-37"/>
                            <path class="preview-route-glow" d="M18 74C48 29 79 72 109 43s56-19 92-28" filter="url(#previewRouteGlow)"/>
                            <path class="preview-route" d="M18 74C48 29 79 72 109 43s56-19 92-28"/>
                            <circle class="preview-pin preview-pin-start" cx="18" cy="74" r="6"/>
                            <circle class="preview-pin preview-pin-end" cx="201" cy="15" r="7"/>
                        </svg>
                    </div>
                    <span class="section-kicker" data-i18n="mapPlaceholderKicker">Интерактивная карта</span>
                    <strong data-i18n="mapPlaceholderTitle">Маршрут оживёт здесь</strong>
                    <p data-i18n="mapPlaceholder">Введите города и нажмите «Рассчитать маршрут» — здесь появится карта с оптимизированным маршрутом.</p>
                    <div class="map-feature-pills" aria-hidden="true">
                        <span data-i18n="mapPill2d">Точная 2D</span>
                        <span data-i18n="mapPill3d">Рельефная 3D</span>
                        <span data-i18n="mapPillKeyless">Без API-ключа</span>
                    </div>
                </div>
            </div>
            <div id="map" class="hidden" role="region" data-i18n-aria-label="mapAriaLabel"></div>
        </section>
    </div>

    <!-- Результат расчёта: полноширинная приборная панель под строкой
         форма+карта — вместо одной длинной колонки слева, статистика идёт
         компактной лентой, а остальное разложено по вкладкам. -->
    <div id="result-section" class="hidden">
        <div class="result-header">
            <div>
                <span class="section-kicker" data-i18n="routeBriefKicker">02 · Сводка</span>
                <h2 data-i18n="routeBriefTitle">Маршрут одним взглядом</h2>
            </div>
            <span class="route-ready-status"><span aria-hidden="true"></span><span data-i18n="routeReady">Маршрут готов</span></span>
        </div>
        <div class="result">
            <div class="card">
                <span data-i18n="statStops">📍 Точек</span>
                <strong id="stat-stops"></strong>
            </div>
            <div class="card">
                <span data-i18n="statDistance">📏 Дистанция (оптимизированная)</span>
                <strong id="stat-distance"></strong>
            </div>
            <div class="card">
                <span data-i18n="statTransport">🚘 Транспорт</span>
                <strong id="stat-transport"></strong>
                <div class="confidence-bar"><div id="confidence-fill" class="confidence-bar-fill"></div></div>
            </div>
            <div class="card">
                <span data-i18n="statTime">⏱ Время в пути</span>
                <strong id="stat-time"></strong>
            </div>
            <div class="card cost-card">
                <span data-i18n="statCost">💰 Стоимость поездки</span>
                <strong id="stat-cost"></strong>
            </div>
            <div class="card">
                <span data-i18n="statEmissions">🌱 CO2 выбросы</span>
                <strong id="stat-emissions"></strong>
            </div>
        </div>

        <div class="result-notes">
            <p id="emissions-compare" class="emissions-compare"></p>
            <p id="routing-source-note" class="routing-source-note"></p>
            <p id="confidence-label" class="confidence-label-text"></p>
            <p id="cost-note" class="cost-note-text"></p>
        </div>

        <div class="tabs">
            <div class="tab-nav" role="tablist">
                <button type="button" id="tab-cities" class="tab-nav-btn active" role="tab" aria-controls="panel-cities" aria-selected="true" tabindex="0" data-tab="cities" data-i18n="tabCities">📍 Города</button>
                <button type="button" id="tab-model" class="tab-nav-btn" role="tab" aria-controls="panel-model" aria-selected="false" tabindex="-1" data-tab="model" data-i18n="tabModel">🧠 Модель</button>
                <button type="button" id="tab-assistant" class="tab-nav-btn" role="tab" aria-controls="panel-assistant" aria-selected="false" tabindex="-1" data-tab="assistant" data-i18n="tabAssistant">🤖 AI-совет</button>
                <button type="button" id="tab-extras" class="tab-nav-btn" role="tab" aria-controls="panel-extras" aria-selected="false" tabindex="-1" data-tab="extras" data-i18n="tabExtras">🧰 Доп.</button>
                <button type="button" id="tab-share" class="tab-nav-btn" role="tab" aria-controls="panel-share" aria-selected="false" tabindex="-1" data-tab="share" data-i18n="tabShare">🔗 Поделиться</button>
            </div>

            <div id="panel-cities" class="tab-panel" role="tabpanel" aria-labelledby="tab-cities" tabindex="0" data-tab-panel="cities">
                <ul id="points-list" class="points-list"></ul>
            </div>

            <div id="panel-model" class="tab-panel hidden" role="tabpanel" aria-labelledby="tab-model" tabindex="0" data-tab-panel="model">
                <div class="ml-inline-controls">
                    <button type="button" id="explain-toggle" class="btn-link">🔍 Почему такой транспорт?</button>

                    <div class="learn-correction">
                        <span data-i18n="learnPrompt">Модель ошиблась?</span>
                        <button type="button" class="learn-btn" data-label="walk" data-i18n="learnButtonWalk">🚶 пешком</button>
                        <button type="button" class="learn-btn" data-label="car" data-i18n="learnButtonCar">🚗 авто</button>
                        <button type="button" class="learn-btn" data-label="bus" data-i18n="learnButtonBus">🚌 автобус</button>
                    </div>

                    <div class="ab-feedback">
                        <span id="ab-feedback-prompt"></span>
                        <button type="button" id="ab-feedback-yes" class="learn-btn">👍</button>
                        <button type="button" id="ab-feedback-no" class="learn-btn">👎</button>
                    </div>
                </div>
                <div id="learn-toast" class="learn-toast hidden"></div>
                <div id="ab-feedback-toast" class="learn-toast hidden"></div>

                <div id="explain-panel" class="explain-panel hidden">
                    <p id="explain-intro" class="explain-intro"></p>
                    <div id="explain-bars" class="explain-bars"></div>
                </div>
            </div>

            <div id="panel-assistant" class="tab-panel hidden" role="tabpanel" aria-labelledby="tab-assistant" tabindex="0" data-tab-panel="assistant">
                <div id="assistant-card" class="assistant-card hidden">
                    <h3 data-i18n="assistantTitle">🤖 AI-совет по поездке</h3>
                    <p id="assistant-text" class="assistant-text"></p>
                    <span id="assistant-source" class="assistant-source"></span>
                </div>
            </div>

            <div id="panel-extras" class="tab-panel hidden" role="tabpanel" aria-labelledby="tab-extras" tabindex="0" data-tab-panel="extras">
                <button type="button" id="poi-button" class="btn secondary poi-btn">
                    📍 Показать точки интереса (АЗС/кафе/отели)
                </button>
                <p id="poi-empty-note" class="poi-empty-note hidden" data-i18n="poiEmpty"></p>

                <div class="day-plan-section">
                    <button type="button" id="day-plan-button" class="btn secondary day-plan-btn" data-i18n="dayPlanButton">
                        📅 Разбить маршрут по дням (K-Means)
                    </button>
                    <div id="day-plan-controls" class="day-plan-controls hidden">
                        <label>
                            <span data-i18n="dayPlanDaysLabel">Число дней:</span>
                            <input type="number" id="day-plan-days" min="1" max="14" value="1">
                        </label>
                        <button type="button" id="day-plan-apply" class="btn-link" data-i18n="dayPlanApply">Пересчитать</button>
                    </div>
                    <p class="day-plan-intro hidden" id="day-plan-intro" data-i18n="dayPlanIntro"></p>
                    <ol id="day-plan-list" class="day-plan-list"></ol>
                </div>
            </div>

            <div id="panel-share" class="tab-panel hidden" role="tabpanel" aria-labelledby="tab-share" tabindex="0" data-tab-panel="share">
                <div class="links">
                    <a id="link-google" class="btn" href="#" target="_blank" data-i18n="linkGoogle">Google Maps</a>
                    <a id="link-yandex" class="btn secondary" href="#" target="_blank" data-i18n="linkYandex">Yandex Maps</a>
                </div>
                <button type="button" id="share-button" class="btn share-btn" data-i18n="shareButton">🔗 Скопировать ссылку на маршрут</button>
                <div id="share-toast" class="share-toast hidden"></div>
            </div>
        </div>
    </div>

    <div id="boundary-section" class="boundary-section">
        <button type="button" id="boundary-toggle" class="btn secondary">
            Показать карту решений модели
        </button>
        <div id="boundary-panel" class="boundary-panel hidden">
            <h3 data-i18n="boundaryTitle">🧠 Как модель делит маршруты на walk / car / bus</h3>
            <p class="boundary-intro" data-i18n="boundaryIntro"></p>
            <div class="boundary-controls">
                <label>
                    <span data-i18n="boundaryModelLabel">Модель:</span>
                    <select id="boundary-model-select">
                        <option value="mlp" data-i18n="boundaryModelMlp">нейросеть (MLP)</option>
                        <option value="softmax" data-i18n="boundaryModelSoftmax">линейная (softmax)</option>
                    </select>
                </label>
            </div>
            <div class="boundary-chart-wrap">
                <canvas id="boundary-chart" height="320"></canvas>
            </div>
            <button type="button" id="reset-model-button" class="btn-link reset-model-btn" data-i18n="resetModelButton">↩️ Сбросить модель к обученному состоянию</button>
            <div id="reset-model-toast" class="learn-toast hidden"></div>

            <div class="ab-stats-panel">
                <h4 data-i18n="abStatsTitle">📊 A/B-тест: MLP vs Softmax (реальные отзывы посетителей)</h4>
                <div id="ab-stats-content" class="ab-stats-content"></div>
            </div>
        </div>
    </div>

    <button type="button" id="install-button" class="install-btn hidden" data-i18n="installApp">⬇️ Установить приложение</button>
</div>

<script src="https://unpkg.com/maplibre-gl@5.24.0/dist/maplibre-gl.js"></script>
<script src="assets/js/i18n.js?v=9"></script>
<script src="assets/js/ml_boundary.js?v=9"></script>
<script src="assets/js/app.js?v=9"></script>
<script src="assets/js/ui.js?v=9"></script>
</body>
</html>
