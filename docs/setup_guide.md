# Setup & Operations

[English](setup_guide.en.md) · [Documentation hub](README.md)

## Требования

### PHP

- PHP 8.1+
- `curl`, `json`, `mbstring`

### Проверки

- Composer
- Node.js 22.x
- npm

### Docker

- Docker
- Docker Compose

## Clone

```bash
git clone https://github.com/ViolettaNcl/smart-route-planner.git
cd smart-route-planner
```

## Локальный запуск

Production API работает через front controller. Для корректного local dispatch используйте router из HTTP integration tests:

```bash
php -S 127.0.0.1:8000 -t public tests/Http/router.php
```

Открыть `http://127.0.0.1:8000`.

Health: `http://127.0.0.1:8000/api/health.php`.

Простой запуск `php -S ... -t public` без router может не повторять production API routing.

## Environment

```bash
cp .env.example .env
```

LLM key не обязателен: assistant имеет rule-based fallback.

Опциональные настройки: AI providers, Nominatim-compatible search, OSRM chain/cache, public URL, model admin token.

Не коммитьте secrets.

## Docker

```bash
cp .env.example .env
docker compose up --build
```

Открыть `http://localhost:8080`.

`./var` монтируется как persistent volume на обычном host.

## Backend checks

```bash
composer install
composer check
```

Отдельно:

```bash
composer run cs-check
composer run stan
composer run test
```

## Frontend / browser

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

## Retrain

```bash
php bin/train_model.php
```

Для первого запуска это не требуется.

## Render

Используйте Docker Web Service и Dockerfile из репозитория. Apache раздаёт
`public/` и передаёт `/api/<name>.php` в `api/index.php`.

Демо: **https://smart-route-planner-wiwk.onrender.com/**

## Хранение данных

Для сохранения файлов между деплоями используйте постоянный диск или том.
На Render задайте `APP_RUNTIME_DIR` как доступный на запись путь на этом
диске. В Docker Compose каталог `var/` монтируется с хоста.

## Troubleshooting

### Local API 404

```bash
php -S 127.0.0.1:8000 -t public tests/Http/router.php
```

### Нет routing/geodata

Проверить network, provider, env, logs, limiter state. Fallback может быть ожидаемым.

### AI работает в fallback

Корректно при отсутствии provider credential.

### `var/` write error

Проверить filesystem permissions.

### Playwright не стартует

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
[ ] route flow
[ ] degraded provider behavior
[ ] production smoke
[ ] no secrets committed
```
