# Security Policy

[English version below](#security-policy-english)

## Как сообщить об уязвимости

Пожалуйста, **не создавайте публичный Issue** для потенциальной уязвимости.
Вместо этого напишите через
[GitHub Security Advisories](https://github.com/ViolettaNcl/smart-route-planner/security/advisories/new)
репозитория — это приватный канал, видимый только мейнтейнеру, пока
проблема не будет исправлена.

Это учебный/портфолио-проект без SLA на исправление, но постараюсь ответить
в течение нескольких дней.

## Что стоит знать о поверхности атаки

- **Нет базы данных и аутентификации** — соответственно, нет SQL-инъекций и
  нет учётных записей, которые можно было бы скомпрометировать.
- **API-ключи** (`ANTHROPIC_API_KEY`, `OPENAI_API_KEY`) читаются только из
  переменных окружения или `config.local.php` (в `.gitignore`) — никогда не
  коммитятся и не возвращаются клиенту в ответах API.
- **Rate limiting** (`App\Http\RateLimiter`) защищает эндпоинты живого
  дообучения модели (`api/learn.php`, `api/reset_model.php`) и свободные
  лимиты внешних API от злоупотребления одним клиентом.
- **Пользовательский ввод** (список городов, параметры стоимости) валидируется
  на сервере перед использованием; при выводе в HTML на фронтенде
  применяется экранирование (`escapeHtml` в `app.js`).
- Веса модели после `api/learn.php` — общий файл на диске для всех
  посетителей сайта (см. "Известные ограничения" в README) — это
  демонстрационный механизм, не многопользовательская изоляция; не
  рассчитывайте на него как на защищённое хранилище состояния.
- Идентификация клиента для rate limiter — по IP (с опциональным доверием
  `X-Forwarded-For`, см. докблок `App\Http\ClientIdentity`) — при деплое за
  балансировщиком/CDN стоит явно проверить эту настройку, иначе лимитер
  может считать всех клиентов одним IP или, наоборот, доверять
  подделываемому заголовку.

## Практики, которые уже применяются

- `composer audit` — часть CI, проверяет dev-зависимости (PHPStan,
  PHP-CS-Fixer) на известные уязвимости при каждом push.
- Dependabot — еженедельно проверяет обновления Composer/GitHub
  Actions/Docker.
- PHPStan (уровень 6) в CI — ловит часть логических ошибок до релиза, хоть
  и не является security-инструментом в чистом виде.

---

## Security Policy (English)

### Reporting a vulnerability

Please **do not open a public Issue** for a potential vulnerability. Instead,
use
[GitHub Security Advisories](https://github.com/ViolettaNcl/smart-route-planner/security/advisories/new)
for this repository — a private channel visible only to the maintainer
until the issue is resolved.

This is a portfolio/learning project with no fix-time SLA, but I'll aim to
respond within a few days.

### Attack surface notes

- No database or authentication — no SQL injection surface, no accounts to
  compromise.
- API keys (`ANTHROPIC_API_KEY`, `OPENAI_API_KEY`) are read only from
  environment variables or `config.local.php` (gitignored) — never
  committed, never echoed back in API responses.
- Rate limiting (`App\Http\RateLimiter`) protects the live model
  fine-tuning endpoints (`api/learn.php`, `api/reset_model.php`) and the
  free external API quotas from single-client abuse.
- User input (city list, cost parameters) is validated server-side before
  use; output is escaped on the frontend (`escapeHtml` in `app.js`).
- Model weights after `api/learn.php` are a single shared file for all site
  visitors (see "Known Limitations" in the README) — a demo mechanic, not
  multi-tenant isolation; don't treat it as a secured state store.
- The rate limiter identifies clients by IP (with optional
  `X-Forwarded-For` trust — see the `App\Http\ClientIdentity` docblock) —
  behind a load balancer/CDN, verify this setting explicitly, or the
  limiter may either treat every client as one IP or trust a spoofable
  header.

### Practices already in place

- `composer audit` runs in CI on every push, checking dev dependencies
  (PHPStan, PHP-CS-Fixer) against known advisories.
- Dependabot checks Composer/GitHub Actions/Docker updates weekly.
- PHPStan (level 6) in CI catches some classes of logic bugs before
  release, though it isn't a dedicated security tool.
