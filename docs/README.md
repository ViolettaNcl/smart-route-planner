# Smart Route Planner — Engineering Documentation

<div align="center">

**Architecture · API · ML · Operations · Product boundaries**

[English README](../README.md) · [Русский README](../README.ru.md) · [Live demo](https://smart-route-planner-vn.vercel.app/)

</div>

---

## Documentation map

| Area | English | Русский |
|---|---|---|
| Architecture | [architecture.en.md](architecture.en.md) | [architecture.md](architecture.md) |
| API reference | [api-reference.en.md](api-reference.en.md) | [api-reference.md](api-reference.md) |
| ML engineering | [neural_net.en.md](neural_net.en.md) | [neural_net.md](neural_net.md) |
| Setup & operations | [setup_guide.en.md](setup_guide.en.md) | [setup_guide.md](setup_guide.md) |
| Product analysis | [business_analysis.en.md](business_analysis.en.md) | [business_analysis.md](business_analysis.md) |
| OpenAPI | [`openapi.yaml`](openapi.yaml) | same contract |

## Suggested reading path

### Engineering review

1. Main [README](../README.md)
2. [Architecture](architecture.en.md)
3. [ML Engineering](neural_net.en.md)
4. [Setup & Operations](setup_guide.en.md)
5. [`openapi.yaml`](openapi.yaml) and `server/endpoints/`

### Deployment review

1. [Setup & Operations](setup_guide.en.md)
2. `vercel.json`
3. `Dockerfile` / `docker-compose.yml`
4. `.github/workflows/production-smoke.yml`

## Documentation principles

- **Accuracy before marketing.** Claims should map to code, tests or workflows.
- **Fallbacks are part of the contract.** Preferred and degraded behavior are distinguished.
- **Limitations stay visible.** Synthetic data, public-provider dependencies and serverless persistence are documented.
- **No fake metrics.** No invented coverage, adoption or benchmark claims.
- **GitHub-native presentation.** Markdown, Mermaid, `<details>` and local SVG assets instead of unsupported README JavaScript.
