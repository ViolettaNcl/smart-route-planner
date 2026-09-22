# Contributing

Thanks for considering a contribution to Smart Route Planner.

## Before you start

For substantial behavior changes, open an issue first and describe:

- the problem;
- the proposed behavior;
- affected API/UI surface;
- fallback behavior if an external provider is involved;
- how the change will be tested.

## Local quality gate

```bash
composer install
composer check
npm ci
npm run test:frontend
npm run test:e2e
```

## Pull request expectations

A focused PR should:

- keep unrelated refactoring out of the diff;
- include tests for behavior changes;
- preserve the public API unless a breaking change is intentional and documented;
- update README/docs/OpenAPI when a public contract changes;
- document provider failure/fallback behavior;
- avoid secrets, private keys and personal data.

## Architecture rules

### Public API

Public endpoint URLs use `/api/<endpoint>.php`. Vercel dispatches those through `api/index.php`. Do not expose `server/endpoints/` as a new public URL scheme without an explicit architecture decision.

### External providers

A new provider should define timeout, rate-limit, error, fallback and data-boundary behavior.

### ML changes

Model changes should include reproducible evaluation rather than accuracy-only claims. Do not promote arbitrary web feedback directly into shared production weights inside an HTTP request.

### Documentation

Keep claims verifiable against the current repository. Avoid fake coverage, invented benchmark wins or diagrams implying unimplemented behavior.

## Commit examples

```text
docs: refresh architecture and setup guide
fix: preserve route result when weather provider fails
test: cover front-controller endpoint dispatch
```

## Security

Do not open a public issue for a sensitive vulnerability. See [SECURITY.md](SECURITY.md).
