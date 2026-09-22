# Documentation Update Manifest

Documentation-only update for `ViolettaNcl/smart-route-planner`.

## Included

- `README.md`
- `README.ru.md`
- `CONTRIBUTING.md`
- `SECURITY.md`
- engineering documentation under `docs/`
- repository-local SVG visual assets under `docs/assets/`

## Intentionally not overwritten

- application source code
- tests
- GitHub workflows
- `CHANGELOG.md`
- `LICENSE`
- existing `docs/openapi.yaml`

`docs/openapi.yaml` remains the detailed machine-readable API contract already maintained by the repository. The new API reference links to it instead of replacing the schema from incomplete assumptions.
