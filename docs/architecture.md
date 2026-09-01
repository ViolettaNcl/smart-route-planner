# Архитектура приложения

[🇬🇧 English version](architecture.en.md)

## Общий обзор

Проект построен как слоистое приложение с чёткой ответственностью каждого
класса, а не один процедурный файл. На верхнем уровне файлов класса ничего
не выполняется — каждый класс только предоставляет методы, поэтому вся
бизнес-логика (кроме HTTP-обвязки) покрыта тестами без поднятия веб-сервера.

База данных не используется. Исходные веса и воспроизводимый отчёт обучения
хранятся в репозитории (`src/ML/model_weights.json`,
`src/ML/mlp_weights.json`, `src/ML/training_report.json`), а изменяемое
состояние (кэш, rate limiter, A/B-статистика, логи и очередь обезличенных
исправлений) проходит через `RuntimeStorage`: локально это `var/`, на
Vercel — временный `/tmp`.

Проект вырос из простого расчёта маршрута в набор независимых фич,
каждая — отдельный API-эндпоинт, который не блокирует и не ломает основной
сценарий, если недоступен: AI-совет по поездке, погода, точки интереса,
план по дням, объяснимый ML Lab, A/B-тест и безопасная очередь обратной
связи.

## Структура файлов

```text
bootstrap.php                 # Автозагрузчик классов (работает без composer install)
composer.json                 # PSR-4 автозагрузка (опционально, для composer dump-autoload)
vercel.json                   # PHP 8.3 runtime и маршруты Vercel Functions
api/index.php                 # Единый Vercel front controller для всех API-маршрутов
bin/
  train_model.php             # CLI: обучает MLP/Softmax, сохраняет веса, метрики и снимки обучения
  review_feedback.php         # CLI: review allow-list, anomaly checks, holdout gate и promotion кандидата
  model_admin.php             # CLI: статус реестра версий и rollback
public/
  index.php                   # Веб-интерфейс (форма, карта, все виджеты фич)
  manifest.webmanifest         # PWA-манифест (установка на телефон как приложение)
  service-worker.js            # PWA: офлайн-кэш оболочки интерфейса
  api/
    route.php                  # POST: главный расчёт маршрута (гео + оптимизация + ML + маршрутизация + стоимость)
    day_plan.php                # POST: план маршрута по дням (K-Means)
    suggest.php                  # GET: configured search; public Nominatim = submit-only
    poi.php                       # POST: точки интереса рядом с маршрутом (Overpass)
    weather.php                    # POST: погода по точкам маршрута (Open-Meteo)
    assistant.php                   # POST: AI-совет по поездке (LLM или rule-based fallback)
    decision_boundary.php            # GET: сетка предсказаний модели для визуализации границы решений
    explain.php                       # GET: разбор одного предсказания модели по числам ("почему такой транспорт")
    model_insights.php                # GET: персональное объяснение, сравнение, контрфакты и рейтинг
    model_quality.php                 # GET: test-метрики, калибровка, Model Card и снимки обучения
    ab_stats.php                      # GET: агрегированная статистика A/B-теста MLP vs Softmax
    feedback.php                      # POST: фиксирует "угадала ли модель" для варианта текущего визита
    learn.php                          # POST: обезличенное исправление в очередь; production-веса не меняются
    reset_model.php                    # POST: защищённый admin-token сброс к проверенному baseline
    health.php                           # GET: health-check для аптайм-мониторинга и Docker HEALTHCHECK
  assets/
    css/route.css
    js/
      app.js                    # Оркестрация фронтенда: fetch к API, состояние формы, рендер карточек/карты/виджетов (POI/погода/план по дням), обработчики
      route-editor.js           # Структурированные остановки: reorder/reverse/map-pick/demo
      product.js                # Альтернативы, манёвры, история, экспорт, mobile sheet
      ui.js                      # UI-слой без обращений к API: светлая/тёмная тема (сохраняется в localStorage, перекрашивает карту и график модели) + вкладки панели результата
      i18n.js                    # Словарь RU/EN + применение перевода к DOM, хранение выбора в localStorage
      ml_boundary.js               # Chart.js-визуализация decision boundary (MLP ⇄ softmax) + A/B-виджет
    icons/                      # Логотип: favicon (svg/ico/png) + иконки PWA (192/512/512-maskable) + SVG-исходники для регенерации
src/
  RoutePlanner.php              # Оркестратор: связывает гео, оптимизацию, маршрутизацию, ML, стоимость, CO2
  Geocoding/
    GeocoderInterface.php
    NominatimGeocoder.php       # cURL + кэш + rate limit
    FileCache.php
  Routing/
    HaversineCalculator.php     # Расстояние по дуге большого круга (запасной вариант)
    RouteOptimizer.php           # Nearest Neighbor + 2-opt
    RoadRouterInterface.php       # Контракт дорожного маршрутизатора
    OsrmRoadRouter.php             # Реальный маршрут по дорогам через OSRM
    TravelTimeEstimator.php         # Оценка времени в пути по средней скорости
    CostEstimator.php                # Прикидочная стоимость поездки (топливо / билет), настраиваемые параметры
    EmissionsEstimator.php            # Оценка выбросов CO2 по видам транспорта (усреднённые коэффициенты)
  ML/
    ClassifierInterface.php       # Общий контракт MLP/Softmax
    Dataset.php                  # Генератор синтетического датасета
    FeatureEncoder.php            # Общие преобразования признаков (train + inference)
    MLPClassifier.php              # Нейросеть (скрытый слой tanh + softmax-выход, backprop с нуля)
    SoftmaxClassifier.php          # Линейный baseline, обучение градиентным спуском
    ModelEvaluator.php               # Confusion matrix, F1, log loss, Brier, calibration, k-fold CV
    KMeansDaySplitter.php            # Unsupervised: K-Means (метод Ллойда), план маршрута по дням поездки
    TransportPredictor.php          # Загружает версионированные веса и выполняет read-only inference
    ABTestStats.php                  # Файловое хранилище счётчиков A/B-теста MLP vs Softmax (flock)
    ModelInsightService.php          # Локальное влияние, counterfactual, похожие примеры, рейтинг вариантов
    ModelQualityService.php          # Отдельные validation/test-метрики и Model Card
    FeedbackStore.php                # Append-only очередь обезличенных исправлений и архив
    ModelRegistry.php                # CLI-only promotion/rollback версий модели
    mlp_weights.json                  # Изначально обученные веса MLP (генерируются train_model.php)
    mlp_weights.trained.json           # Резервная копия/эталон весов MLP для api/reset_model.php
    model_weights.json                  # Веса Softmax-baseline
    training_report.json                # Loss-кривые и снимки границы, связанные с весами по SHA-256
  AI/
    TripAssistantService.php      # AI-совет: Vercel AI Gateway / Anthropic / OpenAI / fallback
  Weather/
    OpenMeteoClient.php            # Погода по точкам маршрута (без ключа)
  Geodata/
    OverpassPoiFinder.php          # АЗС/кафе/рестораны/отели рядом с маршрутом (без ключа)
  Http/
    RateLimiter.php                # Token bucket с нуля, файловое хранилище с flock
    ClientIdentity.php              # Определение клиента по IP (с опциональным доверием X-Forwarded-For)
    RateLimitGuard.php               # Обвязка: одна строка в начале api/*.php -> 429 при превышении лимита
  Support/
    RuntimeStorage.php             # var/ локально, доступный для записи /tmp на Vercel
    Logger.php                     # Файловый логгер без зависимостей — fallback-переключения
tests/
  run.php                      # Точка входа: php tests/run.php
  TestReporter.php              # Мини-замена ассертов PHPUnit
  HaversineCalculatorTest.php
  RouteOptimizerTest.php
  SoftmaxClassifierTest.php
  MLPClassifierTest.php
  ModelEvaluatorTest.php
  KMeansDaySplitterTest.php
  ABTestStatsTest.php
  CostEstimatorTest.php
  EmissionsEstimatorTest.php
  TravelTimeEstimatorTest.php
  RateLimiterTest.php
  RoutePlannerTest.php
  Fakes/
    FakeGeocoder.php
    FakeRoadRouter.php
  Http/
    HttpTestServer.php            # Поднимает настоящий php -S для HTTP-тестов
    ApiHttpTest.php                 # HTTP-интеграционные тесты (405/422/429, error_code, day_plan/assistant/... "живьём")
.github/
  workflows/ci.yml              # GitHub Actions: lint + тесты на PHP 8.1/8.2/8.3, smoke-тест сервера, composer audit
Dockerfile                     # php:8.3-apache, document root -> public/
docker-compose.yml              # Локальный запуск / простой деплой на VPS
.dockerignore
.env.example                    # Шаблон переменных окружения для docker-compose
.gitignore
```

## Поток данных (веб-запрос на расчёт маршрута)

1. Пользователь редактирует независимые остановки в `route-editor.js`; `app.js`
   отправляет `stops_json` (label + необязательные lat/lon), legacy `points` и
   флаг оптимизации в `api/route.php`.
2. `public/api/route.php` создаёт зависимости (`NominatimGeocoder`,
   `HaversineCalculator`, `RouteOptimizer`, `TransportPredictor`, `OsrmRoadRouter`,
   `CostEstimator`) и передаёт их в `RoutePlanner`.
3. `RoutePlanner::planStops()` (legacy `plan()` остаётся совместимой обёрткой):
   - нормализует до 12 остановок со стабильными внутренними ID;
   - геокодирует каждую точку (`GeocoderInterface::geocode`), непризнанные
     города собирает в `skipped`, а не молча теряет;
   - если валидных точек меньше двух — возвращает ошибку;
   - сохраняет старт/финиш и оптимизирует только промежуточные точки;
   - пытается построить реальный маршрут через `RoadRouterInterface::route()`
     (реализация — `OsrmRoadRouter`): сначала проверяет свежий файловый кэш,
     затем по порядку обращается к настроенным OSRM-compatible endpoint и при
     их временном отказе допускает недавнюю stale-копию; только после отказа
     всей цепочки приложение **честно откатывается** на дистанцию «по воздуху»
     (`HaversineCalculator`) и прямые линии на карте;
   - предсказывает транспорт обученной моделью (`TransportPredictor::predict`),
     передавая либо дорожную, либо воздушную дистанцию — в зависимости от того,
     что удалось получить;
   - оценивает время в пути: точное от OSRM (только для авто) либо
     приблизительное по средней скорости (`TravelTimeEstimator`);
   - оценивает стоимость поездки (`CostEstimator`) и выбросы CO2
     (`EmissionsEstimator`) по предсказанному транспорту;
   - формирует ссылки на Google Maps и Яндекс.Карты.
4. `api/route.php` отдаёт результат как JSON, включая `routing_source`,
   `routing_provider`, `routing_cached`, `routing_cache_status` и
   `routing_failover_used`, а также назначенный этому визиту вариант
   модели для A/B-теста (`model_variant`: `mlp` либо `softmax`, 50/50) —
   фронтенд честно показывает пользователю, какой источник данных и какая
   модель использовались.
5. `app.js` обновляет карточки результата (включая время, стоимость, CO2) и
   запускает сцену MapLibre GL JS: камера показывает границы пути, линия
   поэтапно рисуется по реальной геометрии дороги и затем открывается краткая
   сводка. Стили OpenFreeMap Fiord/Liberty синхронизируются с темой через
   `ui.js`; 2D и 3D переключаются без пересоздания карты. В 3D как независимые
   progressive-enhancement слои добавляются здания, рельеф/hillshade
   Mapterhorn, globe-атмосфера и свет. Ошибка любого из этих слоёв не влияет
   на API-результат: при отказе WebGL/стиля рендерится SVG из координат ответа.
   Локальные shell-ассеты имеют явную UI-версию, а HTML-навигация service
   worker использует network-first, поэтому deployment не остаётся за старым
   PWA-кэшем.

## Дополнительные API-эндпоинты (после основного расчёта маршрута)

Каждая из этих фич — отдельный, необязательный вызов фронтенда уже после
того, как основной маршрут посчитан и показан. Ни один из них не может
заблокировать или сломать `api/route.php`:

| Эндпоинт | Что делает |
|---|---|
| `api/assistant.php` | AI-совет: Vercel AI Gateway (OIDC), Anthropic/OpenAI либо офлайн fallback (`App\AI\TripAssistantService`) |
| `api/weather.php` | Погода по каждой точке маршрута через Open-Meteo, без ключа API (`App\Weather\OpenMeteoClient`) |
| `api/poi.php` | Точки интереса (АЗС/кафе/рестораны/отели) рядом с маршрутом через Overpass API, без ключа (`App\Geodata\OverpassPoiFinder`) |
| `api/day_plan.php` | Делит уже посчитанный маршрут на сбалансированные по километражу дни вождения (`App\ML\KMeansDaySplitter`, unsupervised K-Means) |
| `api/suggest.php` | Поиск через явно настроенный совместимый endpoint; с публичным Nominatim отвечает `submit_only` без autocomplete-запроса |
| `api/decision_boundary.php` | Считает предсказание модели на регулярной сетке [дистанция × число точек] для визуализации границы решений на Chart.js |
| `api/explain.php` | Разбирает одно конкретное предсказание модели по числам — "почему выбран именно этот транспорт" |
| `api/model_insights.php` | Возвращает вероятности обеих моделей, локальное влияние признаков, ближайшую смену класса, похожие примеры, нейронные активации и прозрачный рейтинг транспорта |
| `api/model_quality.php` | Возвращает отдельные validation/test-метрики, confusion matrix, F1, log loss, Brier, calibration, Model Card и снимки обучения |
| `api/ab_stats.php` | Отдаёт агрегированную статистику A/B-теста MLP vs Softmax из `var/ab_stats.json` |
| `api/feedback.php` | Фиксирует 👍/👎 — угадала ли модель для варианта, назначенного этому визиту |
| `api/learn.php` | Добавляет обезличенное исправление в append-only очередь; единичный HTTP-запрос никогда не меняет production-веса |
| `api/reset_model.php` | Защищённая `X-Model-Admin-Token` административная операция восстановления baseline; отсутствует в публичном UI |
| `api/health.php` | Health-check без обращения к внешним сервисам — для аптайм-мониторинга и Docker `HEALTHCHECK` |

## Шеринг маршрута без базы данных

Кнопка «Скопировать ссылку» не обращается к серверу: структурированные точки
вместе с координатами кодируются в Base64 прямо в URL (`?s=<base64>`), а
legacy-ссылки `?r=<base64>` продолжают поддерживаться. При открытии JS
восстанавливает редактор и инициирует расчёт — получатель сразу видит готовый
результат. Это осознанный компромисс: без БД нельзя
синхронизировать историю между устройствами или сокращать ссылку. Локальная
история/избранное хранятся только в браузере, а GeoJSON/GPX/KML формируются
на клиенте без загрузки пользовательского маршрута на сервер.

## Поток данных (обучение модели)

`bin/train_model.php` — отдельный, независимый от веб-запроса процесс:

1. `Dataset::generate()` генерирует помеченные примеры (дистанция, число
   точек, метка класса) — см. `docs/neural_net.md` про честность этого
   датасета.
2. Данные делятся на train/validation/test (80/10/10); test остаётся
   нетронутым до финальной оценки.
3. `MLPClassifier::train()` и `SoftmaxClassifier::train()` обучаются на
   одной и той же обучающей выборке — скрипт печатает точность обеих
   моделей на validation и test для честного сравнения.
4. `ModelEvaluator` строит confusion matrix, precision/recall/F1 по каждому
   классу и прогоняет k-fold кросс-валидацию для обеих моделей.
5. Веса сохраняются в `src/ML/mlp_weights.json` (и административная копия в
   `mlp_weights.trained.json`) и `src/ML/model_weights.json`; loss-кривые и
   шесть снимков границы записываются в `training_report.json` с SHA-256
   версий. Эти файлы читает `TransportPredictor` во время обычной работы
   приложения (по умолчанию — MLP, с откатом на Softmax).

## Компоненты и их ответственность

| Компонент | Ответственность |
|---|---|
| `RoutePlanner` | Оркестрация: геокодирование, оптимизация, маршрутизация, ML-предсказание, время, стоимость, CO2, ссылки |
| `NominatimGeocoder` + `FileCache` | Геокодирование с кэшем и соблюдением лимитов API |
| `HaversineCalculator` | Расстояние между двумя точками и суммарная длина маршрута (запасной вариант) |
| `OsrmRoadRouter` | Реальный маршрут по дорогам, точная дистанция/время для авто, геометрия для карты |
| `TravelTimeEstimator` | Приблизительное время в пути по средней скорости, когда точных данных нет |
| `RouteOptimizer` | Оптимизация порядка обхода точек (эвристика TSP: Nearest Neighbor + 2-opt) |
| `CostEstimator` | Прикидочная стоимость поездки (топливо/билет) с настраиваемыми параметрами |
| `EmissionsEstimator` | Оценка выбросов CO2 по виду транспорта (усреднённые коэффициенты) |
| `Dataset` | Генерация обучающих данных для ML-модели |
| `FeatureEncoder` | Единая логика преобразования сырых величин в признаки модели |
| `MLPClassifier` | Нейросеть (скрытый слой tanh + softmax) — прямой/обратный проход, backprop с нуля |
| `SoftmaxClassifier` | Обучение и инференс линейного baseline |
| `ModelEvaluator` | Confusion matrix, precision/recall/F1 по классам, k-fold кросс-валидация |
| `KMeansDaySplitter` | Unsupervised K-Means (метод Ллойда, 1D по кумулятивной дистанции) — план по дням, сохраняя порядок городов |
| `TransportPredictor` | Загрузка версионированных весов и read-only предсказание транспорта |
| `ModelInsightService` | Персональное объяснение, чувствительность, counterfactual, похожие примеры и рейтинг |
| `ModelQualityService` | Validation/test-оценка, calibration, Model Card и provenance обучения |
| `FeedbackStore` | Дедуплицированная append-only очередь обезличенных исправлений и архивирование рассмотренных событий |
| `ModelRegistry` | CLI-only promotion/rollback проверенных версий модели |
| `ABTestStats` | Файловое хранилище счётчиков A/B-теста MLP vs Softmax |
| `TripAssistantService` | AI-совет — Gateway с OIDC, прямые провайдеры и rule-based fallback |
| `OpenMeteoClient` | Погода по точкам маршрута |
| `OverpassPoiFinder` | Точки интереса рядом с маршрутом |
| `RateLimiter` | Token bucket с нуля: непрерывное пополнение токенов, файловое хранилище с `flock`, fail-open при сбое диска |
| `ClientIdentity` | Определение клиента по IP для rate limiting |
| `RateLimitGuard` | Обвязка над `RateLimiter` для использования в одну строку в начале `api/*.php` — 429 при превышении |
| `RuntimeStorage` | Единый путь для кэша, rate-limit, логов и изменяемых весов: `var/` локально, `/tmp` на Vercel |
| `Logger` | Файловый логгер без зависимостей — фиксирует fallback-переключения (OSRM→Haversine, LLM→rule-based) |
| `Tests\Http\HttpTestServer` | Поднимает настоящий `php -S`, даёт `get()`/`post()` через cURL — основа HTTP-интеграционных тестов |
| `public/assets/js/app.js` | Оркестрация фронтенда: fetch к API, состояние формы, рендер карточек/карты/виджетов фич, обработчики событий |
| `public/assets/js/route-editor.js` | Структурированный редактор точек и выбор координат на карте |
| `public/assets/js/product.js` | Альтернативы, навигация, история/избранное, экспорт, mobile bottom sheet |
| `public/assets/js/ui.js` | UI-слой без обращений к API: тема (светлая/тёмная, localStorage), синхронизация темы с картой и графиком модели, вкладки панели результата |
| `public/assets/js/i18n.js` | Локализация интерфейса (RU/EN), хранение выбора в `localStorage` |
| `public/assets/js/ml_boundary.js` | Визуализация decision boundary модели и A/B-виджета через Chart.js |

## Взаимодействие с внешними системами

- **OpenStreetMap Nominatim** — явное геокодирование при расчёте; публичный
  endpoint не используется для autocomplete. Отдельно настроенный совместимый
  endpoint может обслуживать `api/suggest.php`.
- **OSRM** (router.project-osrm.org) — построение реального маршрута по дорогам,
  точная дистанция и время в пути для автомобиля (см. `OsrmRoadRouter`); публичный
  демо-сервер без SLA, приложение обязано корректно работать и при его недоступности.
- **OpenFreeMap + MapLibre GL JS** — векторная 2D/3D-карта без регистрации и
  API-ключа; данные OpenStreetMap/OpenMapTiles, обязательная атрибуция сохранена
  в интерфейсе.
- **Overpass API** — точки интереса рядом с маршрутом (см. `OverpassPoiFinder`).
- **Open-Meteo** — погода по точкам маршрута, без ключа (см. `OpenMeteoClient`).
- **Vercel AI Gateway / Anthropic / OpenAI** — Gateway с OIDC используется
  на Vercel, прямые ключи остаются резервом (см. `TripAssistantService`);
  без ключа — честный rule-based fallback.
- **Google Maps / Яндекс.Карты** — генерация ссылок на готовый маршрут (открывается
  в новой вкладке, не встроено).

## Ограничения и допущения

- Оптимизация порядка точек — эвристика (Nearest Neighbor + 2-opt), не точный
  алгоритм: для малого числа точек (до ~20) разница с истинным оптимумом на
  практике минимальна, а точный перебор (n!) стал бы неприемлемо медленным.
- Первая введённая пользователем точка всегда остаётся стартом маршрута — это
  осознанное решение (человек начинает поездку оттуда, где находится физически),
  а не ограничение алгоритма.
- Модель предсказания транспорта не имеет доступа к реальной дорожной сети,
  пробкам или расписанию — только дистанция и число точек.
- Данные не сохраняются между запросами, кроме кэша геокодирования, состояния
  rate limiter'а, агрегированной A/B-статистики и обезличенной очереди
  исправлений. На Vercel это временные данные и они могут исчезнуть после
  cold start или деплоя.
- Публичный `api/learn.php` не изменяет веса. Promotion возможен только через
  `bin/review_feedback.php` после ручного allow-list review, anomaly checks и
  holdout-gate; `bin/model_admin.php` сохраняет возможность rollback.
