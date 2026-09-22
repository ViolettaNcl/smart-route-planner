# Product & Business Analysis

[Русская версия](business_analysis.md) · [Documentation hub](README.md)

## Product thesis

Smart Route Planner is a technically transparent route-planning product and engineering portfolio project.

Its differentiation is the combination of route optimization, provider-backed geodata, explainable ML and graceful degradation without a mandatory account/database layer for the core experience.

## Primary user jobs

1. Build a route across several stops.
2. Improve intermediate stop order.
3. Compare road-route alternatives.
4. Understand time, approximate cost and emissions.
5. Add weather/POI context.
6. Save/share/export without creating an account.
7. Inspect how the transport model reached its output.

## Differentiation

The strongest story is not the word “AI”; it is the combination of:

- classical route optimization;
- real road routing;
- transparent fallback behavior;
- from-scratch ML plus a baseline;
- model-quality and explanation surfaces;
- no-database core flow;
- reproducible tests and deployment checks.

## Non-goals

The project should not be positioned as:

- a replacement for a commercial turn-by-turn navigation platform;
- a guaranteed exact TSP solver;
- a traffic-forecasting platform;
- a classifier trained on a large corpus of real traveler behavior;
- a booking engine;
- a distributed stateful platform out of the box.

## User value vs. engineering signal

| Capability | User value | Engineering signal |
|---|---|---|
| Route ordering | less manual stop ordering | heuristic optimization |
| OSRM routing | road-realistic geometry/time | provider integration + failover |
| Weather / POI | trip context | optional enrichment boundaries |
| MLP/Softmax | transport suggestion | implementation + baseline |
| Model insights | transparency | explainability / evaluation |
| PWA / sharing | low-friction reuse | client-side state design |
| Docker/Vercel | availability | deployment portability |
| CI / smoke | trust | release discipline |

## Reliability promise

A credible product promise is graceful degradation, not universal provider uptime:

> When an optional external service is unavailable, Smart Route Planner attempts to preserve the core trip-planning flow and surface the degraded source where possible.

## Privacy shape

The core route library is client-side and basic use does not require an account. Route requests still send route-related input to the backend and may involve third-party providers, so production deployments should document provider privacy terms.

## Credible growth directions

- durable shared storage for feedback/analytics;
- self-hosted or contracted routing/geocoding;
- real consented training data;
- richer transport-classifier features;
- observability dashboards;
- traffic-aware routing through an appropriate provider;
- optional account sync / collaboration;
- additional locales.

## Repository success criteria

- architecture is understandable within minutes;
- local setup works from documentation;
- live demo is reachable;
- CI state is visible;
- limitations are explicit;
- issue/PR path is obvious;
- docs match the current code structure.

Those signals build more trust than decorative fake metrics.
