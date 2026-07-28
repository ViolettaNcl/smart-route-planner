/**
 * Простая i18n-надстройка без сборщиков и фреймворков: словарь строк для
 * двух языков + применение перевода к DOM по атрибутам data-i18n /
 * data-i18n-placeholder. Выбранный язык хранится в localStorage, чтобы
 * не сбрасываться между визитами.
 *
 * Бэкенд (PHP) отдаёт сообщения об ошибках на русском + машинный `error_code`
 * для типовых случаев (см. route.php/RoutePlanner.php) — на фронтенде мы
 * переводим по коду, если он известен, и показываем ответ сервера "как есть",
 * если код неизвестен (например, нестандартная ошибка).
 */

const I18N = {
    ru: {
        pageTitle: 'Smart Route Planner',
        heading: '🚗 Smart Route Planner',
        pointsLabel: 'Введите точки через «;»',
        pointsPlaceholder: 'Например: Волгоград, Россия;Ростов-на-Дону, Россия;Воронеж, Россия;Москва, Россия',
        submitIdle: 'Рассчитать маршрут',
        submitLoading: 'Считаю маршрут…',
        costSettingsSummary: '⚙️ Настройки стоимости поездки',
        fuelPriceLabel: 'Цена топлива, ₽/л',
        fuelConsumptionLabel: 'Расход, л/100км',
        ticketPriceLabel: 'Цена билета, ₽/км',
        statStops: '📍 Точек',
        statDistance: '📏 Дистанция (оптимизированная)',
        statTransport: '🚘 Транспорт',
        statTime: '⏱ Время в пути',
        statCost: '💰 Стоимость поездки',
        confidenceLabel: (pct) => `Уверенность модели: ${pct}%`,
        routingOsrm: '📍 Дистанция и время — по реальным дорогам (OSRM)',
        routingStraight: '📏 Дистанция — по прямой (сервис маршрутизации недоступен, время — оценка)',
        costNote: (mode) => {
            if (mode === 'car') return '⛽ Оценка по расходу топлива — приблизительная.';
            if (mode === 'bus') return '🎫 Оценка стоимости билета — приблизительная.';
            return '🚶 Пешком — поездка бесплатна.';
        },
        linkGoogle: 'Google Maps',
        linkYandex: 'Yandex Maps',
        shareButton: '🔗 Скопировать ссылку на маршрут',
        shareCopied: '🔗 Ссылка скопирована в буфер обмена!',
        shareCopyFailed: (url) => `Не удалось скопировать автоматически: ${url}`,
        mapPlaceholder: 'Введите города и нажмите «Рассчитать маршрут» — здесь появится карта с оптимизированным маршрутом.',
        skippedWarning: (list) => `⚠️ Не удалось распознать: ${list.join(', ')} — эти точки пропущены.`,
        genericNetworkError: 'Не удалось связаться с сервером. Проверьте соединение и попробуйте ещё раз.',
        genericRouteError: 'Не удалось построить маршрут.',
        shareLinkBroken: 'Не удалось прочитать ссылку маршрута — возможно, она повреждена.',
        installApp: '⬇️ Установить приложение',
        transportModes: { walk: 'пешком', car: 'авто', bus: 'общественный транспорт' },
        errorCodes: {
            MIN_TWO_POINTS: 'Нужно минимум 2 распознанных города, чтобы построить маршрут.',
            EMPTY_POINTS: 'Укажите хотя бы два города через «;».',
            METHOD_NOT_ALLOWED: 'Метод не поддерживается, используйте POST.',
            INTERNAL_ERROR: 'Внутренняя ошибка сервера. Попробуйте позже.',
        },
        currency: '₽',

        // --- AI-ассистент поездки ---
        assistantTitle: '🤖 AI-совет по поездке',
        assistantLoading: 'Продумываю маршрут…',
        assistantSourceLlm: '✨ сгенерировано ИИ',
        assistantSourceFallback: 'ℹ️ офлайн-подсказка (LLM не подключён — см. docs/setup_guide.md)',
        assistantError: 'Не удалось получить AI-комментарий к маршруту.',

        // --- погода по маршруту ---
        weatherLoading: 'Гружу погоду…',
        weatherWarningPrefix: '⚠️',

        // --- точки интереса (Overpass) ---
        poiButton: '📍 Показать точки интереса (АЗС/кафе/отели)',
        poiButtonLoading: 'Ищу точки интереса…',
        poiButtonHide: '📍 Скрыть точки интереса',
        poiEmpty: 'Рядом с маршрутом ничего не нашлось (или Overpass API недоступен).',
        poiCategoryLabels: { fuel: 'АЗС', cafe: 'кафе', restaurant: 'ресторан', hotel: 'отель' },

        // --- визуализация decision boundary ---
        boundaryTitle: '🧠 Как модель делит маршруты на walk / car / bus',
        boundaryIntro: 'Карта решений нейросети: по осям — дистанция маршрута (км, логарифмическая шкала) '
            + 'и число точек. Цвет области — предсказанный транспорт, точки — примеры из обучающего датасета.',
        boundaryToggle: 'Показать карту решений модели',
        boundaryToggleHide: 'Скрыть карту решений модели',
        boundaryLoading: 'Строю карту решений…',
        boundaryStopsLabel: (n) => `Точек в маршруте: ${n}`,
        boundaryModelLabel: 'Модель:',
        boundaryModelMlp: 'нейросеть (MLP)',
        boundaryModelSoftmax: 'линейная (softmax)',
        boundaryAxisX: 'Дистанция, км (лог. шкала)',
        boundaryAxisY: 'Число точек маршрута',
        boundaryError: 'Не удалось загрузить карту решений модели.',
        boundaryRegionSuffix: 'область решения',
        boundarySampleSuffix: 'обучающие примеры',

        // --- CO2 ---
        statEmissions: '🌱 CO2 выбросы',
        emissionsCompareLabel: (mode) => 'если бы ' + mode,
        emissionsNote: 'Грубая оценка по усреднённым коэффициентам (авто ~120 г/км, автобус/поезд ~68 г/км, пешком — 0).',

        // --- explainability ---
        explainToggle: '🔍 Почему такой транспорт?',
        explainToggleHide: '🔍 Скрыть объяснение',
        explainLoading: 'Разбираю предсказание…',
        explainMlpIntro: 'Активации скрытого слоя нейросети и их вклад в счёт класса '
            + '(положительный — "за", отрицательный — "против"):',
        explainSoftmaxIntro: 'Вклад каждого признака в счёт класса (линейная модель, скрытого слоя нет):',
        explainError: 'Не удалось получить объяснение.',
        explainFeatureNames: { distance_feature: 'дистанция', stops_feature: 'число точек', bias: 'смещение (bias)' },

        // --- live learning ---
        learnPrompt: 'Модель ошиблась?',
        learnButtonWalk: '🚶 пешком',
        learnButtonCar: '🚗 авто',
        learnButtonBus: '🚌 автобус',
        learnApplied: (before, after) => `Учтено! Уверенность в "${after.mode_ru}": было ${before.confidence}% → стало ${after.confidence}%`,
        learnNotSupported: 'Точечное дообучение сейчас недоступно (активна fallback softmax-модель).',
        learnError: 'Не удалось применить дообучение.',
        resetModelButton: '↩️ Сбросить модель к обученному состоянию',
        resetModelDone: 'Модель сброшена к изначально обученным весам.',
        resetModelError: 'Не удалось сбросить модель.',

        // --- A/B тест ---
        abFeedbackPrompt: (variantLabel) => `Модель (${variantLabel}) угадала транспорт?`,
        abFeedbackThanks: 'Спасибо! Отзыв учтён в статистике A/B-теста.',
        abFeedbackError: 'Не удалось сохранить отзыв.',
        abStatsTitle: '📊 A/B-тест: MLP vs Softmax (реальные отзывы посетителей)',
        abStatsLoading: 'Загружаю статистику…',
        abStatsError: 'Не удалось загрузить статистику.',
        abStatsEmpty: (n) => `пока ${n} отзыв(ов)`,
        abStatsRow: (label, correct, total, accuracy) =>
            `${label}: ${correct}/${total} верно (${accuracy}%)`,

        // --- AI-планировщик по дням (K-Means) ---
        dayPlanButton: '📅 Разбить маршрут по дням (K-Means)',
        dayPlanButtonHide: '📅 Скрыть план по дням',
        dayPlanButtonLoading: 'Считаю кластеризацию…',
        dayPlanDaysLabel: 'Число дней:',
        dayPlanApply: 'Пересчитать',
        dayPlanIntro: 'Маршрут разбит на дни алгоритмом K-Means (кластеризация без учителя, с нуля на PHP): '
            + 'каждый день — сбалансированный по километражу отрезок пути, без нарушения порядка городов.',
        dayPlanDayLabel: (n) => `День ${n}`,
        dayPlanRoute: (from, to) => `${from} → ${to}`,
        dayPlanError: 'Не удалось построить план по дням.',
        dayPlanSuggested: (n) => `Предложено моделью: ${n} дн.`,

        sectionMlTools: '🧠 Как решала модель',
        sectionRouteStops: '📍 Города маршрута',
        sectionExtras: '🧰 Дополнительно',
        sectionShare: '🔗 Поделиться',

        // --- вкладки результата (десктопный layout) ---
        tabCities: '📍 Города',
        tabModel: '🧠 Модель',
        tabAssistant: '🤖 AI-совет',
        tabExtras: '🧰 Доп.',
        tabShare: '🔗 Поделиться',
        themeToggleToLight: 'Светлая тема',
        themeToggleToDark: 'Тёмная тема',

        // --- блок "как это работает" в форме ---
        highlightsTitle: 'Как это работает',
        highlightRoadsTitle: 'Реальные дороги',
        highlightRoadsText: 'Дистанция и время — по дорожной сети (OSRM), а не по прямой',
        highlightAiTitle: 'Нейросеть учится на лету',
        highlightAiText: 'Предсказывает транспорт и подстраивается под ваши правки',
        highlightCostTitle: 'Стоимость и CO2',
        highlightCostText: 'Расход топлива или билет плюс экологический след поездки',
    },
    en: {
        pageTitle: 'Smart Route Planner',
        heading: '🚗 Smart Route Planner',
        pointsLabel: "Enter stops separated by ';'",
        pointsPlaceholder: 'e.g.: Volgograd, Russia;Rostov-on-Don, Russia;Voronezh, Russia;Moscow, Russia',
        submitIdle: 'Calculate route',
        submitLoading: 'Calculating…',
        costSettingsSummary: '⚙️ Trip cost settings',
        fuelPriceLabel: 'Fuel price, ₽/L',
        fuelConsumptionLabel: 'Consumption, L/100km',
        ticketPriceLabel: 'Ticket price, ₽/km',
        statStops: '📍 Stops',
        statDistance: '📏 Distance (optimized)',
        statTransport: '🚘 Transport',
        statTime: '⏱ Travel time',
        statCost: '💰 Trip cost',
        confidenceLabel: (pct) => `Model confidence: ${pct}%`,
        routingOsrm: '📍 Distance and time follow real roads (OSRM)',
        routingStraight: '📏 Straight-line distance (routing service unavailable, time is an estimate)',
        costNote: (mode) => {
            if (mode === 'car') return '⛽ Fuel-cost estimate — approximate.';
            if (mode === 'bus') return '🎫 Ticket-price estimate — approximate.';
            return '🚶 On foot — the trip is free.';
        },
        linkGoogle: 'Google Maps',
        linkYandex: 'Yandex Maps',
        shareButton: '🔗 Copy route link',
        shareCopied: '🔗 Link copied to clipboard!',
        shareCopyFailed: (url) => `Could not copy automatically: ${url}`,
        mapPlaceholder: 'Enter cities and click "Calculate route" — the optimized route will appear here on the map.',
        skippedWarning: (list) => `⚠️ Could not recognize: ${list.join(', ')} — these stops were skipped.`,
        genericNetworkError: 'Could not reach the server. Check your connection and try again.',
        genericRouteError: 'Could not build the route.',
        shareLinkBroken: 'Could not read the route link — it may be corrupted.',
        installApp: '⬇️ Install app',
        transportModes: { walk: 'on foot', car: 'car', bus: 'public transport' },
        errorCodes: {
            MIN_TWO_POINTS: 'At least 2 recognized cities are required to build a route.',
            EMPTY_POINTS: "Please enter at least two cities separated by ';'.",
            METHOD_NOT_ALLOWED: 'Method not allowed, please use POST.',
            INTERNAL_ERROR: 'Internal server error. Please try again later.',
        },
        currency: '₽',

        // --- AI trip assistant ---
        assistantTitle: '🤖 AI trip advice',
        assistantLoading: 'Thinking about your route…',
        assistantSourceLlm: '✨ generated by AI',
        assistantSourceFallback: 'ℹ️ offline suggestion (no LLM configured — see docs/setup_guide.md)',
        assistantError: 'Could not get an AI comment for this route.',

        // --- weather along the route ---
        weatherLoading: 'Loading weather…',
        weatherWarningPrefix: '⚠️',

        // --- points of interest (Overpass) ---
        poiButton: '📍 Show points of interest (fuel/cafes/hotels)',
        poiButtonLoading: 'Looking up points of interest…',
        poiButtonHide: '📍 Hide points of interest',
        poiEmpty: 'Nothing found near the route (or the Overpass API is unavailable).',
        poiCategoryLabels: { fuel: 'fuel', cafe: 'cafe', restaurant: 'restaurant', hotel: 'hotel' },

        // --- decision boundary visualization ---
        boundaryTitle: '🧠 How the model splits routes into walk / car / bus',
        boundaryIntro: 'The model\'s decision map: axes are route distance (km, log scale) and number of '
            + 'stops. Color is the predicted transport mode, dots are examples from the training dataset.',
        boundaryToggle: 'Show model decision map',
        boundaryToggleHide: 'Hide model decision map',
        boundaryLoading: 'Building decision map…',
        boundaryStopsLabel: (n) => `Stops in route: ${n}`,
        boundaryModelLabel: 'Model:',
        boundaryModelMlp: 'neural net (MLP)',
        boundaryModelSoftmax: 'linear (softmax)',
        boundaryAxisX: 'Distance, km (log scale)',
        boundaryAxisY: 'Number of stops',
        boundaryError: 'Could not load the model decision map.',
        boundaryRegionSuffix: 'decision region',
        boundarySampleSuffix: 'training examples',

        // --- CO2 ---
        statEmissions: '🌱 CO2 emissions',
        emissionsCompareLabel: (mode) => 'if by ' + mode,
        emissionsNote: 'Rough estimate using average factors (car ~120 g/km, bus/train ~68 g/km, walking — 0).',

        // --- explainability ---
        explainToggle: '🔍 Why this transport mode?',
        explainToggleHide: '🔍 Hide explanation',
        explainLoading: 'Breaking down the prediction…',
        explainMlpIntro: 'Hidden-layer activations and their contribution to the class score '
            + '(positive — "for", negative — "against"):',
        explainSoftmaxIntro: 'Contribution of each feature to the class score (linear model, no hidden layer):',
        explainError: 'Could not load the explanation.',
        explainFeatureNames: { distance_feature: 'distance', stops_feature: 'number of stops', bias: 'bias' },

        // --- live learning ---
        learnPrompt: 'Did the model get it wrong?',
        learnButtonWalk: '🚶 walk',
        learnButtonCar: '🚗 car',
        learnButtonBus: '🚌 bus',
        learnApplied: (before, after) => `Learned! Confidence in "${after.mode}": was ${before.confidence}% → now ${after.confidence}%`,
        learnNotSupported: 'Live learning is unavailable right now (fallback softmax model is active).',
        learnError: 'Could not apply the correction.',
        resetModelButton: '↩️ Reset model to trained state',
        resetModelDone: 'Model reset to its originally trained weights.',
        resetModelError: 'Could not reset the model.',

        // --- A/B test ---
        abFeedbackPrompt: (variantLabel) => `Did the model (${variantLabel}) get the transport right?`,
        abFeedbackThanks: 'Thanks! Your feedback was added to the A/B test stats.',
        abFeedbackError: 'Could not save your feedback.',
        abStatsTitle: '📊 A/B test: MLP vs Softmax (real visitor feedback)',
        abStatsLoading: 'Loading stats…',
        abStatsError: 'Could not load stats.',
        abStatsEmpty: (n) => `${n} response(s) so far`,
        abStatsRow: (label, correct, total, accuracy) =>
            `${label}: ${correct}/${total} correct (${accuracy}%)`,

        // --- AI day-trip planner (K-Means) ---
        dayPlanButton: '📅 Split route into days (K-Means)',
        dayPlanButtonHide: '📅 Hide day plan',
        dayPlanButtonLoading: 'Running clustering…',
        dayPlanDaysLabel: 'Number of days:',
        dayPlanApply: 'Recalculate',
        dayPlanIntro: 'The route is split into days using K-Means (unsupervised clustering, built from scratch in PHP): '
            + 'each day is a balanced-distance leg of the trip, without breaking the order of cities.',
        dayPlanDayLabel: (n) => `Day ${n}`,
        dayPlanRoute: (from, to) => `${from} → ${to}`,
        dayPlanError: 'Could not build the day plan.',
        dayPlanSuggested: (n) => `Suggested by the model: ${n} day(s)`,

        sectionMlTools: '🧠 How the model decided',
        sectionRouteStops: '📍 Route stops',
        sectionExtras: '🧰 More tools',
        sectionShare: '🔗 Share',

        // --- result tabs (desktop layout) ---
        tabCities: '📍 Cities',
        tabModel: '🧠 Model',
        tabAssistant: '🤖 AI advice',
        tabExtras: '🧰 Extra',
        tabShare: '🔗 Share',
        themeToggleToLight: 'Light theme',
        themeToggleToDark: 'Dark theme',

        // --- "how it works" block in the form ---
        highlightsTitle: 'How it works',
        highlightRoadsTitle: 'Real roads',
        highlightRoadsText: 'Distance and time follow the actual road network (OSRM), not a straight line',
        highlightAiTitle: 'The model learns on the fly',
        highlightAiText: 'Predicts transport mode and adapts to your corrections',
        highlightCostTitle: 'Cost and CO2',
        highlightCostText: 'Fuel or ticket cost plus the trip\'s carbon footprint',
    },
};

const LANG_STORAGE_KEY = 'srp_lang';

function getLang() {
    return localStorage.getItem(LANG_STORAGE_KEY) || 'ru';
}

function setLang(lang) {
    localStorage.setItem(LANG_STORAGE_KEY, lang);
    applyTranslations();
}

/**
 * Возвращает строку/функцию перевода по ключу для текущего языка.
 */
function t(key) {
    const lang = getLang();
    return (I18N[lang] && I18N[lang][key] !== undefined) ? I18N[lang][key] : I18N.ru[key];
}

/**
 * Переводит текст ошибки по machine-readable коду от бэкенда.
 * Если код неизвестен — возвращает исходное сообщение сервера как есть
 * (оно на русском, но лучше показать хоть что-то, чем ничего).
 */
function translateError(errorCode, fallbackMessage) {
    const codes = t('errorCodes');
    if (errorCode && codes[errorCode]) {
        return codes[errorCode];
    }
    return fallbackMessage || t('genericRouteError');
}

function applyTranslations() {
    const lang = getLang();
    document.documentElement.lang = lang;

    document.querySelectorAll('[data-i18n]').forEach((el) => {
        const key = el.getAttribute('data-i18n');
        const value = t(key);
        if (typeof value === 'string') {
            el.textContent = value;
        }
    });

    document.querySelectorAll('[data-i18n-placeholder]').forEach((el) => {
        const key = el.getAttribute('data-i18n-placeholder');
        const value = t(key);
        if (typeof value === 'string') {
            el.placeholder = value;
        }
    });

    document.title = t('pageTitle');

    document.querySelectorAll('.lang-switch button').forEach((btn) => {
        btn.classList.toggle('active', btn.dataset.lang === lang);
    });
}

document.addEventListener('DOMContentLoaded', () => {
    applyTranslations();

    document.querySelectorAll('.lang-switch button').forEach((btn) => {
        btn.addEventListener('click', () => setLang(btn.dataset.lang));
    });
});
