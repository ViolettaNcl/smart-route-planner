<div align="center">

# Smart Route Planner

### Multi-city trip planner with TSP route optimization, a neural network trained from scratch, and real road routing

[🇷🇺 Русская версия](README.ru.md)

<p>
  <a href="https://smart-route-planner-wiwk.onrender.com"><img src="https://img.shields.io/badge/demo-live-brightgreen?style=flat-square&logo=render&logoColor=white" alt="Live demo"></a>
  <img src="https://github.com/violettancl/smart-route-planner/actions/workflows/ci.yml/badge.svg" alt="CI">
  <img src="https://img.shields.io/badge/Docker-ready-2496ED?style=flat-square&logo=docker&logoColor=white" alt="Docker">
  <img src="https://img.shields.io/badge/PHP-8.1+-777BB4?style=flat-square&logo=php&logoColor=white" alt="PHP 8.1+">
  <img src="https://img.shields.io/badge/UI-RU_%2F_EN-4c9aff?style=flat-square" alt="Bilingual UI">
  <img src="https://img.shields.io/badge/ML-MLP_%2B_Backprop_from_scratch-orange?style=flat-square" alt="Neural net from scratch">
  <img src="https://img.shields.io/badge/tests-132_passing-success?style=flat-square" alt="132 tests passing">
  <img src="https://img.shields.io/badge/license-MIT-green?style=flat-square" alt="MIT License">
</p>

**[Live demo →](https://smart-route-planner-wiwk.onrender.com)**
(free-tier hosting: the first request after idle takes 30–50s to wake up, then runs normally)

</div>

---

## Contents

- [Overview](#overview)
- [Highlights](#highlights)
- [Features](#features)
- [Quick Start](#quick-start)
- [Tech Stack](#tech-stack)
- [Documentation](#documentation)
- [Testing](#testing)
- [Known Limitations](#known-limitations)
- [License](#license)

---

## Overview

Enter a list of cities and the app geocodes them, **works out the most efficient
visiting order** (rather than driving them in the order they were typed),
**predicts a suitable mode of transport with a neural network trained from
scratch**, renders the trip on an **interactive map**, and generates an
**AI travel note** (rest stops, overnight stays, weather) via an LLM.

This is a full rewrite of an earlier procedural script: a single file with
hardcoded weights became a layered PHP application with dependency injection,
a TSP heuristic replacing "visit in input order," and a neural network trained
with backpropagation replacing hand-picked classification weights.

## Highlights

What this project is meant to demonstrate:

- **A neural network implemented from scratch** — forward/backward pass,
  gradient descent, cross-entropy loss, all in plain PHP arrays and loops, no
  ML framework. Benchmarked honestly against a linear baseline with a
  confusion matrix, per-class precision/recall/F1, and 5-fold cross-validation
  — see [`docs/neural_net.md`](docs/neural_net.md) for the full write-up,
  including the case where the linear model wins.
- **A classic algorithms problem solved properly** — TSP via Nearest Neighbor
  + 2-opt, not a brute-force permutation.
- **Engineering maturity beyond the ML** — 132 automated tests (unit +
  HTTP-integration), a from-scratch rate limiter (token bucket, not a naive
  fixed window), a CI matrix across three PHP versions, and a
  fail-open-by-design architecture where any external service (routing,
  weather, POIs, LLM) can go down without breaking the core flow.

## Features

### ML / AI

- **Multi-layer perceptron with backpropagation from scratch** — one hidden
  layer (`tanh`), softmax output, forward and backward pass hand-written in
  PHP. Trained and honestly compared against a linear softmax-regression
  baseline (`bin/train_model.php` prints both accuracies side by side).
- **Rigorous model evaluation** — confusion matrix, per-class precision /
  recall / F1 (accuracy alone hides class imbalance), and 5-fold
  cross-validation instead of a single random train/val split
  (`App\ML\ModelEvaluator`).
- **AI trip assistant** — after a route is calculated, an LLM (Anthropic or
  OpenAI, if an API key is configured) writes a short human note: where to
  rest, whether an overnight stop makes sense on a long leg, what to watch
  for in the weather. Without a key it falls back to a rule-based offline
  generator — the UI honestly labels which one produced the text.
- **Interactive decision-boundary chart** — a Chart.js visualization of how
  the classifier splits the "distance × number of stops" feature space into
  walk/car/bus, with an MLP ⇄ softmax toggle for a direct visual comparison.
- **Day-by-day trip planner (K-Means)** — for long routes, a from-scratch
  K-Means implementation (Lloyd's algorithm) splits the trip into
  mileage-balanced driving days without reordering the cities. Unlike the
  MLP/softmax classifiers, this is **unsupervised learning** — a genuinely
  different ML problem, with no labeled examples at all
  (`App\ML\KMeansDaySplitter`, `api/day_plan.php`).

### Geodata & Planning

- **Geocoding** via OpenStreetMap Nominatim, with a disk cache and rate-limit
  compliance.
- **Route order optimization** — Nearest Neighbor construction + 2-opt local
  search (the standard combination for practical TSP instances).
- **Real road routing** via OSRM — actual road geometry, not straight lines
  between cities, with a transparent fallback to great-circle distance if the
  routing service is unavailable.
- **Travel-time estimation** — exact driving time from OSRM, an
  average-speed approximation for walking/public transit.
- **Points of interest along the route** (gas stations, cafés, restaurants,
  hotels) via the free Overpass API — no API key required.
- **Weather along the route** via Open-Meteo (no key) — flags heavy rain,
  extreme heat, freezing temperatures, or storms at each stop.
- **Interactive Leaflet map** — numbered markers, real road geometry, no map
  API key required.
- **Shareable route links with no database** — the entire route is encoded
  directly into the URL; opening the link recalculates and displays the trip
  immediately.
- Ready-made links to Google Maps and Yandex Maps.
- **Trip cost estimate** — fuel cost (consumption × distance × price/liter)
  or an approximate ticket price for public transit, with parameters
  editable in the UI.
- **PWA** — installable on mobile, with a service worker caching the app
  shell for offline use.
- **City autocomplete** — live search via Nominatim, keyboard-navigable.
- **Fully localized UI (Russian / English)** — one-click switch, no page
  reload, choice persisted in `localStorage`
  (`public/assets/js/i18n.js`).
- **Light / dark theme** — persisted between visits; the map tiles and the
  decision-boundary chart re-theme along with the rest of the UI
  (`public/assets/js/ui.js`).
- No page reloads anywhere — every calculation is a `fetch` call against a
  JSON API.
- **Live MLP vs. softmax A/B test** — each visitor is randomly (50/50)
  assigned one of the two models for the whole session; after a route is
  calculated, the visitor can flag whether the prediction was right, and
  aggregate accuracy for both models is tracked in `var/ab_stats.json` and
  shown in the UI.
- **132 automated tests** — 106 unit tests (fake dependencies, no I/O) and 26
  HTTP integration tests (see [Testing](#testing)).

### Engineering

- **Token-bucket rate limiter, written from scratch** — continuous token
  replenishment rather than a naive fixed-window counter (which has a
  well-known boundary exploit). Protects the free Nominatim/Overpass/
  Open-Meteo quotas and the live-learning demo endpoints from abuse
  (`App\Http\RateLimiter`).
- **HTTP integration tests, separate from unit tests** — `tests/Http/` boots
  a real `php -S` server and exercises the actual `api/*.php` endpoints over
  HTTP: request parsing, status codes (405/422/429), `error_code` payloads,
  and rate-limiter behavior under a live request — none of which unit tests
  alone can verify.
- **CI** ([`.github/workflows/ci.yml`](.github/workflows/ci.yml)) — on every
  push/PR: `php -l` across the codebase, the full test suite on PHP
  8.1/8.2/8.3, a smoke test booting the built-in server, `composer audit`.
- **Automated Docker image build**
  ([`.github/workflows/docker-publish.yml`](.github/workflows/docker-publish.yml))
  on every push to `main`.
- **Dependabot** ([`.github/dependabot.yml`](.github/dependabot.yml)) —
  weekly checks for GitHub Actions version updates.

## Quick Start

The fastest option is the **[live demo](https://smart-route-planner-wiwk.onrender.com)** — no setup required.

To run it locally: no database, Composer is optional. The trained model
weights ship in the repository (`src/ML/mlp_weights.json`); you don't need
to retrain anything before first use.

```bash
# 1. Start PHP's built-in server (document root is public/)
php -S localhost:8000 -t public

# 2. Open in a browser
http://localhost:8000
```

To retrain the model yourself (for example, after editing `Dataset.php`):

```bash
php bin/train_model.php
```

The AI trip assistant and points-of-interest/weather features work
out of the box with no configuration (offline fallback for the AI text,
free Overpass/Open-Meteo APIs for geodata). For the assistant to call a real
LLM instead of the rule-based fallback, set an environment variable before
starting the server:

```bash
export ANTHROPIC_API_KEY=sk-ant-...   # or OPENAI_API_KEY=sk-...
php -S localhost:8000 -t public
```

For XAMPP setup (no terminal) and deployment details, see
[`docs/setup_guide.md`](docs/setup_guide.md).

### Or with Docker (no local PHP install needed)

```bash
cp .env.example .env      # API keys are optional, see comments in the file
chmod -R 777 var          # see docs/setup_guide.md for why this is needed with a bind mount
docker compose up --build
```

Open `http://localhost:8080`. The same image is suitable for a VPS deployment
— see [`docs/setup_guide.md`](docs/setup_guide.md) for details.

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.1+, OOP, PSR-4 (`composer.json` with a fallback autoloader) |
| Geocoding | OpenStreetMap Nominatim, cURL, file cache |
| Routing | OSRM (real roads) with a Haversine + Nearest Neighbor/2-opt fallback |
| Machine learning | MLP (hidden layer + backprop from scratch) and softmax regression — gradient descent, cross-entropy loss; K-Means (Lloyd's algorithm, from scratch) — unsupervised day-plan clustering |
| AI assistant | Anthropic Messages API / OpenAI Chat Completions (optional), rule-based offline fallback |
| Geodata | Overpass API (points of interest), Open-Meteo (weather) — both keyless |
| Frontend | Vanilla JS (fetch API), Leaflet.js, Chart.js, CSS custom properties (theming) |
| Testing | A minimal custom test runner (no dependencies); HTTP integration tests via `php -S` |
| Rate limiting | Token bucket from scratch (`App\Http\RateLimiter`), file storage with `flock` |
| CI/CD | GitHub Actions — lint + tests on PHP 8.1/8.2/8.3, server smoke test, `composer audit`, automated Docker build, Dependabot |
| Deployment | Docker + docker-compose (`php:8.3-apache`); [live demo on Render](https://smart-route-planner-wiwk.onrender.com); works on plain shared hosting/XAMPP without Docker too |

## Documentation

| Document | Description |
|---|---|
| [`docs/architecture.md`](docs/architecture.md) ([EN](docs/architecture.en.md)) | Architecture, data flow, class structure |
| [`docs/neural_net.md`](docs/neural_net.md) ([EN](docs/neural_net.en.md)) | Model design, training, and evaluation metrics |
| [`docs/business_analysis.md`](docs/business_analysis.md) ([EN](docs/business_analysis.en.md)) | Use cases and business logic |
| [`docs/setup_guide.md`](docs/setup_guide.md) ([EN](docs/setup_guide.en.md)) | Installation: XAMPP, Docker, or the built-in PHP server |

## Testing

```bash
php tests/run.php
```

Covers: distance calculation (Haversine), route-order optimization (TSP
heuristic), MLP/softmax training and accuracy, rigorous evaluation (confusion
matrix, precision/recall/F1, k-fold cross-validation), unsupervised day-plan
clustering (K-Means: balance, order preservation, determinism), the rate
limiter (token bucket across clients, replenishment over time, fail-open on
disk errors), travel-time and cost estimation, full `RoutePlanner`
integration, and HTTP integration tests against a real `php -S` server
(405/422/429, `error_code`, day-plan/decision-boundary/explain/assistant
end-to-end) — 132 tests in total. Runs automatically in CI on every push
(see [`.github/workflows/ci.yml`](.github/workflows/ci.yml)).

## Known Limitations

- Route-order optimization is a heuristic (Nearest Neighbor + 2-opt), not a
  guaranteed exact optimum — for 3–20 stops it lands very close to it in
  milliseconds.
- OSRM's public demo server only exposes the `driving` profile, so exact
  travel time is available for cars only; walking/transit time is an
  average-speed estimate.
- OSRM is a free public demo server with no SLA; if it's unreachable, the app
  transparently falls back to great-circle distance (visibly labeled in the
  UI).
- The ML model is trained on a **synthetic** dataset — there's no accumulated
  history of real user decisions to train on. See the honest discussion in
  [`docs/neural_net.md`](docs/neural_net.md).
- The MLP doesn't dramatically outperform the linear softmax baseline on
  these two features — the gap is within statistical noise on the validation
  split. This is documented with numbers across several seeds in
  [`docs/neural_net.md`](docs/neural_net.md): the value of the MLP here is
  the architecture and the room for future non-linear features, not a
  current accuracy jump.
- The AI trip note is a rule-based offline text unless `ANTHROPIC_API_KEY` /
  `OPENAI_API_KEY` is configured — the UI labels which one produced it.
- Overpass (points of interest) and Open-Meteo (weather) are free public
  services with no SLA; if unavailable, the app simply omits that panel
  without breaking the main route calculation.
- Route history isn't persisted (aside from link sharing) — a candidate for
  a future iteration: SQLite storage, accounts, GPX export.
- Trip cost is a rough estimate (`src/Routing/CostEstimator.php`), not an
  exact figure — real fuel/ticket prices vary heavily by region and carrier.
  Currency is fixed (₽), but the calculation parameters are editable in the
  UI.
- The rate limiter identifies clients by IP (with optional
  `X-Forwarded-For` trust) — sufficient for a single server, but worth
  reviewing behind a load balancer/CDN (see the docblock on
  `App\Http\ClientIdentity`). Limiter state is a local file, not shared
  across multiple servers in a horizontally scaled deployment.
- City autocomplete and the route calculation itself require internet access
  (Nominatim/OSRM) — without it, the form reports the issue instead of
  failing silently.
- Localization (RU/EN) covers the whole UI, but server error messages are
  only translated for known `error_code` values; unrecognized errors are
  shown as-is (in Russian).
- The K-Means day plan balances days **by driving distance**, not by actual
  lodging availability in a given city — it's a "roughly where a sensible
  driving day ends" hint, not a hotel booking. The clustering always
  respects the original route order (a day can't "jump" backward) — see the
  docblock on `App\ML\KMeansDaySplitter`.
- The **free Render tier** (live demo) sleeps after 15 minutes idle — the
  first request after that takes 30–50s to wake up; it also has no
  persistent disk, so the geocoding cache and A/B stats reset on every new
  deploy (route calculation itself is unaffected).
- The PWA splash-screen color (`manifest.webmanifest`, `theme_color` /
  `background_color`) is fixed to the dark theme — the in-app light/dark
  toggle doesn't affect it, since it's rendered before any JS runs.

## License

[MIT](LICENSE)
