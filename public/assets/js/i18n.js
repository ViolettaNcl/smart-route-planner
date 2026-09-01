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
        heading: 'Smart Route Planner',
        brandEyebrow: 'Route intelligence',
        mapKeylessStatus: 'Карта без API-ключа',
        mapKeylessStatusHint: 'OpenFreeMap работает без регистрации и публичного API-ключа',
        routeSetupKicker: '01 · Маршрут',
        routeSetupTitle: 'Куда отправимся?',
        routeSetupSubtitle: 'Добавьте минимум две точки — порядок будет оптимизирован автоматически.',
        pointsLabel: 'Введите точки через «;»',
        pointsPlaceholder: 'Например: Волгоград, Россия;Ростов-на-Дону, Россия;Воронеж, Россия;Москва, Россия',
        routeInputHint: 'Разделяйте города точкой с запятой',
        routePointCount: (n) => {
            const mod10 = n % 10;
            const mod100 = n % 100;
            const noun = mod10 === 1 && mod100 !== 11
                ? 'точка'
                : (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14) ? 'точки' : 'точек');
            return `${n} ${noun}`;
        },
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
        confidenceLabel: (pct) => `Оценка модели: ${pct}%`,
        routingOsrm: '📍 Дистанция и время — по реальным дорогам (OSRM)',
        routingRoadProvider: (provider) => `📍 Дистанция и время — по реальным дорогам · ${provider}`,
        routingStraight: '📏 Дистанция — по прямой (сервис маршрутизации недоступен, время — оценка)',
        routingProviderProject: 'Project OSRM',
        routingProviderFossgis: 'FOSSGIS OSRM',
        routingProviderConfigured: 'свой OSRM',
        routingProviderFailover: 'резервная цепочка OSRM',
        routingProviderGeneric: 'OSRM',
        routingFreshCache: 'проверенный кэш',
        routingStaleCache: 'резервная копия маршрута',
        routingBackupUsed: 'использован резервный сервер',
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
        mapCanvasTitle: 'Навигационная сцена',
        mapCanvasSubtitle: 'Fiord / Liberty · OpenStreetMap · без ключа',
        mapStyleFiord: 'FIORD · фирменный тёмный',
        mapStyleLiberty: 'LIBERTY · фирменный светлый',
        mapPlaceholderKicker: 'Интерактивная карта',
        mapPlaceholderTitle: 'Маршрут оживёт здесь',
        mapPlaceholder: 'Введите города и нажмите «Рассчитать маршрут» — здесь появится карта с оптимизированным маршрутом.',
        mapPill2d: 'Точная 2D',
        mapPill3d: 'Рельефная 3D',
        mapPillKeyless: 'Без API-ключа',
        mapAriaLabel: 'Интерактивная карта рассчитанного маршрута',
        mapModeLabel: 'Режим отображения карты',
        mapWebglError: 'Интерактивная карта недоступна в этом браузере. Сводка маршрута остаётся доступной.',
        mapStatusCalculating: 'Рассчитываю реальный маршрут…',
        mapStatusFraming: 'Настраиваю обзор маршрута…',
        mapStatusDrawing: 'Прорисовываю геометрию пути…',
        mapStatusReady: 'Маршрут на карте готов',
        mapPickHint: 'Нажмите на нужное место',
        mapSummaryDistance: 'Дистанция',
        mapSummaryTime: 'В пути',
        mapSummaryMode: 'Режим',
        mapSummaryRoadSource: 'Маршрут следует реальным дорогам · OSRM',
        mapSummaryRoadSourceProvider: (provider) => `Маршрут следует реальным дорогам · ${provider}`,
        mapSummaryFallbackSource: 'Маршрут показан по прямой · сервис дорог недоступен',
        mapStaticMode: 'Упрощённый вид: WebGL недоступен, показана фактическая геометрия маршрута.',
        routeBriefKicker: '02 · Сводка',
        routeBriefTitle: 'Маршрут одним взглядом',
        routeReady: 'Маршрут готов',
        timelineStart: 'Старт',
        timelineStop: 'Промежуточная точка',
        timelineFinish: 'Финиш',
        skippedWarning: (list) => `⚠️ Не удалось распознать: ${list.join(', ')} — эти точки пропущены.`,
        genericNetworkError: 'Не удалось связаться с сервером. Проверьте соединение и попробуйте ещё раз.',
        genericRouteError: 'Не удалось построить маршрут.',
        shareLinkBroken: 'Не удалось прочитать ссылку маршрута — возможно, она повреждена.',
        installApp: '⬇️ Установить приложение',
        transportModes: { walk: 'пешком', car: 'авто', bus: 'общественный транспорт' },
        errorCodes: {
            MIN_TWO_POINTS: 'Нужно минимум 2 распознанных города, чтобы построить маршрут.',
            EMPTY_POINTS: 'Укажите минимум две точки маршрута.',
            INVALID_STOPS: 'Список точек повреждён. Проверьте редактор и попробуйте снова.',
            TOO_MANY_STOPS: 'В одном маршруте можно использовать не более 12 точек.',
            PAYLOAD_TOO_LARGE: 'Список точек слишком большой. Сократите названия и попробуйте снова.',
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
        boundaryToggle: 'Показать ML Lab',
        boundaryToggleHide: 'Скрыть ML Lab',
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
        boundaryShowRegions: 'Области',
        boundaryShowSamples: 'Примеры',
        boundaryShowDisagreement: 'Разногласия',
        boundaryShowCurrent: 'Текущий маршрут',
        boundaryClassFilter: 'Показывать классы',
        modeWalkShort: 'Пешком',
        modeCarShort: 'Авто',
        modeBusShort: 'Автобус',
        boundaryResetZoom: 'Сбросить масштаб',
        boundaryChartDescription: 'Цветные области показывают прогноз класса, точки — обучающие примеры, звезда — выбранный маршрут.',
        boundaryTableToggle: 'Показать доступную таблицу значений',

        // --- ML Lab 2.0 ---
        modelExplanationKicker: 'Объяснимая рекомендация',
        modelExplanationTitle: 'Почему модель выбрала этот транспорт?',
        modelInsightWaiting: 'Рассчитайте маршрут, чтобы увидеть персональное объяснение.',
        modelInsightLoading: 'Анализирую прогноз и ближайшие альтернативы…',
        modelInsightError: 'Не удалось загрузить объяснение модели.',
        modelRecommendationLabel: 'Рекомендация модели',
        modelProbabilityDisclaimer: 'Расчётная вероятность, не гарантия.',
        modelScoreLabel: 'оценка модели',
        modelInputDistance: 'Дистанция',
        modelInputStops: 'Остановки',
        modelInputMargin: 'Отрыв от второго места',
        modelInputBoundary: 'Ближайшая граница',
        modelInfluenceTitle: 'Что повлияло',
        modelCounterfactualTitle: 'Что изменит решение',
        modelRankingTitle: 'Рейтинг транспорта',
        modelRankingHint: 'Вероятность модели + время + стоимость + CO₂',
        modelCertaintyLabels: { stable: 'устойчивый прогноз', moderate: 'средняя определённость', ambiguous: 'пограничный прогноз' },
        modelAgreement: 'Модели согласны',
        modelDisagreement: 'Модели не согласны',
        modelFeatureDistance: 'Дистанция',
        modelFeatureStops: 'Количество остановок',
        influenceHigherSupports: (feature, mode) => `Увеличение «${feature}» усиливает вариант «${mode}».`,
        influenceLowerSupports: (feature, mode) => `Уменьшение «${feature}» усиливает вариант «${mode}».`,
        influenceNeutral: (feature) => `«${feature}» почти не меняет этот прогноз рядом с текущим значением.`,
        counterfactualDistance: (value, mode) => `Около ${value} км решение сменится на «${mode}».`,
        counterfactualStops: (value, mode) => `При ${value} остановках решение сменится на «${mode}».`,
        counterfactualNone: 'Поблизости модель не меняет решение.',
        rankingPriority: 'Приоритет:',
        priorityBalanced: 'Баланс',
        priorityFast: 'Быстрее',
        priorityCheap: 'Дешевле',
        priorityEco: 'Экологичнее',
        rankingOptionMeta: (minutes, cost, co2) => `${minutes} мин · ${cost} ₽ · ${co2} кг CO₂`,
        rankingNotViable: 'непрактично для этой дистанции',
        feedbackQueueHint: 'Исправление попадёт в обезличенную очередь и не изменит общие веса сразу.',
        feedbackCorrectModeLabel: 'Выберите правильный транспорт',
        feedbackQueued: (n) => `Спасибо! Исправление добавлено в очередь для пакетной проверки (${n}).`,
        feedbackDuplicate: 'Этот отзыв уже был учтён.',
        openMlLab: 'Открыть полную ML Lab',
        mlLabTitle: 'Лаборатория решений модели',
        mlLabSectionsLabel: 'Разделы ML Lab',
        mlReadOnlyBadge: 'read-only · production safe',
        mlTabBoundary: 'Карта решений',
        mlTabCompare: 'Сравнение',
        mlTabQuality: 'Качество',
        mlTabNetwork: 'Нейросеть',
        mlTabTraining: 'Обучение',
        mlTabData: 'Данные',
        mlTabCard: 'Model Card',
        whatIfTitle: 'Что будет, если изменить маршрут?',
        modelCompareTitle: 'MLP и Softmax — одновременно',
        abStatsConfidenceHint: 'Победитель не объявляется до 30 отзывов на вариант; показывается 95% доверительный интервал.',
        abStatsInterval: (low, high) => `95% ДИ: ${low ?? '—'}–${high ?? '—'}%`,
        abStatsNotReady: 'недостаточно данных',
        modelQualityTitle: 'Качество на независимой выборке',
        confusionMatrixTitle: 'Confusion matrix',
        calibrationTitle: 'Калибровка вероятностей',
        networkTitle: 'Как сигнал проходит через нейросеть',
        networkHint: 'Толщина и яркость связей отражают активации текущего маршрута; это точные вычисления forward pass.',
        networkAriaLabel: 'Схема нейросети с двумя входами, восемью скрытыми нейронами и тремя выходами',
        trainingTitle: 'Как модель обучалась',
        trainingHint: 'Кривая потерь и воспроизводимые снимки границы показывают, как MLP менялась по эпохам.',
        trainingCurveTitle: 'Кросс-энтропия по эпохам',
        trainingBoundaryTitle: 'Эволюция границы решений',
        trainingPlay: '▶ Показать',
        trainingPause: '❚❚ Пауза',
        trainingReferenceRun: 'эталонный прогон',
        trainingEpoch: 'Эпоха',
        trainingBoundaryAriaLabel: 'Снимок границы решений MLP на выбранной эпохе обучения',
        trainingSnapshotMetrics: (loss, accuracy) => `Loss ${loss} · валидационная accuracy ${accuracy}%`,
        datasetTitle: 'Данные и похожие примеры',
        datasetPrivacyBadge: 'без персональных данных',
        nearestExamplesTitle: 'Ближайшие обучающие примеры',
        modelCardTitle: 'Паспорт модели',
        exportModelCard: 'Экспорт JSON',
        releasePolicyTitle: 'Безопасный выпуск модели',
        releaseStepQueue: 'Исправления попадают в обезличенную очередь.',
        releaseStepBatch: 'Кандидат обучается только пакетно.',
        releaseStepGate: 'Публикация проходит holdout-gate по F1 и log loss.',
        releaseStepRollback: 'Каждая версия допускает rollback.',
        metricAccuracy: 'Accuracy',
        metricMacroF1: 'Macro-F1',
        metricLogLoss: 'Log loss',
        metricBrier: 'Brier score',
        metricEce: 'Ошибка калибровки',
        datasetSummary: (total, validation) => `${total} синтетических примеров · ${validation} в независимой проверке · seed 42`,
        modelCardLimitations: 'Известные ограничения',
        modelCardUses: 'Назначение',
        modelCardOutOfScope: 'Не предназначена для',

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

        // --- safe correction queue ---
        learnPrompt: 'Модель ошиблась?',
        learnButtonWalk: '🚶 пешком',
        learnButtonCar: '🚗 авто',
        learnButtonBus: '🚌 автобус',
        learnError: 'Не удалось сохранить исправление.',

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
        tabNavigation: '🧭 Навигация',
        tabModel: '🧠 Модель',
        tabAssistant: '🤖 AI-совет',
        tabExtras: '🧰 Доп.',
        tabShare: '🔗 Поделиться',
        mobileRouteSheet: 'Редактор маршрута',
        routeEditorLabel: 'Точки маршрута',
        routeEditorTools: 'Инструменты редактора маршрута',
        routeEditorHint: 'Старт и финиш фиксированы; промежуточные точки можно менять местами',
        addStop: '＋ Точка',
        reverseRoute: '⇅ Развернуть',
        pickOnMap: '⌖ На карте',
        demoRoute: '▶ Демо',
        optimizeOrderTitle: 'Оптимизировать промежуточные',
        optimizeOrderHint: 'Старт и финиш сохранятся',
        focusMap: '⛶ Карта',
        routeOptionsKicker: 'Варианты OSRM',
        routeOptionsTitle: 'Сравните реальные маршруты',
        routeOptionsHint: 'Показаны только варианты, возвращённые дорожным сервисом',
        routeOptionsLabel: 'Выбор варианта маршрута',
        navigationTitle: 'Пошаговые инструкции',
        navigationHint: 'Манёвры и расстояния получены из выбранного дорожного маршрута.',
        navigationEmpty: 'Для резервного прямого маршрута пошаговая навигация недоступна.',
        printRoute: 'Печать',
        favoriteRoute: '☆ В избранное',
        savedRoutes: 'Недавние и избранные',
        clearHistory: 'Очистить историю',
        mlLabDescription: 'Исследуйте границы решений, сравните модели и проверьте качество на независимой выборке.',
        themeToggleToLight: 'Светлая тема',
        themeToggleToDark: 'Тёмная тема',

        // --- блок "как это работает" в форме ---
        highlightsTitle: 'Как это работает',
        highlightRoadsTitle: 'Реальные дороги',
        highlightRoadsText: 'Дистанция и время — по дорожной сети (OSRM), а не по прямой',
        highlightAiTitle: 'Объяснимая ML-рекомендация',
        highlightAiText: 'Сравнивает модели, показывает причины и безопасно собирает исправления',
        highlightCostTitle: 'Стоимость и CO2',
        highlightCostText: 'Расход топлива или билет плюс экологический след поездки',
    },
    en: {
        pageTitle: 'Smart Route Planner',
        heading: 'Smart Route Planner',
        brandEyebrow: 'Route intelligence',
        mapKeylessStatus: 'Keyless map',
        mapKeylessStatusHint: 'OpenFreeMap works without registration or a public API key',
        routeSetupKicker: '01 · Route',
        routeSetupTitle: 'Where are we going?',
        routeSetupSubtitle: 'Add at least two stops — their order will be optimized automatically.',
        pointsLabel: "Enter stops separated by ';'",
        pointsPlaceholder: 'e.g.: Volgograd, Russia;Rostov-on-Don, Russia;Voronezh, Russia;Moscow, Russia',
        routeInputHint: 'Separate cities with semicolons',
        routePointCount: (n) => `${n} ${n === 1 ? 'stop' : 'stops'}`,
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
        confidenceLabel: (pct) => `Model score: ${pct}%`,
        routingOsrm: '📍 Distance and time follow real roads (OSRM)',
        routingRoadProvider: (provider) => `📍 Distance and time follow real roads · ${provider}`,
        routingStraight: '📏 Straight-line distance (routing service unavailable, time is an estimate)',
        routingProviderProject: 'Project OSRM',
        routingProviderFossgis: 'FOSSGIS OSRM',
        routingProviderConfigured: 'configured OSRM',
        routingProviderFailover: 'OSRM failover chain',
        routingProviderGeneric: 'OSRM',
        routingFreshCache: 'verified cache',
        routingStaleCache: 'cached route backup',
        routingBackupUsed: 'backup server used',
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
        mapCanvasTitle: 'Navigation scene',
        mapCanvasSubtitle: 'Fiord / Liberty · OpenStreetMap · keyless',
        mapStyleFiord: 'FIORD · branded dark',
        mapStyleLiberty: 'LIBERTY · branded light',
        mapPlaceholderKicker: 'Interactive map',
        mapPlaceholderTitle: 'Your route comes alive here',
        mapPlaceholder: 'Enter cities and click "Calculate route" — the optimized route will appear here on the map.',
        mapPill2d: 'Precise 2D',
        mapPill3d: 'Terrain 3D',
        mapPillKeyless: 'No API key',
        mapAriaLabel: 'Interactive map of the calculated route',
        mapModeLabel: 'Map display mode',
        mapWebglError: 'The interactive map is unavailable in this browser. The route summary remains available.',
        mapStatusCalculating: 'Calculating the real route…',
        mapStatusFraming: 'Framing the route…',
        mapStatusDrawing: 'Drawing the route geometry…',
        mapStatusReady: 'Route map ready',
        mapPickHint: 'Tap the required place',
        mapSummaryDistance: 'Distance',
        mapSummaryTime: 'Travel time',
        mapSummaryMode: 'Mode',
        mapSummaryRoadSource: 'Route follows real roads · OSRM',
        mapSummaryRoadSourceProvider: (provider) => `Route follows real roads · ${provider}`,
        mapSummaryFallbackSource: 'Straight-line route · road service unavailable',
        mapStaticMode: 'Simplified view: WebGL is unavailable; actual route geometry is shown.',
        routeBriefKicker: '02 · Brief',
        routeBriefTitle: 'Your route at a glance',
        routeReady: 'Route ready',
        timelineStart: 'Start',
        timelineStop: 'Intermediate stop',
        timelineFinish: 'Finish',
        skippedWarning: (list) => `⚠️ Could not recognize: ${list.join(', ')} — these stops were skipped.`,
        genericNetworkError: 'Could not reach the server. Check your connection and try again.',
        genericRouteError: 'Could not build the route.',
        shareLinkBroken: 'Could not read the route link — it may be corrupted.',
        installApp: '⬇️ Install app',
        transportModes: { walk: 'on foot', car: 'car', bus: 'public transport' },
        errorCodes: {
            MIN_TWO_POINTS: 'At least 2 recognized cities are required to build a route.',
            EMPTY_POINTS: 'Add at least two route stops.',
            INVALID_STOPS: 'The stop list is invalid. Check the editor and try again.',
            TOO_MANY_STOPS: 'A route can contain no more than 12 stops.',
            PAYLOAD_TOO_LARGE: 'The stop list is too large. Shorten the labels and try again.',
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
        boundaryToggle: 'Show ML Lab',
        boundaryToggleHide: 'Hide ML Lab',
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
        boundaryShowRegions: 'Regions',
        boundaryShowSamples: 'Examples',
        boundaryShowDisagreement: 'Disagreements',
        boundaryShowCurrent: 'Current route',
        boundaryClassFilter: 'Show classes',
        modeWalkShort: 'Walk',
        modeCarShort: 'Car',
        modeBusShort: 'Bus',
        boundaryResetZoom: 'Reset zoom',
        boundaryChartDescription: 'Coloured regions show predicted classes, dots are training examples, and the star is the selected route.',
        boundaryTableToggle: 'Show accessible values table',

        // --- ML Lab 2.0 ---
        modelExplanationKicker: 'Explainable recommendation',
        modelExplanationTitle: 'Why did the model choose this transport?',
        modelInsightWaiting: 'Calculate a route to see its personalised explanation.',
        modelInsightLoading: 'Analysing the prediction and nearby alternatives…',
        modelInsightError: 'Could not load the model explanation.',
        modelRecommendationLabel: 'Model recommendation',
        modelProbabilityDisclaimer: 'A model probability, not a guarantee.',
        modelScoreLabel: 'model score',
        modelInputDistance: 'Distance',
        modelInputStops: 'Stops',
        modelInputMargin: 'Lead over runner-up',
        modelInputBoundary: 'Nearest boundary',
        modelInfluenceTitle: 'What influenced it',
        modelCounterfactualTitle: 'What changes the decision',
        modelRankingTitle: 'Transport ranking',
        modelRankingHint: 'Model probability + time + cost + CO₂',
        modelCertaintyLabels: { stable: 'stable prediction', moderate: 'medium certainty', ambiguous: 'borderline prediction' },
        modelAgreement: 'Models agree',
        modelDisagreement: 'Models disagree',
        modelFeatureDistance: 'Distance',
        modelFeatureStops: 'Number of stops',
        influenceHigherSupports: (feature, mode) => `Increasing “${feature}” supports “${mode}”.`,
        influenceLowerSupports: (feature, mode) => `Decreasing “${feature}” supports “${mode}”.`,
        influenceNeutral: (feature) => `“${feature}” barely changes this prediction near the current value.`,
        counterfactualDistance: (value, mode) => `Around ${value} km the decision changes to “${mode}”.`,
        counterfactualStops: (value, mode) => `At ${value} stops the decision changes to “${mode}”.`,
        counterfactualNone: 'The model does not change its decision nearby.',
        rankingPriority: 'Priority:',
        priorityBalanced: 'Balanced',
        priorityFast: 'Faster',
        priorityCheap: 'Cheaper',
        priorityEco: 'Greener',
        rankingOptionMeta: (minutes, cost, co2) => `${minutes} min · ₽${cost} · ${co2} kg CO₂`,
        rankingNotViable: 'impractical for this distance',
        feedbackQueueHint: 'The correction enters an anonymous review queue and never changes shared weights immediately.',
        feedbackCorrectModeLabel: 'Choose the correct transport mode',
        feedbackQueued: (n) => `Thank you! The correction was queued for batch review (${n}).`,
        feedbackDuplicate: 'This feedback has already been counted.',
        openMlLab: 'Open full ML Lab',
        mlLabTitle: 'Model decision laboratory',
        mlLabSectionsLabel: 'ML Lab sections',
        mlReadOnlyBadge: 'read-only · production safe',
        mlTabBoundary: 'Decision map',
        mlTabCompare: 'Compare',
        mlTabQuality: 'Quality',
        mlTabNetwork: 'Network',
        mlTabTraining: 'Training',
        mlTabData: 'Data',
        mlTabCard: 'Model Card',
        whatIfTitle: 'What if the route changes?',
        modelCompareTitle: 'MLP and Softmax side by side',
        abStatsConfidenceHint: 'No winner is declared before 30 responses per variant; a 95% confidence interval is shown.',
        abStatsInterval: (low, high) => `95% CI: ${low ?? '—'}–${high ?? '—'}%`,
        abStatsNotReady: 'not enough data',
        modelQualityTitle: 'Quality on an unseen holdout set',
        confusionMatrixTitle: 'Confusion matrix',
        calibrationTitle: 'Probability calibration',
        networkTitle: 'How the signal passes through the network',
        networkHint: 'Connection width and brightness reflect current-route activations; these are the real forward-pass values.',
        networkAriaLabel: 'Neural network diagram with two inputs, eight hidden neurons and three outputs',
        trainingTitle: 'How the model learned',
        trainingHint: 'The loss curve and reproducible boundary snapshots show how the MLP changed across epochs.',
        trainingCurveTitle: 'Cross-entropy by epoch',
        trainingBoundaryTitle: 'Decision-boundary evolution',
        trainingPlay: '▶ Play',
        trainingPause: '❚❚ Pause',
        trainingReferenceRun: 'reference run',
        trainingEpoch: 'Epoch',
        trainingBoundaryAriaLabel: 'MLP decision-boundary snapshot at the selected training epoch',
        trainingSnapshotMetrics: (loss, accuracy) => `Loss ${loss} · validation accuracy ${accuracy}%`,
        datasetTitle: 'Data and similar examples',
        datasetPrivacyBadge: 'no personal data',
        nearestExamplesTitle: 'Nearest training examples',
        modelCardTitle: 'Model Card',
        exportModelCard: 'Export JSON',
        releasePolicyTitle: 'Safe model release',
        releaseStepQueue: 'Corrections enter an anonymous queue.',
        releaseStepBatch: 'Candidates are trained only in reviewed batches.',
        releaseStepGate: 'Promotion requires a holdout gate on F1 and log loss.',
        releaseStepRollback: 'Every version supports rollback.',
        metricAccuracy: 'Accuracy',
        metricMacroF1: 'Macro-F1',
        metricLogLoss: 'Log loss',
        metricBrier: 'Brier score',
        metricEce: 'Calibration error',
        datasetSummary: (total, validation) => `${total} synthetic examples · ${validation} held out · seed 42`,
        modelCardLimitations: 'Known limitations',
        modelCardUses: 'Intended uses',
        modelCardOutOfScope: 'Out of scope',

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

        // --- safe correction queue ---
        learnPrompt: 'Did the model get it wrong?',
        learnButtonWalk: '🚶 walk',
        learnButtonCar: '🚗 car',
        learnButtonBus: '🚌 bus',
        learnError: 'Could not save the correction.',

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
        tabNavigation: '🧭 Navigation',
        tabModel: '🧠 Model',
        tabAssistant: '🤖 AI advice',
        tabExtras: '🧰 Extra',
        tabShare: '🔗 Share',
        mobileRouteSheet: 'Route editor',
        routeEditorLabel: 'Route stops',
        routeEditorTools: 'Route editor tools',
        routeEditorHint: 'Start and finish stay fixed; intermediate stops can be reordered',
        addStop: '＋ Stop',
        reverseRoute: '⇅ Reverse',
        pickOnMap: '⌖ Pick on map',
        demoRoute: '▶ Demo',
        optimizeOrderTitle: 'Optimise intermediate stops',
        optimizeOrderHint: 'Start and finish stay fixed',
        focusMap: '⛶ Map',
        routeOptionsKicker: 'OSRM alternatives',
        routeOptionsTitle: 'Compare real routes',
        routeOptionsHint: 'Only alternatives returned by the road service are shown',
        routeOptionsLabel: 'Choose a route alternative',
        navigationTitle: 'Turn-by-turn directions',
        navigationHint: 'Maneuvers and distances come from the selected road route.',
        navigationEmpty: 'Turn-by-turn directions are unavailable for the straight-line fallback.',
        printRoute: 'Print',
        favoriteRoute: '☆ Add to favourites',
        savedRoutes: 'Recent and favourites',
        clearHistory: 'Clear history',
        mlLabDescription: 'Explore decision boundaries, compare models, and inspect quality on an unseen holdout set.',
        themeToggleToLight: 'Light theme',
        themeToggleToDark: 'Dark theme',

        // --- "how it works" block in the form ---
        highlightsTitle: 'How it works',
        highlightRoadsTitle: 'Real roads',
        highlightRoadsText: 'Distance and time follow the actual road network (OSRM), not a straight line',
        highlightAiTitle: 'Explainable ML recommendation',
        highlightAiText: 'Compares models, shows the reasoning, and queues corrections safely',
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

    document.querySelectorAll('[data-i18n-aria-label]').forEach((el) => {
        const key = el.getAttribute('data-i18n-aria-label');
        const value = t(key);
        if (typeof value === 'string') {
            el.setAttribute('aria-label', value);
        }
    });

    document.querySelectorAll('[data-i18n-title]').forEach((el) => {
        const key = el.getAttribute('data-i18n-title');
        const value = t(key);
        if (typeof value === 'string') {
            el.setAttribute('title', value);
        }
    });

    document.title = t('pageTitle');

    document.querySelectorAll('.lang-switch button').forEach((btn) => {
        btn.classList.toggle('active', btn.dataset.lang === lang);
    });

    if (typeof window.refreshRouteUiLanguage === 'function') {
        window.refreshRouteUiLanguage();
    }
}

document.addEventListener('DOMContentLoaded', () => {
    applyTranslations();

    document.querySelectorAll('.lang-switch button').forEach((btn) => {
        btn.addEventListener('click', () => setLang(btn.dataset.lang));
    });
});
