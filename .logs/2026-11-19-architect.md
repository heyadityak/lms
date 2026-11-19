# 2026-11-19 — architect

## Goal
Produce the design docs and builder contract for a greenfield Laravel 12 / PHP 8.4 / Postgres 17 / NGINX rewrite of the 2016 college LMS. Docs only, no app code. Mid-run requirement added by the coordinator: white-label single-tenant delivery (branding + module toggles).

## Research
- Original project (via `gh api repos/heyadityak/archive/contents/college-projects-archive/leave-management-system`). Read README, `phpdb.sql`, `admin/{acceptleave,rejectleave,setleaves,empdelete,extractleaves,view_leaves}.php`, `admin/register/confirm.php`, `client/{validate,update_leaves,request_confirm,leaverequest,my_leaves,resetpass}.php`.
  - Tables: `admins` (HOD per Dept + SetSick/Casual/EarnLeave quotas), `employees` (balances as columns), `emp_leaves` (Status Requested/Granted/Rejected, name-string joins).
  - Flows: HOD registers employees (quotas copied from HOD defaults). Client requests leave with client-posted day count + lecture-substitution table → DOMPDF slip + mail. HOD grants (subtract balance) / rejects with mail. Anniversary update at login: EL += quota (cap 300), SL += quota, CL reset. Password recovery via emailed random key. Profile picture. Extract leaves by date range to PDF. Delete employee.
  - Defects not to repeat: SQL string concatenation (SQLi), sha1/hmac password hashing, client-trusted day counts, hard deletes losing history, non-idempotent accrual on login.
- Web sources (cited in docs):
  - https://techinterview.org/lld-employee-leave-management/ (ledger transactions, idempotent accrual, states, business-day counting)
  - https://udyamo-hrms-book.pages.dev/en/part-5/ch22-leave-policies (CL/SL/EL/LWP, proration formula, carry-over vs max accumulation, half-day, backdating)
  - https://www.item.com/staffing/time-and-attendance-leave-leave-carryover , https://kb.flair.hr/docs/learn/absence-management/accrual-policy , Workday accrual calc docs
  - Sandwich rule: https://www.keka.com/sandwich-leave-policy , Zoho People settings, https://worknest.com/blog/the-legal-risks-of-sandwich-leave
  - Laravel 12 release notes (PHP 8.2–8.5): https://laravel.com/docs/12.x/releases , 12factor.net

## What I did (files)
- `docs/USE_CASES.md`: actors, MVP/Later use cases (incl. UC-B branding/modules), business rules, state machine, accrual, module table, mapping from the original.
- `docs/SYSTEM_DESIGN.md`: architecture + container topology, Dockerfile targets, ERD (9 domain/app tables + framework tables), constraints, HTTP/API surface, white-label settings/theming/module registry, 12-factor table, env vars, security, observability, migrations, CI.
- `AGENTS.md`, `CLAUDE.md`, `.logs/MEMORY.md`, `.logs/decisions.md` (D-01…D-19).

## Decisions
D-01 … D-19 in `decisions.md`.

## Open questions (for user)
See `.logs/MEMORY.md` → Open questions.

## Next steps
1. platform: scaffold + Docker + compose + CI + health endpoints.
2. domain: migrations → enums/models → services/actions → policies/controllers/views → branding/modules.
3. qa: unit tests for calculator/state machine in parallel with domain. Feature tests per finished UC.
