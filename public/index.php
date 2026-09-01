<?php
require_once dirname(__DIR__) . '/bootstrap.php';

$publicUrl = \App\Support\PublicUrl::resolve();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Smart Route Planner — умный редактор маршрутов</title>
    <meta name="description" content="Планировщик поездок с реальными дорожными маршрутами, альтернативами, пошаговой навигацией, оптимизацией остановок, стоимостью и CO₂.">
    <meta name="robots" content="index,follow,max-image-preview:large">
    <link rel="canonical" href="<?= htmlspecialchars($publicUrl, ENT_QUOTES, 'UTF-8') ?>/">
    <meta property="og:type" content="website">
    <meta property="og:title" content="Smart Route Planner">
    <meta property="og:description" content="Соберите маршрут, сравните реальные альтернативы и получите навигационные инструкции.">
    <meta property="og:url" content="<?= htmlspecialchars($publicUrl, ENT_QUOTES, 'UTF-8') ?>/">
    <meta property="og:image" content="<?= htmlspecialchars($publicUrl, ENT_QUOTES, 'UTF-8') ?>/assets/icons/icon-512.png">
    <meta name="twitter:card" content="summary">
    <script type="application/ld+json"><?= json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'WebApplication',
        'name' => 'Smart Route Planner',
        'url' => $publicUrl . '/',
        'applicationCategory' => 'TravelApplication',
        'operatingSystem' => 'Any',
        'description' => 'Route editor with road alternatives, navigation instructions and trip estimates.',
        'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'RUB'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>

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

    <link rel="stylesheet" href="assets/css/route.css?v=14">
    <link rel="stylesheet" href="https://unpkg.com/maplibre-gl@5.24.0/dist/maplibre-gl.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/hammerjs@2.0.8/hammer.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-zoom@2.2.0/dist/chartjs-plugin-zoom.min.js"></script>
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
        <aside class="panel sheet-peek" aria-labelledby="route-form-title" data-sheet-state="peek">
            <button type="button" id="panel-sheet-handle" class="panel-sheet-handle" aria-expanded="false" aria-controls="route-editor-sheet-content">
                <span class="sheet-grabber" aria-hidden="true"></span>
                <span data-i18n="mobileRouteSheet">Редактор маршрута</span>
                <span class="sheet-state-icon" aria-hidden="true">
                    <svg viewBox="0 0 20 20" focusable="false">
                        <path d="m5 12.5 5-5 5 5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </span>
            </button>
            <div id="route-editor-sheet-content" class="panel-sheet-content">
            <div class="panel-heading">
                <span class="section-kicker" data-i18n="routeSetupKicker">01 · Маршрут</span>
                <h2 id="route-form-title" data-i18n="routeSetupTitle">Куда отправимся?</h2>
                <p data-i18n="routeSetupSubtitle">Добавьте минимум две точки — порядок будет оптимизирован автоматически.</p>
            </div>
            <form id="route-form">
                <label id="route-stops-label" class="field-label">
                    <span data-i18n="routeEditorLabel">Точки маршрута</span>
                    <span class="required-mark" aria-hidden="true">*</span>
                </label>
                <div id="route-stop-list" class="route-stop-list" role="list" aria-labelledby="route-stops-label"></div>
                <div class="route-editor-toolbar" role="group" data-i18n-aria-label="routeEditorTools">
                    <button type="button" id="add-stop-button" class="editor-tool" data-i18n="addStop">＋ Точка</button>
                    <button type="button" id="reverse-route-button" class="editor-tool" data-i18n="reverseRoute">⇅ Развернуть</button>
                    <button type="button" id="map-pick-button" class="editor-tool" aria-pressed="false" data-i18n="pickOnMap">⌖ На карте</button>
                    <button type="button" id="demo-route-button" class="editor-tool editor-tool-accent" data-i18n="demoRoute">▶ Демо</button>
                </div>
                <input type="hidden" id="points" name="points" value="">
                <input type="hidden" id="stops-json" name="stops_json" value="[]">
                <div class="route-input-meta">
                    <span id="route-input-hint" data-i18n="routeEditorHint">Старт и финиш фиксированы; промежуточные точки можно менять местами</span>
                    <span id="route-point-count" aria-live="polite">0 точек</span>
                </div>

                <label class="optimize-toggle" for="optimize-order">
                    <input type="checkbox" id="optimize-order" checked>
                    <span><strong data-i18n="optimizeOrderTitle">Оптимизировать промежуточные</strong><small data-i18n="optimizeOrderHint">Старт и финиш сохранятся</small></span>
                </label>

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
                        <strong data-i18n="highlightAiTitle">Объяснимая ML-рекомендация</strong>
                        <span data-i18n="highlightAiText">Сравнивает модели, показывает причины и безопасно собирает исправления</span>
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
            </div>
        </aside>

        <section class="map-panel" aria-labelledby="map-canvas-title">
            <button type="button" id="map-focus-toggle" class="map-focus-toggle" data-i18n="focusMap">⛶ Карта</button>
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

            <div id="map-pick-hint" class="map-pick-hint hidden" role="status" aria-live="polite">
                <span aria-hidden="true">⌖</span>
                <span data-i18n="mapPickHint">Нажмите на нужное место</span>
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

        <section id="route-options-section" class="route-options-section" aria-labelledby="route-options-title">
            <div class="section-heading-inline">
                <div>
                    <span class="section-kicker" data-i18n="routeOptionsKicker">Варианты OSRM</span>
                    <h3 id="route-options-title" data-i18n="routeOptionsTitle">Сравните реальные маршруты</h3>
                </div>
                <small id="route-options-note" data-i18n="routeOptionsHint">Показаны только варианты, возвращённые дорожным сервисом</small>
            </div>
            <div id="route-options" class="route-options" role="radiogroup" data-i18n-aria-label="routeOptionsLabel"></div>
        </section>

        <div class="tabs">
            <div class="tab-nav" role="tablist">
                <button type="button" id="tab-cities" class="tab-nav-btn active" role="tab" aria-controls="panel-cities" aria-selected="true" tabindex="0" data-tab="cities" data-i18n="tabCities">📍 Города</button>
                <button type="button" id="tab-navigation" class="tab-nav-btn" role="tab" aria-controls="panel-navigation" aria-selected="false" tabindex="-1" data-tab="navigation" data-i18n="tabNavigation">🧭 Навигация</button>
                <button type="button" id="tab-model" class="tab-nav-btn" role="tab" aria-controls="panel-model" aria-selected="false" tabindex="-1" data-tab="model" data-i18n="tabModel">🧠 Модель</button>
                <button type="button" id="tab-assistant" class="tab-nav-btn" role="tab" aria-controls="panel-assistant" aria-selected="false" tabindex="-1" data-tab="assistant" data-i18n="tabAssistant">🤖 AI-совет</button>
                <button type="button" id="tab-extras" class="tab-nav-btn" role="tab" aria-controls="panel-extras" aria-selected="false" tabindex="-1" data-tab="extras" data-i18n="tabExtras">🧰 Доп.</button>
                <button type="button" id="tab-share" class="tab-nav-btn" role="tab" aria-controls="panel-share" aria-selected="false" tabindex="-1" data-tab="share" data-i18n="tabShare">🔗 Поделиться</button>
            </div>

            <div id="panel-cities" class="tab-panel" role="tabpanel" aria-labelledby="tab-cities" tabindex="0" data-tab-panel="cities">
                <ul id="points-list" class="points-list"></ul>
            </div>

            <div id="panel-navigation" class="tab-panel hidden" role="tabpanel" aria-labelledby="tab-navigation" tabindex="0" data-tab-panel="navigation">
                <div class="navigation-head">
                    <div>
                        <h3 data-i18n="navigationTitle">Пошаговые инструкции</h3>
                        <p id="navigation-source" data-i18n="navigationHint">Манёвры и расстояния получены из выбранного дорожного маршрута.</p>
                    </div>
                    <button type="button" id="print-route-button" class="btn secondary compact-btn" data-i18n="printRoute">Печать</button>
                </div>
                <ol id="navigation-steps" class="navigation-steps"></ol>
                <p id="navigation-empty" class="navigation-empty hidden" data-i18n="navigationEmpty">Для резервного прямого маршрута пошаговая навигация недоступна.</p>
            </div>

            <div id="panel-model" class="tab-panel hidden" role="tabpanel" aria-labelledby="tab-model" tabindex="0" data-tab-panel="model">
                <section class="prediction-explainer" aria-labelledby="ml-prediction-title">
                    <div class="prediction-explainer-head">
                        <div>
                            <span class="section-kicker" data-i18n="modelExplanationKicker">Объяснимая рекомендация</span>
                            <h3 id="ml-prediction-title" data-i18n="modelExplanationTitle">Почему модель выбрала этот транспорт?</h3>
                        </div>
                        <div class="model-meta-chips">
                            <span id="ml-model-version" class="model-meta-chip">MLP</span>
                            <span id="ml-certainty-chip" class="model-meta-chip model-meta-chip-accent">—</span>
                        </div>
                    </div>

                    <div id="ml-insight-loading" class="ml-insight-loading" role="status" data-i18n="modelInsightWaiting">
                        Рассчитайте маршрут, чтобы увидеть персональное объяснение.
                    </div>

                    <div id="ml-insight-content" class="hidden">
                        <div class="prediction-hero">
                            <div>
                                <span data-i18n="modelRecommendationLabel">Рекомендация модели</span>
                                <strong id="ml-prediction-mode">—</strong>
                                <small id="ml-prediction-disclaimer" data-i18n="modelProbabilityDisclaimer">Расчётная вероятность, не гарантия.</small>
                            </div>
                            <div class="prediction-score" aria-label="Model score">
                                <strong id="ml-prediction-score">—</strong>
                                <span data-i18n="modelScoreLabel">оценка модели</span>
                            </div>
                        </div>

                        <div id="ml-probability-bars" class="probability-stack" aria-label="Class probabilities"></div>

                        <div class="model-input-strip">
                            <span><small data-i18n="modelInputDistance">Дистанция</small><strong id="ml-input-distance">—</strong></span>
                            <span><small data-i18n="modelInputStops">Остановки</small><strong id="ml-input-stops">—</strong></span>
                            <span><small data-i18n="modelInputMargin">Отрыв от второго места</small><strong id="ml-input-margin">—</strong></span>
                            <span><small data-i18n="modelInputBoundary">Ближайшая граница</small><strong id="ml-input-boundary">—</strong></span>
                        </div>

                        <div class="model-explanation-grid">
                            <section aria-labelledby="ml-influence-title">
                                <h4 id="ml-influence-title" data-i18n="modelInfluenceTitle">Что повлияло</h4>
                                <div id="ml-feature-influence" class="feature-influence-list"></div>
                            </section>
                            <section aria-labelledby="ml-counterfactual-title">
                                <h4 id="ml-counterfactual-title" data-i18n="modelCounterfactualTitle">Что изменит решение</h4>
                                <div id="ml-counterfactuals" class="counterfactual-list"></div>
                            </section>
                        </div>

                        <section class="transport-ranking" aria-labelledby="ml-ranking-title">
                            <div class="section-heading-inline compact-heading">
                                <div>
                                    <h4 id="ml-ranking-title" data-i18n="modelRankingTitle">Рейтинг транспорта</h4>
                                    <small data-i18n="modelRankingHint">Вероятность модели + время + стоимость + CO₂</small>
                                </div>
                            </div>
                            <div id="ml-ranking-list" class="transport-ranking-list"></div>
                        </section>

                        <div class="model-feedback-panel">
                            <div>
                                <strong data-i18n="learnPrompt">Модель ошиблась?</strong>
                                <small data-i18n="feedbackQueueHint">Исправление попадёт в обезличенную очередь и не изменит общие веса сразу.</small>
                            </div>
                            <div class="model-feedback-actions" role="group" data-i18n-aria-label="feedbackCorrectModeLabel">
                                <button type="button" class="model-correction-btn learn-btn" data-label="walk" data-i18n="learnButtonWalk">🚶 пешком</button>
                                <button type="button" class="model-correction-btn learn-btn" data-label="car" data-i18n="learnButtonCar">🚗 авто</button>
                                <button type="button" class="model-correction-btn learn-btn" data-label="bus" data-i18n="learnButtonBus">🚌 автобус</button>
                            </div>
                        </div>
                        <div id="learn-toast" class="learn-toast hidden" role="status"></div>

                        <div class="ab-feedback">
                            <span id="ab-feedback-prompt"></span>
                            <button type="button" id="ab-feedback-yes" class="learn-btn" aria-label="Correct">👍</button>
                            <button type="button" id="ab-feedback-no" class="learn-btn" aria-label="Incorrect">👎</button>
                        </div>
                        <div id="ab-feedback-toast" class="learn-toast hidden" role="status"></div>

                        <button type="button" id="open-ml-lab" class="btn secondary compact-btn" data-i18n="openMlLab">Открыть полную ML Lab</button>
                    </div>
                </section>
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
                    <a id="link-google" class="btn" href="#" target="_blank" rel="noopener noreferrer" data-i18n="linkGoogle">Google Maps</a>
                    <a id="link-yandex" class="btn secondary" href="#" target="_blank" rel="noopener noreferrer" data-i18n="linkYandex">Yandex Maps</a>
                </div>
                <button type="button" id="share-button" class="btn share-btn" data-i18n="shareButton">🔗 Скопировать ссылку на маршрут</button>
                <div class="route-actions-grid">
                    <button type="button" id="favorite-route-button" class="btn secondary" data-i18n="favoriteRoute">☆ В избранное</button>
                    <button type="button" id="export-geojson-button" class="btn secondary">GeoJSON</button>
                    <button type="button" id="export-gpx-button" class="btn secondary">GPX</button>
                    <button type="button" id="export-kml-button" class="btn secondary">KML</button>
                </div>
                <section class="saved-routes" aria-labelledby="saved-routes-title">
                    <div class="saved-routes-heading">
                        <h3 id="saved-routes-title" data-i18n="savedRoutes">Недавние и избранные</h3>
                        <button type="button" id="clear-history-button" class="btn-link" data-i18n="clearHistory">Очистить историю</button>
                    </div>
                    <div id="saved-routes-list" class="saved-routes-list"></div>
                </section>
                <div id="share-toast" class="share-toast hidden"></div>
            </div>
        </div>
    </div>

    <section id="boundary-section" class="boundary-section" aria-labelledby="ml-lab-title">
        <div class="ml-lab-heading">
            <div>
                <span class="section-kicker">ML LAB 2.0</span>
                <h2 id="ml-lab-title" data-i18n="mlLabTitle">Лаборатория решений модели</h2>
                <p data-i18n="mlLabDescription">Исследуйте границы, сравните модели и проверьте качество на независимой выборке.</p>
            </div>
            <span class="ml-readonly-badge" data-i18n="mlReadOnlyBadge">read-only · production safe</span>
        </div>
        <button type="button" id="boundary-toggle" class="btn secondary" aria-controls="boundary-panel" aria-expanded="false">
            Показать ML Lab
        </button>

        <div id="boundary-panel" class="boundary-panel hidden">
            <div class="ml-lab-tabs" role="tablist" data-i18n-aria-label="mlLabSectionsLabel" aria-label="Разделы ML Lab">
                <button type="button" id="ml-tab-boundary" class="ml-lab-tab active" role="tab" aria-selected="true" aria-controls="ml-view-boundary" tabindex="0" data-ml-view="boundary" data-i18n="mlTabBoundary">Карта решений</button>
                <button type="button" id="ml-tab-compare" class="ml-lab-tab" role="tab" aria-selected="false" aria-controls="ml-view-compare" tabindex="-1" data-ml-view="compare" data-i18n="mlTabCompare">Сравнение</button>
                <button type="button" id="ml-tab-quality" class="ml-lab-tab" role="tab" aria-selected="false" aria-controls="ml-view-quality" tabindex="-1" data-ml-view="quality" data-i18n="mlTabQuality">Качество</button>
                <button type="button" id="ml-tab-network" class="ml-lab-tab" role="tab" aria-selected="false" aria-controls="ml-view-network" tabindex="-1" data-ml-view="network" data-i18n="mlTabNetwork">Нейросеть</button>
                <button type="button" id="ml-tab-training" class="ml-lab-tab" role="tab" aria-selected="false" aria-controls="ml-view-training" tabindex="-1" data-ml-view="training" data-i18n="mlTabTraining">Обучение</button>
                <button type="button" id="ml-tab-data" class="ml-lab-tab" role="tab" aria-selected="false" aria-controls="ml-view-data" tabindex="-1" data-ml-view="data" data-i18n="mlTabData">Данные</button>
                <button type="button" id="ml-tab-card" class="ml-lab-tab" role="tab" aria-selected="false" aria-controls="ml-view-card" tabindex="-1" data-ml-view="card" data-i18n="mlTabCard">Model Card</button>
            </div>

            <section id="ml-view-boundary" class="ml-lab-view" role="tabpanel" aria-labelledby="ml-tab-boundary" tabindex="0">
                <div class="boundary-view-header">
                    <div>
                        <h3 data-i18n="boundaryTitle">Как модель делит маршруты на walk / car / bus</h3>
                        <p class="boundary-intro" data-i18n="boundaryIntro"></p>
                    </div>
                    <div class="boundary-controls">
                        <label>
                            <span data-i18n="boundaryModelLabel">Модель:</span>
                            <select id="boundary-model-select">
                                <option value="mlp" data-i18n="boundaryModelMlp">нейросеть (MLP)</option>
                                <option value="softmax" data-i18n="boundaryModelSoftmax">линейная (softmax)</option>
                            </select>
                        </label>
                        <label class="boundary-check"><input type="checkbox" id="boundary-show-regions" checked> <span data-i18n="boundaryShowRegions">Области</span></label>
                        <label class="boundary-check"><input type="checkbox" id="boundary-show-samples" checked> <span data-i18n="boundaryShowSamples">Примеры</span></label>
                        <label class="boundary-check"><input type="checkbox" id="boundary-show-disagreement" checked> <span data-i18n="boundaryShowDisagreement">Разногласия</span></label>
                        <label class="boundary-check"><input type="checkbox" id="boundary-show-current" checked> <span data-i18n="boundaryShowCurrent">Текущий маршрут</span></label>
                        <button type="button" id="boundary-reset-zoom" class="btn-link" data-i18n="boundaryResetZoom">Сбросить масштаб</button>
                    </div>
                </div>
                <fieldset class="boundary-class-filters">
                    <legend data-i18n="boundaryClassFilter">Показывать классы</legend>
                    <label class="class-filter walk"><input type="checkbox" data-boundary-class="walk" checked> 🚶 <span data-i18n="modeWalkShort">Пешком</span></label>
                    <label class="class-filter car"><input type="checkbox" data-boundary-class="car" checked> 🚗 <span data-i18n="modeCarShort">Авто</span></label>
                    <label class="class-filter bus"><input type="checkbox" data-boundary-class="bus" checked> 🚌 <span data-i18n="modeBusShort">Автобус</span></label>
                </fieldset>
                <div class="boundary-chart-wrap">
                    <canvas id="boundary-chart" height="390" aria-describedby="boundary-chart-description"></canvas>
                </div>
                <p id="boundary-chart-description" class="sr-only" data-i18n="boundaryChartDescription">Цветные области показывают прогноз класса, точки — обучающие примеры, звезда — выбранный маршрут.</p>

                <div class="what-if-panel">
                    <div class="what-if-heading">
                        <div><span class="section-kicker">WHAT-IF</span><h4 data-i18n="whatIfTitle">Что будет, если изменить маршрут?</h4></div>
                        <label><span data-i18n="rankingPriority">Приоритет:</span>
                            <select id="what-if-priority">
                                <option value="balanced" data-i18n="priorityBalanced">Баланс</option>
                                <option value="fast" data-i18n="priorityFast">Быстрее</option>
                                <option value="cheap" data-i18n="priorityCheap">Дешевле</option>
                                <option value="eco" data-i18n="priorityEco">Экологичнее</option>
                            </select>
                        </label>
                    </div>
                    <div class="what-if-controls">
                        <label><span data-i18n="modelInputDistance">Дистанция</span><output id="what-if-distance-output">100 км</output><input type="range" id="what-if-distance" min="0" max="100" value="65"></label>
                        <label><span data-i18n="modelInputStops">Остановки</span><output id="what-if-stops-output">4</output><input type="range" id="what-if-stops" min="2" max="12" value="4"></label>
                    </div>
                    <div id="what-if-result" class="what-if-result" aria-live="polite"></div>
                </div>

                <details class="accessible-model-table">
                    <summary data-i18n="boundaryTableToggle">Показать доступную таблицу значений</summary>
                    <div class="table-scroll"><table><thead><tr><th data-i18n="modelInputDistance">Дистанция</th><th data-i18n="modelInputStops">Остановки</th><th>MLP</th><th>Softmax</th></tr></thead><tbody id="boundary-data-table"></tbody></table></div>
                </details>
            </section>

            <section id="ml-view-compare" class="ml-lab-view hidden" role="tabpanel" aria-labelledby="ml-tab-compare" tabindex="0">
                <div class="section-heading-inline"><div><span class="section-kicker">A/B</span><h3 data-i18n="modelCompareTitle">MLP и Softmax — одновременно</h3></div><span id="model-agreement-badge" class="model-agreement-badge">—</span></div>
                <div id="model-comparison-cards" class="model-comparison-cards"></div>
                <div class="ab-stats-panel">
                    <h4 data-i18n="abStatsTitle">A/B-тест: MLP vs Softmax</h4>
                    <p data-i18n="abStatsConfidenceHint">Победитель не объявляется до 30 отзывов на вариант; показывается 95% доверительный интервал.</p>
                    <div id="ab-stats-content" class="ab-stats-content"></div>
                </div>
            </section>

            <section id="ml-view-quality" class="ml-lab-view hidden" role="tabpanel" aria-labelledby="ml-tab-quality" tabindex="0">
                <div class="section-heading-inline"><div><span class="section-kicker">VALIDATION</span><h3 data-i18n="modelQualityTitle">Качество на независимой выборке</h3></div><span id="quality-sample-count" class="model-meta-chip">—</span></div>
                <div id="quality-summary" class="quality-summary"></div>
                <div class="quality-grid">
                    <section><h4 data-i18n="confusionMatrixTitle">Confusion matrix</h4><div id="confusion-matrix" class="table-scroll"></div></section>
                    <section><h4 data-i18n="calibrationTitle">Калибровка вероятностей</h4><div class="calibration-chart-wrap"><canvas id="calibration-chart" height="260"></canvas></div></section>
                </div>
                <div id="per-class-metrics" class="table-scroll"></div>
            </section>

            <section id="ml-view-network" class="ml-lab-view hidden" role="tabpanel" aria-labelledby="ml-tab-network" tabindex="0">
                <div class="section-heading-inline"><div><span class="section-kicker">FORWARD PASS</span><h3 data-i18n="networkTitle">Как сигнал проходит через нейросеть</h3></div><span class="model-meta-chip">2 → 8 → 3</span></div>
                <p data-i18n="networkHint">Толщина и яркость связей отражают активации текущего маршрута; это точные вычисления forward pass.</p>
                <div id="network-visual" class="network-visual" role="img" data-i18n-aria-label="networkAriaLabel"></div>
                <div id="network-values" class="network-values"></div>
            </section>

            <section id="ml-view-training" class="ml-lab-view hidden" role="tabpanel" aria-labelledby="ml-tab-training" tabindex="0">
                <div class="section-heading-inline"><div><span class="section-kicker">TRAINING RUN</span><h3 data-i18n="trainingTitle">Как модель обучалась</h3></div><span id="training-run-date" class="model-meta-chip">—</span></div>
                <p data-i18n="trainingHint">Кривая потерь и воспроизводимые снимки границы показывают, как MLP менялась по эпохам.</p>
                <div class="training-grid">
                    <section>
                        <h4 data-i18n="trainingCurveTitle">Кросс-энтропия по эпохам</h4>
                        <div class="training-chart-wrap"><canvas id="training-curve-chart" height="300"></canvas></div>
                    </section>
                    <section>
                        <div class="training-snapshot-heading"><h4 data-i18n="trainingBoundaryTitle">Эволюция границы решений</h4><button type="button" id="training-play" class="btn secondary compact-btn" data-i18n="trainingPlay">▶ Показать</button></div>
                        <canvas id="training-boundary-canvas" class="training-boundary-canvas" width="640" height="300" role="img" data-i18n-aria-label="trainingBoundaryAriaLabel"></canvas>
                        <label class="training-scrubber"><span data-i18n="trainingEpoch">Эпоха</span><input type="range" id="training-snapshot-slider" min="0" max="0" value="0" step="1"><output id="training-snapshot-output">—</output></label>
                        <div id="training-snapshot-metrics" class="training-snapshot-metrics"></div>
                    </section>
                </div>
            </section>

            <section id="ml-view-data" class="ml-lab-view hidden" role="tabpanel" aria-labelledby="ml-tab-data" tabindex="0">
                <div class="section-heading-inline"><div><span class="section-kicker">DATASET</span><h3 data-i18n="datasetTitle">Данные и похожие примеры</h3></div><span id="dataset-privacy-badge" class="model-meta-chip" data-i18n="datasetPrivacyBadge">без персональных данных</span></div>
                <div id="dataset-summary" class="dataset-summary"></div>
                <h4 data-i18n="nearestExamplesTitle">Ближайшие обучающие примеры</h4>
                <div id="nearest-examples" class="nearest-examples"></div>
            </section>

            <section id="ml-view-card" class="ml-lab-view hidden" role="tabpanel" aria-labelledby="ml-tab-card" tabindex="0">
                <div class="section-heading-inline"><div><span class="section-kicker">MODEL CARD</span><h3 data-i18n="modelCardTitle">Паспорт модели</h3></div><button type="button" id="export-model-card" class="btn secondary compact-btn" data-i18n="exportModelCard">Экспорт JSON</button></div>
                <div id="model-card-content" class="model-card-content"></div>
                <div class="release-policy-card"><h4 data-i18n="releasePolicyTitle">Безопасный выпуск модели</h4><ol><li data-i18n="releaseStepQueue">Исправления попадают в обезличенную очередь.</li><li data-i18n="releaseStepBatch">Кандидат обучается только пакетно.</li><li data-i18n="releaseStepGate">Публикация проходит holdout-gate по F1 и log loss.</li><li data-i18n="releaseStepRollback">Каждая версия допускает rollback.</li></ol></div>
            </section>
        </div>
    </section>

    <button type="button" id="install-button" class="install-btn hidden" data-i18n="installApp">⬇️ Установить приложение</button>
</div>

<script src="https://unpkg.com/maplibre-gl@5.24.0/dist/maplibre-gl.js"></script>
<script src="assets/js/i18n.js?v=14"></script>
<script src="assets/js/route-editor.js?v=14"></script>
<script src="assets/js/ml_boundary.js?v=14"></script>
<script src="assets/js/app.js?v=14"></script>
<script src="assets/js/ui.js?v=14"></script>
<script src="assets/js/product.js?v=14"></script>
</body>
</html>
