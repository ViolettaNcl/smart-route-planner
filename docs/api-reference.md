# API Reference

[English](api-reference.en.md) · [Архитектура](architecture.md)

## Публичный контракт

Клиентские URL:

```text
/api/<endpoint>.php
```

На Vercel `vercel.json` направляет их в `api/index.php`, а front controller вызывает `server/endpoints/<endpoint>.php` только для allow-listed endpoint.

## Логические endpoint

| Endpoint | Область | Назначение |
|---|---|---|
| `route` | routing | Маршрут, optimization, ML/cost/emissions |
| `day_plan` | routing / ML | Деление готового route order на дни |
| `suggest` | geodata | Поиск мест при compatible provider |
| `poi` | geodata | POI через Overpass |
| `weather` | geodata | Weather через Open-Meteo |
| `assistant` | AI | Trip narrative + fallback |
| `decision_boundary` | ML | Decision surface |
| `explain` | ML | Explain prediction |
| `model_insights` | ML | Model comparison / local insight |
| `model_quality` | ML | Quality / Model Card |
| `ab_stats` | ML | A/B statistics |
| `feedback` | ML | Correctness feedback |
| `learn` | ML | Corrected label в review queue |
| `reset_model` | admin / ML | Restore baseline; admin-protected |
| `health` | ops | Health/capability check |

## Основной route endpoint

`POST /api/route.php`

Правила:

- до 12 stops;
- готовые координаты не требуют geocoding;
- intermediate stops могут оптимизироваться;
- предпочтителен OSRM-compatible routing;
- при отказе возможен great-circle fallback;
- result включает transport inference, time, cost, emissions;
- unresolved points возвращаются явно.

## Ошибки

Handlers используют JSON error objects и `error_code` там, где это предусмотрено.

Generic front-controller exception превращается в `INTERNAL_ERROR`; server detail остаётся в logs.

## Rate limiting

Token-bucket limiter использует file-backed state. Это не distributed limiter для горизонтального scaling.

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

Подробный machine-readable contract:

[`docs/openapi.yaml`](openapi.yaml)

`server/endpoints/*.php` — внутренние implementation targets, не новый public URL scheme.
