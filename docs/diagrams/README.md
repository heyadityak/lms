# Diagrams

Mermaid diagrams for the white-label LMS. They render natively on GitHub; pre-rendered SVGs are in [`svg/`](svg/) (named `<file>-<block number>.svg`) for slides and READMEs. Source of truth for behaviour stays [USE_CASES.md](../USE_CASES.md), for design [SYSTEM_DESIGN.md](../SYSTEM_DESIGN.md), for the API [api/openapi.yaml](../api/openapi.yaml); decisions in [.logs/decisions.md](../../.logs/decisions.md). If a diagram disagrees with those, the diagram is wrong.

| File | Type | Contents |
|---|---|---|
| [01-system-design.md](01-system-design.md) | C4-style flowcharts | System context, containers (NGINX → Next.js web + BFF and FrankenPHP/Octane API, worker, scheduler, Postgres, Redis, RustFS, Keycloak, SMTP), per-customer deployment with env/secrets, horizontal scaling |
| [02-data-flow.md](02-data-flow.md) | DFD level 0 + level 1 | Processes P1–P11, data stores D1–D14 mapped to tables / Redis keys / buckets |
| [03-class-diagram.md](03-class-diagram.md) | Class diagrams (4) | Models + enums, domain actions/services, identity (Keycloak guard, provisioner, admin client, user-admin actions), HTTP layer; list of class names to adopt |
| [04-object-diagram.md](04-object-diagram.md) | Object diagram (classDiagram with instances) | Snapshot: alice/bob/carol/dave, IT, EL, an approved and a pending request, actions, ledger rows with balances that add up |
| [05-activity-diagrams.md](05-activity-diagrams.md) | Activity diagrams with swimlanes | Apply leave, approve/reject, cancel, year-start accrual, white-label settings, first-login provisioning, role-change guardrails |
| [06-sequence-diagrams.md](06-sequence-diagrams.md) | Sequence diagrams (11) | OIDC login and logout via the Next.js BFF, API JWT, apply (Browser → NGINX → BFF → NGINX → app), approve, cancel+refund, accrual, `/readyz`, logo upload + theming, API errors + idempotency, user creation with compensation |
| [07-state-diagram.md](07-state-diagram.md) | State diagram | Leave request lifecycle and transition table |

## Re-rendering

```bash
# from the repo root; extracts every ```mermaid block and writes docs/diagrams/svg/<file>-<n>.svg
for f in docs/diagrams/0*.md; do
  docker run --rm -v "$PWD/docs/diagrams":/data minlag/mermaid-cli \
    -i "/data/$(basename "$f")" -o "/data/svg/$(basename "$f" .md).md" -e svg
done
```

`mmdc` with a Markdown input writes one SVG per block (`<name>-1.svg`, …) plus a copy of the Markdown that links them; delete the generated `.md` copies in `svg/`.
