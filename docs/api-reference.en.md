# API Reference

[Русская версия](api-reference.md) · [Architecture](architecture.en.md)

## Public contract

Client-facing API paths use:

```text
/api/<endpoint>.php
```

On Vercel, `vercel.json` routes them to the single `api/index.php` front controller, which dispatches to `server/endpoints/<endpoint>.php` using an explicit allow-list.

## Logical endpoints

| Endpoint | Area | Role |
|---|---|---|
| `route` | routing | Build/optimize a route and return routing/ML/cost/emissions data |
| `day_plan` | routing / ML | Split an ordered trip into distance-balanced days |
| `suggest` | geodata | Location search when a compatible provider is configured |
| `poi` | geodata | POIs via Overpass |
| `weather` | geodata | Weather/warnings via Open-Meteo |
| `assistant` | AI | Trip narrative with provider or rule fallback |
| `decision_boundary` | ML | Decision-surface data |
| `explain` | ML | Explain one prediction |
| `model_insights` | ML | Compare models and return local insights |
| `model_quality` | ML | Quality / Model Card data |
| `ab_stats` | ML | A/B feedback statistics |
| `feedback` | ML | Record prediction correctness |
| `learn` | ML | Queue a corrected label for reviewed training |
| `reset_model` | admin / ML | Restore a reviewed baseline; admin-protected |
| `health` | ops | Health/capability check |

## Core route endpoint

`POST /api/route.php`

Important behavior:

- up to 12 stops;
- supplied coordinates bypass unnecessary geocoding;
- intermediate stops may be optimized;
- OSRM-compatible road routing is preferred;
- great-circle fallback is available when road routing fails;
- transport inference, time, cost and emissions are attached to the result;
- unresolved points are returned explicitly.

## Error handling

Relevant handlers use JSON error objects and machine-readable `error_code` values.

The generic front-controller exception path returns `INTERNAL_ERROR` and logs server detail rather than exposing exception text.

## Rate limiting

The application uses a file-backed token bucket. It is useful for a single-instance deployment and provider-quota protection, but it is not a distributed cross-node rate limiter.

## Vercel routing

```text
/api/route.php
        │
        ▼
/api/index.php?endpoint=route
        │
        ▼
server/endpoints/route.php
```

## OpenAPI

The detailed request/response schema remains in:

[`docs/openapi.yaml`](openapi.yaml)

Treat that file as the machine-readable contract and this document as the architecture-oriented API guide.

## Compatibility note

`server/endpoints/*.php` are internal dispatch targets, not the documented public URL scheme.
