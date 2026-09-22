# Security Policy

## Supported code

Security fixes should target the current `main` branch unless a maintained release branch is explicitly documented.

## Reporting a vulnerability

Avoid publishing exploitable details in a public GitHub issue.

Use GitHub private vulnerability reporting/security contact features when enabled. If they are unavailable, contact the repository owner privately before public disclosure.

Include the affected component, reproduction steps, impact, authentication/secret relevance and suggested mitigation when known.

## Trust boundaries

### Public document root

The intended web document root is `public/`. Internal source, test and administration files should not be served directly.

### API front controller

Vercel API traffic is dispatched by `api/index.php` through an explicit endpoint allow-list.

### Administrative model reset

The model reset path is intended to be protected by an administrative token. Production deployments must configure and protect it.

### Runtime storage

File-backed state under `var/` is operational data. Keep it outside direct web access and use appropriate permissions.

### Proxy / client identity

Trust forwarded client-IP headers only from known reverse proxies. Blind trust can weaken IP-based rate limiting.

### External providers

Geocoding, routing, weather, POI and LLM integrations cross the application boundary. Review provider policies and never place server secrets in client-side code.

## Secret handling

- never commit real `.env` values;
- rotate a credential immediately if it is committed;
- keep administrative tokens server-side;
- use deployment-platform secret storage;
- avoid logging provider keys or authorization headers.

## Security headers

The Vercel configuration defines baseline headers including `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy` and HSTS.

Deployment-specific review is still required for CSP, proxy trust and domain configuration.
