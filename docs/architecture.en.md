# Application Architecture

[🇷🇺 Русская версия](architecture.md)

## Overview

The application is a layered PHP application with clearly scoped
responsibilities per class, rather than a single procedural script. Nothing
executes at the top level of a class file — every class only exposes
methods, so all business logic (aside from HTTP glue) is unit-testable
without booting a web server.

There is no database: the only persistent state lives on disk — the
geocoding cache (`var/geocache/`), rate-limiter state (`var/ratelimit/`),
A/B-test statistics (`var/ab_stats.json`), and the trained model weights
(`src/ML/model_weights.json`, `src/ML/mlp_weights.json`).

The project grew from a single route calculation into a set of independent
features, each its own API endpoint that doesn't block or break the core
flow if unavailable: the AI trip assistant, weather, points of interest,
day planning, the decision-boundary chart, A/B testing, live model
fine-tuning.

## File Structure

```text
bootstrap.php                 # Class autoloader (works without composer install)
composer.json                 # PSR-4 autoloading (optional, for composer dump-autoload)
bin/
  train_model.php             # CLI: trains MLP and softmax, saves weights, prints accuracy for both
public/
  index.php                   # Web UI (form, map, all feature widgets)
  manifest.webmanifest         # PWA manifest (install on mobile as an app)
  service-worker.js            # PWA: offline cache for the app shell
  api/
    route.php                  # POST: main route calculation (geo + optimization + ML + routing + cost)
    day_plan.php                # POST: day-by-day route plan (K-Means)
    suggest.php                  # GET/POST: city autocomplete (proxies Nominatim)
    poi.php                       # POST: points of interest along the route (Overpass)
    weather.php                    # POST: weather at each route point (Open-Meteo)
    assistant.php                   # POST: AI trip note (LLM or rule-based fallback)
    decision_boundary.php            # GET: prediction grid for the decision-boundary chart
    explain.php                       # GET: breaks down a single model prediction ("why this mode of transport")
    ab_stats.php                      # GET: aggregated A/B-test statistics, MLP vs softmax
    feedback.php                      # POST: records whether the model's prediction was correct for this visit
    learn.php                          # POST: one live fine-tuning step for the MLP on a user-corrected example
    reset_model.php                     # POST: resets model weights to the originally trained state
    health.php                           # GET: health check for uptime monitoring and Docker HEALTHCHECK
  assets/
    css/route.css
    js/
      app.js                    # Frontend orchestration: fetch calls, form state, rendering (cards/map/POI/weather/day-plan widgets), event handlers
      ui.js                      # UI-only layer with no API calls: light/dark theme (persisted in localStorage, re-themes the map and the model chart) + result-panel tabs
      i18n.js                    # RU/EN dictionary + DOM translation, choice persisted in localStorage
      ml_boundary.js               # Chart.js visualization of the decision boundary (MLP ⇄ softmax) + A/B widget
    icons/                      # Logo: favicon (svg/ico/png) + PWA icons (192/512/512-maskable) + SVG sources for regeneration
src/
  RoutePlanner.php              # Orchestrator: ties together geocoding, optimization, routing, ML, cost, CO2
  Geocoding/
    GeocoderInterface.php
    NominatimGeocoder.php       # cURL + cache + rate limiting
    FileCache.php
  Routing/
    HaversineCalculator.php     # Great-circle distance (fallback)
    RouteOptimizer.php           # Nearest Neighbor + 2-opt
    RoadRouterInterface.php       # Road-router contract
    OsrmRoadRouter.php             # Real road routing via OSRM
    TravelTimeEstimator.php         # Average-speed travel-time estimate
    CostEstimator.php                # Rough trip cost estimate (fuel/ticket), configurable parameters
    EmissionsEstimator.php            # CO2-emissions estimate by transport mode (averaged coefficients)
  ML/
    ClassifierInterface.php       # Shared MLP/softmax contract
    Dataset.php                  # Synthetic dataset generator
    FeatureEncoder.php            # Shared feature transforms (train + inference)
    MLPClassifier.php              # Neural net (tanh hidden layer + softmax output), backprop from scratch
    SoftmaxClassifier.php          # Linear baseline, gradient-descent training
    ModelEvaluator.php               # Confusion matrix, precision/recall/F1, k-fold cross-validation
    KMeansDaySplitter.php            # Unsupervised: K-Means (Lloyd's algorithm), day-by-day trip plan
    TransportPredictor.php          # Loads weights (MLP, softmax fallback), predicts, live fine-tuning
    ABTestStats.php                  # File-backed counters for the MLP vs softmax A/B test (flock)
    mlp_weights.json                  # Originally trained MLP weights (generated by train_model.php)
    mlp_weights.trained.json           # Backup/reference copy of MLP weights for api/reset_model.php
    model_weights.json                  # Softmax baseline weights
  AI/
    TripAssistantService.php      # AI trip note: LLM (Anthropic/OpenAI) or rule-based fallback
  Weather/
    OpenMeteoClient.php            # Weather at each route point (no key required)
  Geodata/
    OverpassPoiFinder.php          # Gas stations/cafés/restaurants/hotels near the route (no key required)
  Http/
    RateLimiter.php                # Token bucket from scratch, file storage with flock
    ClientIdentity.php              # Client identification by IP (with optional X-Forwarded-For trust)
    RateLimitGuard.php               # One-line wrapper at the top of api/*.php -> 429 on limit exceeded
  Support/
    Logger.php                     # Dependency-free file logger (var/app.log) — fallback transitions
tests/
  run.php                      # Entry point: php tests/run.php
  TestReporter.php              # Minimal PHPUnit-assert replacement
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
    HttpTestServer.php            # Boots a real php -S for HTTP tests
    ApiHttpTest.php                 # HTTP integration tests (405/422/429, error_code, day_plan/assistant/... end-to-end)
.github/
  workflows/ci.yml              # GitHub Actions: lint + tests on PHP 8.1/8.2/8.3, server smoke test, composer audit
Dockerfile                     # php:8.3-apache, document root -> public/
docker-compose.yml              # Local run / simple VPS deployment
.dockerignore
.env.example                    # Environment-variable template for docker-compose
.gitignore
```

## Data Flow (route calculation request)

1. The user enters cities in `public/index.php`; JS (`app.js`) intercepts
   the form submit and issues `fetch('api/route.php', { method: 'POST' })`.
2. `public/api/route.php` builds the dependencies (`NominatimGeocoder`,
   `HaversineCalculator`, `RouteOptimizer`, `TransportPredictor`,
   `OsrmRoadRouter`, `CostEstimator`) and passes them into `RoutePlanner`.
3. `RoutePlanner::plan()`:
   - parses the point string (`;`-separated);
   - geocodes each point (`GeocoderInterface::geocode`); unrecognized cities
     are collected into `skipped` rather than silently dropped;
   - returns an error if fewer than two valid points remain;
   - optimizes point order (`RouteOptimizer::optimize`);
   - attempts real road routing via `RoadRouterInterface::route()`
     (implemented by `OsrmRoadRouter`); if OSRM is unavailable the method
     returns `null` and the app **transparently falls back** to great-circle
     distance (`HaversineCalculator`) and straight lines on the map — no
     crash, no silent error;
   - predicts the mode of transport with the trained model
     (`TransportPredictor::predict`), feeding it either the road distance or
     the great-circle distance, whichever was available;
   - estimates travel time: exact from OSRM (cars only) or an average-speed
     approximation (`TravelTimeEstimator`);
   - estimates trip cost (`CostEstimator`) and CO2 emissions
     (`EmissionsEstimator`) based on the predicted transport mode;
   - builds Google Maps and Yandex Maps links.
4. `api/route.php` returns the result as JSON, including `routing_source`
   (`osrm_road` or `straight_line`) and the model variant assigned to this
   visit for the A/B test (`model_variant`: `mlp` or `softmax`, 50/50) — the
   frontend honestly shows the user which data source and which model were
   used.
5. `app.js` updates the result cards (including time, cost, CO2) and
   redraws the Leaflet map — the route line follows the real road geometry
   (rendered as a multi-hue gradient, with start/end markers styled
   separately from intermediate stops) when geometry is available, or
   straight lines between cities otherwise. The map's tile theme
   (dark/light) and the decision-boundary chart's colors are kept in sync
   with the selected UI theme via `ui.js`.

## Additional API Endpoints (after the main route calculation)

Each of these is a separate, optional frontend call made after the main
route has already been calculated and displayed. None of them can block or
break `api/route.php`:

| Endpoint | Purpose |
|---|---|
| `api/assistant.php` | AI trip note: LLM (Anthropic/OpenAI, if a key is set) or an offline rule-based fallback (`App\AI\TripAssistantService`) |
| `api/weather.php` | Weather at each route point via Open-Meteo, no API key (`App\Weather\OpenMeteoClient`) |
| `api/poi.php` | Points of interest (gas stations/cafés/restaurants/hotels) near the route via the Overpass API, no key (`App\Geodata\OverpassPoiFinder`) |
| `api/day_plan.php` | Splits an already-calculated route into mileage-balanced driving days (`App\ML\KMeansDaySplitter`, unsupervised K-Means) |
| `api/suggest.php` | City autocomplete while typing — proxies Nominatim (the browser can't set the required User-Agent itself) |
| `api/decision_boundary.php` | Computes the model's prediction on a regular [distance × stops] grid for the Chart.js decision-boundary visualization |
| `api/explain.php` | Breaks down one specific model prediction numerically — "why this mode of transport" |
| `api/ab_stats.php` | Returns aggregated A/B-test statistics (MLP vs softmax) from `var/ab_stats.json` |
| `api/feedback.php` | Records a 👍/👎 — whether the model guessed correctly for the variant assigned to this visit |
| `api/learn.php` | Live fine-tuning: one gradient-descent step for the MLP on a user-corrected example |
| `api/reset_model.php` | Resets MLP weights to the originally trained state, undoing the effect of `learn.php` |
| `api/health.php` | Health check with no external calls — for uptime monitoring and Docker `HEALTHCHECK` |

## Route Sharing Without a Database

The "Copy link" button never talks to the server at all: the user-entered
list of cities is Base64-encoded directly into the URL (`?r=<base64>`).
Opening that link decodes the parameter client-side, populates the form, and
triggers the route calculation automatically — the recipient sees the
finished result immediately. This is a deliberate trade-off: without a
database you can't store history or shorten the link, but for sharing one
specific route that's unnecessary — and the resulting solution is about as
simple as it gets, with zero database tables involved.

## Data Flow (model training)

`bin/train_model.php` is a separate process, independent of any web request:

1. `Dataset::generate()` produces labeled examples (distance, number of
   stops, class label) — see `docs/neural_net.md` for an honest discussion
   of this dataset.
2. The data is split into training (80%) and validation (20%) sets.
3. `MLPClassifier::train()` and `SoftmaxClassifier::train()` are trained on
   the same training split — the script prints both models' accuracy on the
   validation set for a fair comparison.
4. `ModelEvaluator` builds a confusion matrix, per-class precision/recall/F1,
   and runs k-fold cross-validation for both models.
5. Weights are saved to `src/ML/mlp_weights.json` (with a copy in
   `mlp_weights.trained.json` — the reference used by `api/reset_model.php`)
   and `src/ML/model_weights.json` — these are the files `TransportPredictor`
   reads during normal operation (MLP by default, falling back to softmax if
   the MLP weights file is missing or corrupted).

## Components and Responsibilities

| Component | Responsibility |
|---|---|
| `RoutePlanner` | Orchestration: geocoding, optimization, routing, ML prediction, time, cost, CO2, links |
| `NominatimGeocoder` + `FileCache` | Geocoding with caching and API rate-limit compliance |
| `HaversineCalculator` | Distance between two points and total route length (fallback) |
| `OsrmRoadRouter` | Real road routing, exact distance/time for cars, map geometry |
| `TravelTimeEstimator` | Average-speed travel-time approximation when exact data isn't available |
| `RouteOptimizer` | Point-order optimization (TSP heuristic: Nearest Neighbor + 2-opt) |
| `CostEstimator` | Rough trip cost estimate (fuel/ticket) with configurable parameters |
| `EmissionsEstimator` | CO2-emissions estimate by transport mode (averaged coefficients) |
| `Dataset` | Training-data generation for the ML model |
| `FeatureEncoder` | Shared logic for turning raw values into model features |
| `MLPClassifier` | Neural net (tanh hidden layer + softmax) — forward/backward pass, backprop from scratch |
| `SoftmaxClassifier` | Training and inference for the linear baseline |
| `ModelEvaluator` | Confusion matrix, per-class precision/recall/F1, k-fold cross-validation |
| `KMeansDaySplitter` | Unsupervised K-Means (Lloyd's algorithm, 1D over cumulative distance) — day plan, preserving city order |
| `TransportPredictor` | Loads weights, predicts transport mode, live fine-tuning and model reset |
| `ABTestStats` | File-backed counters for the MLP vs softmax A/B test |
| `TripAssistantService` | AI trip note — calls an LLM (Anthropic/OpenAI) or falls back to rule-based text |
| `OpenMeteoClient` | Weather at each route point |
| `OverpassPoiFinder` | Points of interest near the route |
| `RateLimiter` | Token bucket from scratch: continuous token replenishment, file storage with `flock`, fail-open on disk errors |
| `ClientIdentity` | Client identification by IP for rate limiting |
| `RateLimitGuard` | One-line wrapper around `RateLimiter` for use at the top of `api/*.php` — 429 on limit exceeded |
| `Logger` | Dependency-free file logger (`var/app.log`) — records fallback transitions (OSRM→Haversine, LLM→rule-based) |
| `Tests\Http\HttpTestServer` | Boots a real `php -S`, exposes `get()`/`post()` via cURL — the basis for HTTP integration tests |
| `public/assets/js/app.js` | Frontend orchestration: API fetch calls, form state, rendering of result cards/map/feature widgets, event handlers |
| `public/assets/js/ui.js` | UI-only layer with no API calls: theme (light/dark, localStorage), keeping the map and model chart in sync with the theme, result-panel tabs |
| `public/assets/js/i18n.js` | UI localization (RU/EN), choice persisted in `localStorage` |
| `public/assets/js/ml_boundary.js` | Decision-boundary and A/B-widget visualization via Chart.js |

## External Integrations

- **OpenStreetMap Nominatim** — city geocoding and autocomplete (see
  `NominatimGeocoder`, `api/suggest.php`).
- **OSRM** (router.project-osrm.org) — real road routing, exact distance and
  travel time for cars (see `OsrmRoadRouter`); a free public demo server
  with no SLA — the app is designed to keep working when it's unreachable.
- **OpenStreetMap tiles via CartoDB Basemaps** — Leaflet map tiles
  (`dark_all`/`light_all`, switching along with the UI theme); the
  underlying data is OpenStreetMap, with the required ODbL attribution kept
  in the UI.
- **Overpass API** — points of interest near the route (see
  `OverpassPoiFinder`).
- **Open-Meteo** — weather along the route, no key required (see
  `OpenMeteoClient`).
- **Anthropic Messages API / OpenAI Chat Completions** — optional, for a
  real LLM-generated AI trip note (see `TripAssistantService`); without a
  key, an honest rule-based fallback is used instead.
- **Google Maps / Yandex Maps** — generates links to the finished route
  (opens in a new tab, not embedded).

## Constraints and Assumptions

- Point-order optimization is a heuristic (Nearest Neighbor + 2-opt), not an
  exact algorithm: for a small number of points (up to ~20) the gap to the
  true optimum is negligible in practice, while exact search (n!) would be
  prohibitively slow.
- The first point the user enters always stays the route's starting point —
  a deliberate decision (the traveler starts from wherever they physically
  are), not an algorithmic limitation.
- The transport-prediction model has no access to the real road network,
  traffic, or schedules — only distance and number of stops.
- No data persists across requests except: the geocoding cache,
  rate-limiter state, A/B-test statistics, and model weights after live
  fine-tuning.
- Model weights after `api/learn.php` are a single shared file on disk for
  all site visitors (a demo mechanic — not isolated per user/session).
