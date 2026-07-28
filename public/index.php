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
    <meta name="theme-color" content="#14171c">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="RoutePlanner">
    <link rel="apple-touch-icon" href="assets/icons/icon-192.png">
    <link rel="icon" href="assets/icons/icon-192.png" type="image/png">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@500;600;700&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="assets/css/route.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
          integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
</head>
<body>

<div class="container">
    <div class="top-bar">
        <h1 data-i18n="heading">🚗 Smart Route Planner</h1>
        <div class="top-bar-controls">
            <button type="button" id="theme-toggle" class="theme-toggle" aria-label="Theme switch">
                <span class="theme-toggle-icon">🌙</span>
            </button>
            <div class="lang-switch" role="group" aria-label="Language switch">
                <button type="button" data-lang="ru">RU</button>
                <button type="button" data-lang="en">EN</button>
            </div>
        </div>
    </div>

    <div class="layout">
        <div class="panel">
            <form id="route-form">
                <label for="points" data-i18n="pointsLabel">Введите точки через «;»</label>
                <div class="autocomplete-wrap">
                    <textarea id="points" name="points" autocomplete="off"
                        data-i18n-placeholder="pointsPlaceholder"
                        placeholder="Например: Волгоград, Россия;Ростов-на-Дону, Россия;Воронеж, Россия;Москва, Россия"></textarea>
                    <ul id="suggestions" class="suggestions hidden"></ul>
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

            <div id="error-banner" class="error-banner hidden"></div>
            <div id="warning-banner" class="warning-banner hidden"></div>

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
        </div>

        <div class="map-panel">
            <div id="map-placeholder" class="map-placeholder" data-i18n="mapPlaceholder">
                Введите города и нажмите «Рассчитать маршрут» — здесь появится карта с оптимизированным маршрутом.
            </div>
            <div id="map" class="hidden"></div>
        </div>
    </div>

    <!-- Результат расчёта: полноширинная приборная панель под строкой
         форма+карта — вместо одной длинной колонки слева, статистика идёт
         компактной лентой, а остальное разложено по вкладкам. -->
    <div id="result-section" class="hidden">
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
                <button type="button" class="tab-nav-btn active" data-tab="cities" data-i18n="tabCities">📍 Города</button>
                <button type="button" class="tab-nav-btn" data-tab="model" data-i18n="tabModel">🧠 Модель</button>
                <button type="button" class="tab-nav-btn" data-tab="assistant" data-i18n="tabAssistant">🤖 AI-совет</button>
                <button type="button" class="tab-nav-btn" data-tab="extras" data-i18n="tabExtras">🧰 Доп.</button>
                <button type="button" class="tab-nav-btn" data-tab="share" data-i18n="tabShare">🔗 Поделиться</button>
            </div>

            <div class="tab-panel" data-tab-panel="cities">
                <ul id="points-list" class="points-list"></ul>
            </div>

            <div class="tab-panel hidden" data-tab-panel="model">
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

            <div class="tab-panel hidden" data-tab-panel="assistant">
                <div id="assistant-card" class="assistant-card hidden">
                    <h3 data-i18n="assistantTitle">🤖 AI-совет по поездке</h3>
                    <p id="assistant-text" class="assistant-text"></p>
                    <span id="assistant-source" class="assistant-source"></span>
                </div>
            </div>

            <div class="tab-panel hidden" data-tab-panel="extras">
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

            <div class="tab-panel hidden" data-tab-panel="share">
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

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script src="assets/js/i18n.js"></script>
<script src="assets/js/ml_boundary.js"></script>
<script src="assets/js/app.js"></script>
<script src="assets/js/ui.js"></script>
</body>
</html>
