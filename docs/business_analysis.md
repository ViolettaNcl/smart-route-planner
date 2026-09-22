# Product & Business Analysis

[English](business_analysis.en.md) · [Documentation hub](README.md)

## Product thesis

Smart Route Planner — технически прозрачный route-planning продукт и engineering portfolio project.

Главная дифференциация — комбинация route optimization, provider-backed geodata, explainable ML и graceful degradation без обязательного account/database слоя для core experience.

## Основные user jobs

1. Собрать multi-stop route.
2. Улучшить порядок intermediate stops.
3. Сравнить road-route alternatives.
4. Получить time/cost/emissions.
5. Добавить weather/POI context.
6. Save/share/export без аккаунта.
7. Посмотреть model reasoning.

## Дифференциация

Сильнее всего выглядит не слово “AI”, а:

- classical route optimization;
- real road routing;
- transparent fallback;
- from-scratch ML + baseline;
- quality/explainability surfaces;
- no-database core flow;
- tests и deployment checks.

## Не является целью

- замена коммерческой navigation platform;
- guaranteed exact TSP;
- traffic forecasting;
- модель на большой истории реальных users;
- booking engine;
- готовая distributed stateful platform.

## User value и engineering signal

| Capability | Пользователь | Engineering |
|---|---|---|
| Route ordering | меньше ручной перестановки | heuristic optimization |
| OSRM | road-realistic geometry/time | integration + failover |
| Weather / POI | trip context | optional enrichment |
| MLP/Softmax | transport suggestion | model + baseline |
| Insights | transparency | explainability / evaluation |
| PWA/share | low-friction reuse | client state |
| Docker/Vercel | availability | portability |
| CI/smoke | trust | release discipline |

## Reliability promise

Корректная формулировка — graceful degradation, а не обещание аптайма внешних сервисов.

> При отказе optional provider Smart Route Planner старается сохранить core trip-planning flow и показать degraded source.

## Privacy

Core route library хранится client-side, аккаунт не обязателен. Но route-related input может передаваться backend и third-party provider; production deployment должен учитывать их privacy terms.

## Направления развития

- durable shared storage;
- self-hosted/contracted routing/geocoding;
- consented real-world training data;
- richer ML features;
- observability;
- traffic-aware routing;
- optional account sync/collaboration;
- новые локали.

## Критерии сильного GitHub repository

- архитектура понятна быстро;
- setup воспроизводим;
- demo работает;
- CI виден;
- limitations не спрятаны;
- contribution path очевиден;
- docs соответствуют current code.

Это полезнее для доверия и stars, чем fake metrics.
