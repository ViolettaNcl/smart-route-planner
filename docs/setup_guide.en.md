# Setup & Operations Guide

[Русская версия](setup_guide.md) · [Documentation hub](README.md)

## Requirements

### Native PHP

- PHP 8.1+
- extensions: `curl`, `json`, `mbstring`

### Development checks

- Composer
- Node.js 22.x
- npm

### Containers

- Docker
- Docker Compose

## Clone

```bash
git clone https://github.com/ViolettaNcl/smart-route-planner.git
cd smart-route-planner
```

## Local PHP run

The production API uses a front controller. To mirror that dispatch locally, use the router already used by HTTP integration tests:

```bash
php -S 127.0.0.1:8000 -t public tests/Http/router.php
```

Open:

```text
http://127.0.0.1:8000
```

Health:

```text
http://127.0.0.1:8000/api/health.php
```

Serving only `public/` without the router may not reproduce the production `/api/<endpoint>.php` dispatch model.

## Environment

Copy the example when using Docker or when you want a reference for supported settings:

```bash
cp .env.example .env
```

The core app does not require an LLM key. The assistant can fall back to rule-based output.

Optional configuration areas include AI provider credentials, Nominatim-compatible search, OSRM endpoints/cache, public URL and the model administrative token.

Never commit real secrets.

## Docker

```bash
cp .env.example .env
docker compose up --build
```

Open `http://localhost:8080`.

`docker-compose.yml` mounts `./var` so file-backed state can survive container recreation on a persistent host.

## Backend quality checks

```bash
composer install
composer check
```

`composer check` currently combines:

```text
cs-check
phpstan
test
```

Individual commands:

```bash
composer run cs-check
composer run stan
composer run test
```

## Frontend / browser tests

```bash
npm ci
npx playwright install chromium
npm run test:frontend
npm run test:e2e
```

## Production smoke

```bash
npm run smoke:production
```

GitHub Actions also runs production smoke on a schedule and after successful main-branch CI.

## Retrain the transport model

```bash
php bin/train_model.php
```

Do this only when intentionally regenerating model artifacts.

## Vercel model

`vercel.json`:

- defines one PHP function at `api/index.php`;
- serves static assets from `public/`;
- maps `/api/<name>.php` to the front controller;
- defines basic security/cache headers.

Repository homepage: **https://smart-route-planner-vn.vercel.app/**

## Persistence

On Docker/VPS, preserve `var/` and ensure runtime write access.

On Vercel, do not treat the local filesystem as durable shared storage.

## Troubleshooting

### UI loads but local API returns 404

Use:

```bash
php -S 127.0.0.1:8000 -t public tests/Http/router.php
```

### Routing/geodata is missing

Check outbound network access, provider availability, environment overrides, logs and limiter state. The app may intentionally degrade to fallback behavior.

### AI narrative reports fallback

That is valid when no supported LLM provider/credential is available.

### `var/` write errors

Ensure the runtime user can write to required `var/` paths.

### Browser tests fail before execution

```bash
npm ci
npx playwright install chromium
```

## Release checklist

```text
[ ] composer check
[ ] npm run test:frontend
[ ] npm run test:e2e
[ ] health endpoint
[ ] route calculation
[ ] degraded-provider behavior
[ ] production smoke
[ ] no secrets committed
```
