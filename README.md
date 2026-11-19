# LMS — Leave Management System

[![Docs: wiki](https://img.shields.io/badge/docs-wiki-blue)](https://github.com/heyadityak/lms/wiki) [![License: MIT](https://img.shields.io/badge/license-MIT-green)](LICENSE)

White-label, single-tenant leave management for SMBs and enterprises: one deployment per customer, each with its own branding and module set. Employees apply for leave, managers approve, HR runs leave types, holidays, balances and reports, and an admin manages branding, modules and users. Balances live in an append-only ledger with year-start accrual and carry-forward; identity and roles come from Keycloak.

**Stack:** Laravel 12 + Octane/FrankenPHP (PHP 8.4), PostgreSQL 17, Redis 7, RustFS (S3), Keycloak 26, NGINX.

## 📚 Full documentation: https://github.com/heyadityak/lms/wiki

Quick links: [Home](https://github.com/heyadityak/lms/wiki/Home) · [Use-Cases](https://github.com/heyadityak/lms/wiki/Use-Cases) · [System-Design](https://github.com/heyadityak/lms/wiki/System-Design) · [Api-Guidelines](https://github.com/heyadityak/lms/wiki/Api-Guidelines) · [Diagrams](https://github.com/heyadityak/lms/wiki/Diagrams) · [Agents](https://github.com/heyadityak/lms/wiki/Agents)

The wiki is generated from [`docs/`](docs/) and [`AGENTS.md`](AGENTS.md). Edit those files, not the wiki.

## Quickstart (build in progress)

Install Task: https://taskfile.dev/installation/ (e.g. `brew install go-task`).

```bash
cp .env.example .env && task up
```

## License

MIT — see [LICENSE](LICENSE)
