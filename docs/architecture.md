# Архитектура приложения

[🇬🇧 English version](architecture.en.md)

## Общий обзор

Проект построен как слоистое приложение с чёткой ответственностью каждого
класса, а не один процедурный файл. На верхнем уровне файлов класса ничего
не выполняется — каждый класс только предоставляет методы, поэтому вся
бизнес-логика (кроме HTTP-обвязки) покрыта тестами без поднятия веб-сервера.

База данных не используется: постоянное состояние — только файлы на диске:
кэш геокодирования (`var/geocache/`), состояние rate limiter'а
(`var/ratelimit/`), статистика A/B-теста (`var/ab_stats.json`) и веса
обученной модели (`src/ML/model_weights.json`, `src/ML/mlp_weights.json`).

Проект вырос из простого расчёта маршрута в набор независимых фич,
каждая — отдельный API-эндпоинт, который не блокирует и не ломает основной
сценарий, если недоступен: AI-совет по поездке, погода, точки интереса,
план по дням, карта решений модели, A/B-тест, живое дообучение.

## Структура файлов

```text
bootstrap.php                 # Автозагрузчик классов (работает без composer install)
composer.json                 # PSR-4 автозагрузка (опционально, для composer dump-autoload)
bin/
  train_model.php             # CLI: обучает MLP и Softmax, сохраняет веса и печатает точность обеих моделей
public/
  index.php                   # Веб-интерфейс (форма, карта, все виджеты фич)
  manifest.webmanifest         # PWA-манифест (установка на телефон как приложение)
  service-worker.js            # PWA: офлайн-кэш оболочки интерфейса
  api/
    route.php                  # POST: главный расчёт маршрута (гео + оптимизация + ML + маршрутизация + стоимость)
    day_plan.php                # POST: план маршрута по дням (K-Means)
    suggest.php                  # GET/POST: автоподсказки городов (проксирует Nominatim)
    poi.php                       # POST: точки интереса рядом с маршрутом (Overpass)
    weather.php                    # POST: погода по точкам маршрута (Open-Meteo)
    assistant.php                   # POST: AI-совет по поездке (LLM или rule-based fallback)
    decision_boundary.php            # GET: сетка предсказаний модели для визуализации границы решений
    explain.php                       # GET: разбор одного предсказания модели по числам ("почему такой транспорт")
    ab_stats.php                      # GET: агрегированная статистика A/B-теста MLP vs Softmax
    feedback.php                      # POST: фиксирует "угадала ли модель" для варианта текущего визита
    learn.php                          # POST: "живое" дообучение MLP на одном примере пользователя
    reset_model.php                     # POST: сброс весов модели к изначально обученному состоянию
  assets/
    css/route.css
    js/
      app.js                    # Оркестрация фронтенда: fetch к API, состояние формы, рендер карточек/карты/виджетов (POI/погода/план по дням), обработчики
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
    ModelEvaluator.php               # Confusion matrix, precision/recall/F1, k-fold кросс-валидация
    KMeansDaySplitter.php            # Unsupervised: K-Means (метод Ллойда), план маршрута по дням поездки
    TransportPredictor.php          # Загружает веса (MLP, fallback Softmax), предсказывает, живое дообучение
    ABTestStats.php                  # Файловое хранилище счётчиков A/B-теста MLP vs Softmax (flock)
    mlp_weights.json                  # Изначально обученные веса MLP (генерируются train_model.php)
    mlp_weights.trained.json           # Резервная копия/эталон весов MLP для api/reset_model.php
    model_weights.json                  # Веса Softmax-baseline
  AI/
    TripAssistantService.php      # AI-совет по поездке: LLM (Anthropic/OpenAI) либо rule-based fallback
  Weather/
    OpenMeteoClient.php            # Погода по точкам маршрута (без ключа)
  Geodata/
    OverpassPoiFinder.php          # АЗС/кафе/рестораны/отели рядом с маршрутом (без ключа)
  Http/
    RateLimiter.php                # Token bucket с нуля, файловое хранилище с flock
    ClientIdentity.php              # Определение клиента по IP (с опциональным доверием X-Forwarded-For)
    RateLimitGuard.php               # Обвязка: одна строка в начале api/*.php -> 429 при превышении лимита
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

1. Пользователь вводит города в `public/index.php`, JS (`app.js`) перехватывает
   отправку формы и делает `fetch('api/route.php', { method: 'POST' })`.
2. `public/api/route.php` создаёт зависимости (`NominatimGeocoder`,
   `HaversineCalculator`, `RouteOptimizer`, `TransportPredictor`, `OsrmRoadRouter`,
   `CostEstimator`) и передаёт их в `RoutePlanner`.
3. `RoutePlanner::plan()`:
   - парсит строку точек (`;` как разделитель);
   - геокодирует каждую точку (`GeocoderInterface::geocode`), непризнанные
     города собирает в `skipped`, а не молча теряет;
   - если валидных точек меньше двух — возвращает ошибку;
   - оптимизирует порядок точек (`RouteOptimizer::optimize`);
   - пытается построить реальный маршрут по дорогам через
     `RoadRouterInterface::route()` (реализация — `OsrmRoadRouter`); если OSRM
     недоступен, метод возвращает `null` и приложение **честно откатывается**
     на дистанцию «по воздуху» (`HaversineCalculator`) и прямые линии на карте —
     без падения и без скрытых ошибок;
   - предсказывает транспорт обученной моделью (`TransportPredictor::predict`),
     передавая либо дорожную, либо воздушную дистанцию — в зависимости от того,
     что удалось получить;
   - оценивает время в пути: точное от OSRM (только для авто) либо
     приблизительное по средней скорости (`TravelTimeEstimator`);
   - оценивает стоимость поездки (`CostEstimator`) и выбросы CO2
     (`EmissionsEstimator`) по предсказанному транспорту;
   - формирует ссылки на Google Maps и Яндекс.Карты.
4. `api/route.php` отдаёт результат как JSON, включая `routing_source`
   (`osrm_road` или `straight_line`) и назначенный этому визиту вариант
   модели для A/B-теста (`model_variant`: `mlp` либо `softmax`, 50/50) —
   фронтенд честно показывает пользователю, какой источник данных и какая
   модель использовались.
5. `app.js` обновляет карточки результата (включая время, стоимость, CO2) и
   перерисовывает карту Leaflet — линия маршрута рисуется по настоящей
   геометрии дороги (многоцветным градиентом, старт/финиш выделены отдельно
   от промежуточных точек), если геометрия есть, иначе по прямым между
   городами. Подложка карты (тёмные/светлые тайлы) и цвета графика границы
   решений синхронизируются с выбранной темой интерфейса через `ui.js`.

## Дополнительные API-эндпоинты (после основного расчёта маршрута)

Каждая из этих фич — отдельный, необязательный вызов фронтенда уже после
того, как основной маршрут посчитан и показан. Ни один из них не может
заблокировать или сломать `api/route.php`:

| Эндпоинт | Что делает |
|---|---|
| `api/assistant.php` | AI-совет по поездке: LLM (Anthropic/OpenAI, если задан ключ) либо офлайн rule-based fallback (`App\AI\TripAssistantService`) |
| `api/weather.php` | Погода по каждой точке маршрута через Open-Meteo, без ключа API (`App\Weather\OpenMeteoClient`) |
| `api/poi.php` | Точки интереса (АЗС/кафе/рестораны/отели) рядом с маршрутом через Overpass API, без ключа (`App\Geodata\OverpassPoiFinder`) |
| `api/day_plan.php` | Делит уже посчитанный маршрут на сбалансированные по километражу дни вождения (`App\ML\KMeansDaySplitter`, unsupervised K-Means) |
| `api/suggest.php` | Автоподсказки городов при вводе — проксирует Nominatim (браузер не может сам выставить обязательный User-Agent) |
| `api/decision_boundary.php` | Считает предсказание модели на регулярной сетке [дистанция × число точек] для визуализации границы решений на Chart.js |
| `api/explain.php` | Разбирает одно конкретное предсказание модели по числам — "почему выбран именно этот транспорт" |
| `api/ab_stats.php` | Отдаёт агрегированную статистику A/B-теста MLP vs Softmax из `var/ab_stats.json` |
| `api/feedback.php` | Фиксирует 👍/👎 — угадала ли модель для варианта, назначенного этому визиту |
| `api/learn.php` | "Живое" дообучение: один шаг градиентного спуска MLP на примере, поправленном пользователем |
| `api/reset_model.php` | Сбрасывает веса MLP к изначально обученному состоянию, отменяя эффект `learn.php` |

## Шеринг маршрута без базы данных

Кнопка «Скопировать ссылку» не обращается к серверу вообще: список городов,
введённый пользователем, кодируется в Base64 прямо в URL
(`?r=<base64>`). При открытии такой ссылки JS декодирует параметр,
подставляет города в форму и сам инициирует расчёт маршрута — получатель
сразу видит готовый результат. Это осознанный компромисс: без БД нельзя
хранить историю или сокращать ссылку, но для точечного шеринга конкретного
маршрута это не нужно, а решение получается предельно простым и без единой
таблицы.

## Поток данных (обучение модели)

`bin/train_model.php` — отдельный, независимый от веб-запроса процесс:

1. `Dataset::generate()` генерирует помеченные примеры (дистанция, число
   точек, метка класса) — см. `docs/neural_net.md` про честность этого
   датасета.
2. Данные делятся на обучающую (80%) и валидационную (20%) выборки.
3. `MLPClassifier::train()` и `SoftmaxClassifier::train()` обучаются на
   одной и той же обучающей выборке — скрипт печатает точность обеих
   моделей на валидационной выборке для честного сравнения.
4. `ModelEvaluator` строит confusion matrix, precision/recall/F1 по каждому
   классу и прогоняет k-fold кросс-валидацию для обеих моделей.
5. Веса сохраняются в `src/ML/mlp_weights.json` (и копия в
   `mlp_weights.trained.json` — эталон для `api/reset_model.php`) и
   `src/ML/model_weights.json` — эти файлы читает `TransportPredictor` во
   время обычной работы приложения (по умолчанию — MLP, с откатом на
   Softmax, если файл весов MLP отсутствует или повреждён).

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
| `TransportPredictor` | Загрузка весов, предсказание транспорта, живое дообучение и сброс модели |
| `ABTestStats` | Файловое хранилище счётчиков A/B-теста MLP vs Softmax |
| `TripAssistantService` | AI-совет по поездке — вызов LLM (Anthropic/OpenAI) либо rule-based fallback |
| `OpenMeteoClient` | Погода по точкам маршрута |
| `OverpassPoiFinder` | Точки интереса рядом с маршрутом |
| `RateLimiter` | Token bucket с нуля: непрерывное пополнение токенов, файловое хранилище с `flock`, fail-open при сбое диска |
| `ClientIdentity` | Определение клиента по IP для rate limiting |
| `RateLimitGuard` | Обвязка над `RateLimiter` для использования в одну строку в начале `api/*.php` — 429 при превышении |
| `Tests\Http\HttpTestServer` | Поднимает настоящий `php -S`, даёт `get()`/`post()` через cURL — основа HTTP-интеграционных тестов |
| `public/assets/js/app.js` | Оркестрация фронтенда: fetch к API, состояние формы, рендер карточек/карты/виджетов фич, обработчики событий |
| `public/assets/js/ui.js` | UI-слой без обращений к API: тема (светлая/тёмная, localStorage), синхронизация темы с картой и графиком модели, вкладки панели результата |
| `public/assets/js/i18n.js` | Локализация интерфейса (RU/EN), хранение выбора в `localStorage` |
| `public/assets/js/ml_boundary.js` | Визуализация decision boundary модели и A/B-виджета через Chart.js |

## Взаимодействие с внешними системами

- **OpenStreetMap Nominatim** — геокодирование городов и автоподсказки
  (см. `NominatimGeocoder`, `api/suggest.php`).
- **OSRM** (router.project-osrm.org) — построение реального маршрута по дорогам,
  точная дистанция и время в пути для автомобиля (см. `OsrmRoadRouter`); публичный
  демо-сервер без SLA, приложение обязано корректно работать и при его недоступности.
- **OpenStreetMap Tile Server через CartoDB Basemaps** — тайлы карты для Leaflet
  (`dark_all`/`light_all`, переключаются вместе с темой интерфейса); данные —
  OpenStreetMap, обязательная атрибуция ODbL сохранена в интерфейсе.
- **Overpass API** — точки интереса рядом с маршрутом (см. `OverpassPoiFinder`).
- **Open-Meteo** — погода по точкам маршрута, без ключа (см. `OpenMeteoClient`).
- **Anthropic Messages API / OpenAI Chat Completions** — опционально, для
  настоящего LLM-вывода AI-ассистента поездки (см. `TripAssistantService`);
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
- Данные не сохраняются между запросами, кроме: кэша геокодирования, состояния
  rate limiter'а, статистики A/B-теста и весов модели после "живого" дообучения.
- Веса модели после `api/learn.php` — общий файл на диске для всех
  посетителей сайта (демо-механика, не изолировано по пользователю/сессии).
