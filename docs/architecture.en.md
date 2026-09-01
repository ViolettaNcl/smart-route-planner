# Application Architecture

[🇷🇺 Русская версия](architecture.md)

## Overview

The application is a layered PHP application with clearly scoped
responsibilities per class, rather than a single procedural script. Nothing
executes at the top level of a class file — every class only exposes
methods, so all business logic (aside from HTTP glue) is unit-testable
without booting a web server.

There is no database. Model weights and the reproducible training report live
in the repository (`src/ML/model_weights.json`, `src/ML/mlp_weights.json`,
`src/ML/training_report.json`), while mutable state (cache, rate limits, A/B
stats, logs, and the anonymous correction queue) goes through
`RuntimeStorage`: `var/` locally and ephemeral `/tmp` on Vercel.

The project grew from a single route calculation into a set of independent
features, each its own API endpoint that doesn't block or break the core
flow if unavailable: the AI trip assistant, weather, points of interest,
day planning, the explainable ML Lab, A/B testing, and a safe feedback queue.

## File Structure

```text
bootstrap.php                 # Class autoloader (works without composer install)
composer.json                 # PSR-4 autoloading (optional, for composer dump-autoload)
vercel.json                   # PHP 8.3 runtime and Vercel Functions routes
api/index.php                 # Single Vercel front controller for every API route
bin/
  train_model.php             # CLI: trains MLP/softmax and saves weights, metrics, and snapshots
  review_feedback.php         # CLI: reviewed allow-list, anomaly checks, holdout gate, promotion
  model_admin.php             # CLI: model-registry status and rollback
public/
  index.php                   # Web UI (form, map, all feature widgets)
  manifest.webmanifest         # PWA manifest (install on mobile as an app)
  service-worker.js            # PWA: offline cache for the app shell
  api/
    route.php                  # POST: main route calculation (geo + optimization + ML + routing + cost)
    day_plan.php                # POST: day-by-day route plan (K-Means)
    suggest.php                  # GET: configured search; public Nominatim stays submit-only
    poi.php                       # POST: points of interest along the route (Overpass)
    weather.php                    # POST: weather at each route point (Open-Meteo)
    assistant.php                   # POST: AI trip note (LLM or rule-based fallback)
    decision_boundary.php            # GET: prediction grid for the decision-boundary chart
    explain.php                       # GET: breaks down a single model prediction ("why this mode of transport")
    model_insights.php                # GET: personal explanation, comparison, counterfactuals, ranking
    model_quality.php                 # GET: test metrics, calibration, Model Card, training snapshots
    ab_stats.php                      # GET: aggregated A/B-test statistics, MLP vs softmax
    feedback.php                      # POST: records whether the model's prediction was correct for this visit
    learn.php                          # POST: queues an anonymous correction; never mutates production weights
    reset_model.php                    # POST: admin-token-protected reset to the reviewed baseline
    health.php                           # GET: health check for uptime monitoring and Docker HEALTHCHECK
  assets/
    css/route.css
    js/
      app.js                    # Frontend orchestration: fetch calls, form state, rendering (cards/map/POI/weather/day-plan widgets), event handlers
      route-editor.js           # Structured stops: reorder/reverse/map-pick/demo
      product.js                # Alternatives, maneuvers, history, export, mobile sheet
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
    ModelEvaluator.php               # Confusion matrix, F1, log loss, Brier, calibration, k-fold CV
    KMeansDaySplitter.php            # Unsupervised: K-Means (Lloyd's algorithm), day-by-day trip plan
    TransportPredictor.php          # Loads versioned weights and performs read-only inference
    ABTestStats.php                  # File-backed counters for the MLP vs softmax A/B test (flock)
    ModelInsightService.php          # Local influence, counterfactuals, neighbours, option ranking
    ModelQualityService.php          # Separate validation/test metrics and Model Card
    FeedbackStore.php                # Append-only anonymous correction queue and archive
    ModelRegistry.php                # CLI-only model promotion and rollback
    mlp_weights.json                  # Originally trained MLP weights (generated by train_model.php)
    mlp_weights.trained.json           # Backup/reference copy of MLP weights for api/reset_model.php
    model_weights.json                  # Softmax baseline weights
    training_report.json                # Loss curves and boundary snapshots tied to weights by SHA-256
  AI/
    TripAssistantService.php      # AI note: Vercel AI Gateway / Anthropic / OpenAI / fallback
  Weather/
    OpenMeteoClient.php            # Weather at each route point (no key required)
  Geodata/
    OverpassPoiFinder.php          # Gas stations/cafés/restaurants/hotels near the route (no key required)
  Http/
    RateLimiter.php                # Token bucket from scratch, file storage with flock
    ClientIdentity.php              # Client identification by IP (with optional X-Forwarded-For trust)
    RateLimitGuard.php               # One-line wrapper at the top of api/*.php -> 429 on limit exceeded
  Support/
    RuntimeStorage.php             # var/ locally, writable /tmp on Vercel
    Logger.php                     # Dependency-free file logger — fallback transitions
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

1. The user edits independent stops in `route-editor.js`; `app.js` sends
   `stops_json` (label + optional lat/lon), legacy `points`, and the
   optimisation flag to `api/route.php`.
2. `public/api/route.php` builds the dependencies (`NominatimGeocoder`,
   `HaversineCalculator`, `RouteOptimizer`, `TransportPredictor`,
   `OsrmRoadRouter`, `CostEstimator`) and passes them into `RoutePlanner`.
3. `RoutePlanner::planStops()` (legacy `plan()` remains a compatible wrapper):
   - normalizes up to 12 stops with stable internal IDs;
   - geocodes each point (`GeocoderInterface::geocode`); unrecognized cities
     are collected into `skipped` rather than silently dropped;
   - returns an error if fewer than two valid points remain;
   - preserves start/finish and optimizes only intermediate points;
   - attempts real road routing via `RoadRouterInterface::route()`
     (implemented by `OsrmRoadRouter`): it checks a fresh file cache first,
     tries configured OSRM-compatible endpoints in order, and may use a recent
     stale copy during an outage; only after the whole chain fails does the app
     **transparently fall back** to great-circle distance
     (`HaversineCalculator`) and straight lines on the map;
   - predicts the mode of transport with the trained model
     (`TransportPredictor::predict`), feeding it either the road distance or
     the great-circle distance, whichever was available;
   - estimates travel time: exact from OSRM (cars only) or an average-speed
     approximation (`TravelTimeEstimator`);
   - estimates trip cost (`CostEstimator`) and CO2 emissions
     (`EmissionsEstimator`) based on the predicted transport mode;
   - builds Google Maps and Yandex Maps links.
4. `api/route.php` returns the result as JSON, including `routing_source`,
   `routing_provider`, `routing_cached`, `routing_cache_status`, and
   `routing_failover_used`, plus the model variant assigned to this
   visit for the A/B test (`model_variant`: `mlp` or `softmax`, 50/50) — the
   frontend honestly shows the user which data source and which model were
   used.
5. `app.js` updates the result cards (including time, cost, CO2) and starts a
   MapLibre GL JS route scene: the camera frames the trip, the line is drawn
   progressively from the real road geometry, and the compact summary is then
   revealed. `ui.js` keeps the OpenFreeMap Fiord/Liberty style in sync with the UI theme;
   2D and 3D switch without recreating the map. Extruded buildings,
   Mapterhorn terrain/hillshade, globe atmosphere, and lighting are independent
   progressive enhancements. If WebGL or the base style fails, an SVG view is
   generated from the API response coordinates without affecting route data.
   Local shell assets carry an explicit UI version, while service-worker
   navigation is network-first so a deployed interface cannot remain pinned
   behind a previous PWA cache.

## Additional API Endpoints (after the main route calculation)

Each of these is a separate, optional frontend call made after the main
route has already been calculated and displayed. None of them can block or
break `api/route.php`:

| Endpoint | Purpose |
|---|---|
| `api/assistant.php` | AI note: Vercel AI Gateway (OIDC), Anthropic/OpenAI, or offline fallback (`App\AI\TripAssistantService`) |
| `api/weather.php` | Weather at each route point via Open-Meteo, no API key (`App\Weather\OpenMeteoClient`) |
| `api/poi.php` | Points of interest (gas stations/cafés/restaurants/hotels) near the route via the Overpass API, no key (`App\Geodata\OverpassPoiFinder`) |
| `api/day_plan.php` | Splits an already-calculated route into mileage-balanced driving days (`App\ML\KMeansDaySplitter`, unsupervised K-Means) |
| `api/suggest.php` | Search through an explicitly configured compatible endpoint; public Nominatim returns `submit_only` without autocomplete requests |
| `api/decision_boundary.php` | Computes the model's prediction on a regular [distance × stops] grid for the Chart.js decision-boundary visualization |
| `api/explain.php` | Breaks down one specific model prediction numerically — "why this mode of transport" |
| `api/model_insights.php` | Returns both models' probabilities, local feature influence, nearest class switch, similar examples, neural activations, and transparent option ranking |
| `api/model_quality.php` | Returns separate validation/test metrics, confusion matrix, F1, log loss, Brier, calibration, Model Card, and training snapshots |
| `api/ab_stats.php` | Returns aggregated A/B-test statistics (MLP vs softmax) from `var/ab_stats.json` |
| `api/feedback.php` | Records a 👍/👎 — whether the model guessed correctly for the variant assigned to this visit |
| `api/learn.php` | Queues an anonymous correction; a single HTTP request never mutates production weights |
| `api/reset_model.php` | `X-Model-Admin-Token`-protected baseline restore; it is absent from the public UI |
| `api/health.php` | Health check with no external calls — for uptime monitoring and Docker `HEALTHCHECK` |

## Route Sharing Without a Database

The "Copy link" button never talks to the server: structured stops and their
coordinates are Base64-encoded directly into the URL (`?s=<base64>`), while
legacy `?r=<base64>` city-list links remain supported. Opening the link
restores the editor client-side and triggers calculation automatically — the
recipient sees the finished result immediately. This is a deliberate trade-off: without a
database you cannot synchronize history across devices or shorten the link.
Local history/favourites remain in the browser; GeoJSON/GPX/KML exports are
generated client-side without uploading the route.

## Data Flow (model training)

`bin/train_model.php` is a separate process, independent of any web request:

1. `Dataset::generate()` produces labeled examples (distance, number of
   stops, class label) — see `docs/neural_net.md` for an honest discussion
   of this dataset.
2. The data is split into train/validation/test (80/10/10); test remains
   untouched until the final evaluation.
3. `MLPClassifier::train()` and `SoftmaxClassifier::train()` are trained on
   the same training split — the script prints both models' accuracy on the
   validation and test sets for a fair comparison.
4. `ModelEvaluator` builds a confusion matrix, per-class precision/recall/F1,
   and runs k-fold cross-validation for both models.
5. Weights are saved to `src/ML/mlp_weights.json` (with an administrative
   baseline copy in `mlp_weights.trained.json`) and
   `src/ML/model_weights.json`; loss curves and six decision-boundary
   snapshots are written to `training_report.json` with SHA-256 versions.
   `TransportPredictor` reads these weights during normal operation (MLP by
   default, falling back to softmax).

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
| `TransportPredictor` | Loads versioned weights and performs read-only transport inference |
| `ModelInsightService` | Personal explanation, sensitivity, counterfactuals, similar examples, and ranking |
| `ModelQualityService` | Validation/test evaluation, calibration, Model Card, and training provenance |
| `FeedbackStore` | Deduplicated append-only anonymous correction queue and reviewed-event archive |
| `ModelRegistry` | CLI-only promotion and rollback of reviewed model versions |
| `ABTestStats` | File-backed counters for the MLP vs softmax A/B test |
| `TripAssistantService` | AI note — Gateway with OIDC, direct providers, then rule-based fallback |
| `OpenMeteoClient` | Weather at each route point |
| `OverpassPoiFinder` | Points of interest near the route |
| `RateLimiter` | Token bucket from scratch: continuous token replenishment, file storage with `flock`, fail-open on disk errors |
| `ClientIdentity` | Client identification by IP for rate limiting |
| `RateLimitGuard` | One-line wrapper around `RateLimiter` for use at the top of `api/*.php` — 429 on limit exceeded |
| `RuntimeStorage` | Shared path for cache, rate limits, logs, A/B state, and feedback: `var/` locally, `/tmp` on Vercel |
| `Logger` | Dependency-free file logger — records fallback transitions (OSRM→Haversine, LLM→rule-based) |
| `Tests\Http\HttpTestServer` | Boots a real `php -S`, exposes `get()`/`post()` via cURL — the basis for HTTP integration tests |
| `public/assets/js/app.js` | Frontend orchestration: API fetch calls, form state, rendering of result cards/map/feature widgets, event handlers |
| `public/assets/js/route-editor.js` | Structured stop editor and map-coordinate picking |
| `public/assets/js/product.js` | Alternatives, navigation, history/favourites, exports, mobile bottom sheet |
| `public/assets/js/ui.js` | UI-only layer with no API calls: theme (light/dark, localStorage), keeping the map and model chart in sync with the theme, result-panel tabs |
| `public/assets/js/i18n.js` | UI localization (RU/EN), choice persisted in `localStorage` |
| `public/assets/js/ml_boundary.js` | Decision-boundary and A/B-widget visualization via Chart.js |

## External Integrations

- **OpenStreetMap Nominatim** — explicit geocoding on route submission; the
  public endpoint is not used for autocomplete. A separately configured
  compatible endpoint may serve `api/suggest.php`.
- **OSRM** (router.project-osrm.org) — real road routing, exact distance and
  travel time for cars (see `OsrmRoadRouter`); a free public demo server
  with no SLA — the app is designed to keep working when it's unreachable.
- **OpenFreeMap + MapLibre GL JS** — a keyless vector 2D/3D map backed by
  OpenStreetMap/OpenMapTiles data, with the required attribution kept in the UI.
- **Overpass API** — points of interest near the route (see
  `OverpassPoiFinder`).
- **Open-Meteo** — weather along the route, no key required (see
  `OpenMeteoClient`).
- **Vercel AI Gateway / Anthropic / OpenAI** — Gateway with OIDC is used on
  Vercel, with direct provider keys retained as fallbacks; without any
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
- No data persists across requests except the geocoding cache, rate-limiter
  state, aggregate A/B statistics, and anonymous correction queue. On Vercel,
  that runtime state is ephemeral and can disappear after a cold start or
  deployment.
- Public `api/learn.php` never changes weights. Promotion is CLI-only through
  `bin/review_feedback.php`, after reviewed allow-list, anomaly checks, and a
  holdout gate; `bin/model_admin.php` keeps rollback available.
