# Business Analysis and Use Cases

[🇷🇺 Русская версия](business_analysis.md)

## Purpose

The service helps a user quickly plan a trip across several cities: not just
estimate the distance, but get a **ready-made efficient visiting order**, an
honest estimate of distance, time and cost, a suitable-transport suggestion,
weather and points of interest along the way, a day-by-day plan for longer
trips, and a human-readable AI note — all on an interactive map.

Useful when:

- planning a multi-city trip where the visiting order isn't obvious upfront;
- deciding whether it's worth driving or better to take public transport;
- estimating a trip budget (fuel/tickets) and its carbon footprint (CO2);
- planning a multi-day drive — where it makes sense to stop for the night;
- quickly checking the weather or nearby gas stations/cafés/hotels along the
  way, without opening separate services for each;
- getting a quick visual sense of the route on a map before opening Google
  Maps or Yandex Maps for turn-by-turn navigation.

## Primary Role

- **User** — the person planning a trip: enters the list of cities and
  (optionally) configures the cost-calculation parameters.

## Core Business Processes

### 1. Route input

1. The user edits independent stop rows: add, remove, reorder, reverse, or
   pick a coordinate on the map.
2. The system preserves start/finish, normalizes up to 12 stops, and geocodes
   only entries without coordinates when the route is explicitly submitted.

### 2. City geocoding

1. Each city is sent to Nominatim (with caching — a repeated request for the
   same city doesn't hit the network again).
2. If coordinates are found, the point is included in the route.
3. If a city isn't recognized, it's explicitly added to a "skipped" list and
   shown to the user as a warning — not silently dropped.
4. If fewer than two valid points remain, the user gets a clear error
   instead of an empty or malformed result.

### 3. Visiting-order optimization

1. The system builds an initial route with a "nearest neighbor" heuristic.
2. Local search (2-opt) removes crossings and further shortens the route.
3. The first point entered by the user always stays the starting point — the
   route never reorders the user's point of departure.

### 4. Distance calculation and road routing

1. The system attempts to build a real road route via OSRM — getting exact
   distance, driving time, and route geometry for the map.
2. If the routing service is unavailable, the system transparently falls
   back to great-circle distance (haversine formula) and straight lines
   between points — the user sees an explicit label of which data source was
   used.

### 5. Transport selection, time, cost, and CO2 emissions

1. The trained model receives the total distance (road distance if
   available, otherwise great-circle) and the number of stops; it selects a
   transport mode and reports the prediction's "confidence."
2. Each visit is randomly (50/50) assigned one of two models — MLP or
   Softmax — for continuous production A/B testing of prediction quality
   (see item 9).
3. Travel time: exact — from OSRM — if a car is predicted; for walking and
   public transit, an approximation based on average speed, explicitly
   marked with a "≈" in the UI.
4. Trip cost is estimated based on the predicted transport (fuel ×
   consumption × price per liter for cars, or ticket price per km for
   public transit) — calculation parameters can be edited directly in the
   UI.
5. CO2 emissions are estimated using averaged coefficients per
   passenger-kilometer for the predicted transport mode — an illustrative
   comparison, not an accounting-grade figure.

### 6. Route visualization

1. Points and the path between them are shown on an interactive MapLibre
   vector map (keyless 2D/3D modes) — the route line follows real roads when
   OSRM geometry is available.
2. Ready-made links to Google Maps and Yandex Maps are generated for
   turn-by-turn navigation.

### 7. Route sharing

1. The user can copy a link containing structured stops and coordinates (no
   server-side storage or database).
2. The recipient opens the link and immediately sees the calculated route —
   the calculation runs automatically on page load.

### 8. Weather and points of interest along the route

1. On button press, the user can pull up weather for each route point
   (Open-Meteo) — with warnings for heavy rain, extreme heat, freezing
   temperatures, or storms.
2. A separate button surfaces points of interest near the route (gas
   stations, cafés, restaurants, hotels) via the Overpass API — "smart stops"
   for long trips.
3. Both features are optional, separate calls made after the main
   calculation: they don't consume free-API quotas unnecessarily and don't
   block or break the main flow if the service is unavailable.

### 9. AI day planner and AI trip note

1. For long routes, K-Means (unsupervised, no labels) splits the trip into
   mileage-balanced driving days without disturbing city order — a hint at
   where it's sensible to end a day's drive, not a hotel booking.
2. Separately, an LLM (Anthropic/OpenAI, if an API key is set) generates a
   human-readable comment on the trip: where to rest, whether an overnight
   stop makes sense on a long leg, what to watch for in the weather. Without
   an API key, an offline rule-based fallback runs instead — the UI
   honestly labels which one produced the text.

### 10. A/B testing, explainability, and safe feedback

1. Each visit is randomly assigned a model (MLP or Softmax) for the whole
   session; after a route is calculated, the user can flag 👍/👎 on whether
   it guessed correctly — statistics for both models accumulate and are
   visible directly in the UI (decision-boundary chart → A/B test).
2. ML Lab shows all class probabilities, local feature influence, the nearest
   decision change, MLP/softmax comparison, similar examples, test metrics,
   calibration, a Model Card, and reproducible training snapshots.
3. A correction only enters an anonymous queue. A candidate is trained in a
   reviewed batch; promotion requires the holdout gate to pass and preserves
   rollback. The public UI exposes no production-weight mutation or reset.

### 11. Localization and installation as an app

1. The entire UI switches between 🇷🇺 Russian and 🇬🇧 English with one
   button, no page reload; the choice persists between visits.
2. The site can be installed on mobile as a PWA (manifest + service worker
   with an offline cache for the app shell).

## Non-Functional Requirements

- **Deployment simplicity**: no database required; Composer is only
  optional (for generating the autoloader) — the built-in autoloader works
  without it.
- **Fault tolerance**: unrecognized cities, or unavailability of OSRM,
  Overpass, Open-Meteo, or the LLM, don't break the core flow — every
  feature degrades independently and honestly labels this in the UI.
- **UI responsiveness**: the calculation and every additional feature run
  via `fetch`, with no page reloads.
- **Overload protection**: a custom rate limiter (token bucket) throttles
  requests to the free external APIs and feedback endpoints,
  preventing a single client from exhausting the shared limit for everyone.

## Delivered Improvements (from the original roadmap)

- ✅ Visiting-order optimization (TSP heuristic) — done.
- ✅ A trained model instead of hand-picked weights — done, including a
  neural network (MLP) with backpropagation from scratch (see
  `neural_net.md`).
- ✅ Travel-time estimation by transport mode — done (exact for cars via
  OSRM, approximate for other modes).
- ✅ Real road routing (not straight lines) — done via OSRM, with an honest
  fallback to great-circle distance.
- ✅ Route sharing via link — done, with no database.
- ✅ Trip cost and CO2-emissions estimation — done, with configurable
  parameters in the UI.
- ✅ Weather and points of interest along the route — done (Open-Meteo,
  Overpass).
- ✅ AI day planner (K-Means) and AI trip note (LLM/fallback) — done.
- ✅ Production A/B testing, ML Lab 2.0, and a safe batch-review pipeline — done.
- ✅ UI localization (RU/EN) and PWA installability — done.
- ✅ Local history, favourites, and GeoJSON/GPX/KML export — implemented
  without uploading history; cross-device sync still requires accounts/data storage.

## Technical Constraints

- The application runs without a database: mutable server-side state is the
  geocoding file cache, rate-limiter state, aggregate A/B statistics, and an
  anonymous correction queue. The queue is ephemeral on Vercel; durable
  collection would require persistent storage.
- Route history/favourites are stored in this browser's `localStorage` and
  are not synchronized between devices.
- Weather, points of interest, and the AI note depend on the availability of
  free third-party services with no SLA — if unavailable, the app simply
  omits the corresponding panel, without breaking the main route
  calculation.
