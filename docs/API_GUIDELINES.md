# LMS — API guidelines

Status: v1 (architect, 2026-11-19). Decision D-21. The contract is [`docs/api/openapi.yaml`](api/openapi.yaml) (OpenAPI 3.1). **The spec is the source of truth.** If the code and the spec disagree, the code is wrong. Change the spec first (architect/coordinator), then the code.

Who uses this file:
- **domain** implements `/api/v1` routes, API Resources, status codes and problem `code`s exactly as specified.
- **qa** validates every API feature-test response against `openapi.yaml` with `osteel/openapi-httpfoundation-testing` (dev dependency).
- **platform** serves Swagger UI / Redoc for the spec at `/api/docs` when the `api` module is enabled (404 otherwise).

## 1. Basics

| Topic | Rule |
|---|---|
| Base URL | `{APP_URL}/api/v1`. Major version in the URI. Breaking change ⇒ `/api/v2` alongside v1. Additive changes (new fields, endpoints, enum values documented as extensible) stay in v1. |
| Module gate | Every `/api/v1/*` route (and `/api/docs`) has `module:api`. Off ⇒ `404 application/problem+json`, code `NOT_FOUND`. Feature modules add their own gate (`holidays`, `reports`) with the same 404. |
| Resources | Plural, kebab-case nouns: `/leave-requests`, `/leave-types`, `/ledger-entries`. Nested only for ownership (`/users/{id}/balances`). |
| Current user | `/me`, `/me/balances`, `/me/leave-requests`, `/me/ledger-entries`. Clients never need their own id. |
| State transitions | Sub-resource actions with `POST`: `/leave-requests/{id}/approve`, `/reject`, `/cancel`; `/users/{id}/deactivate`, `/activate`. Never `PATCH status`. |
| Methods | `GET` read, `POST` create/action, `PATCH` partial update (JSON merge semantics: omitted = unchanged, `null` = clear), `PUT` only for whole-file uploads, `DELETE` remove/reset. |
| Media type | Request and response `application/json` (UTF-8). Errors `application/problem+json`. Reports also `text/csv` (§6). Uploads `multipart/form-data`. |
| Fields | `snake_case`. Booleans `is_*`/`has_*` or plain adjectives (`enabled`). Day amounts are JSON numbers, multiples of `0.5`. |
| Dates | ISO-8601. Calendar dates `YYYY-MM-DD` (`start_date`). Instants RFC 3339 with offset, UTC (`2026-11-19T10:15:00Z`). |
| IDs | Every exposed id is the row's **ULID** (`public_id` column, 26 chars, Crockford base32). The bigint primary key is never exposed. Foreign keys in payloads are ULIDs too (`leave_type_id`). |
| Nulls | Optional fields are present with `null`, not omitted, in responses. |

## 2. Envelope

Laravel API Resources, always wrapped:

```json
{
  "data": { "id": "01JD3M7V1Q8Z6K2H5R9T4W0XYZ", "status": "pending", "...": "..." },
  "meta": { },
  "links": { "self": "https://lms.example/api/v1/leave-requests/01JD3M7V1Q8Z6K2H5R9T4W0XYZ" }
}
```

- Single resource: `data` is an object. `meta` and `links` may be `{}`.
- Collections: `data` is an array; `meta` and `links` carry pagination (§3).
- `204 No Content` has no body. Actions (`approve`, `cancel`, …) return `200` with the updated resource.
- `201 Created` returns the resource and a `Location` header.
- Implementation: `JsonResource::$wrap = 'data'` (default); collections via `ResourceCollection` with `paginationInformation()` overridden to produce the shapes in §3.

## 3. Pagination, filtering, sorting, includes

| Param | Example | Rule |
|---|---|---|
| `page[number]` | `page[number]=2` | 1-based, default 1. |
| `page[size]` | `page[size]=50` | default 25, **max 100** (422 above). |
| `filter[...]` | `filter[status]=pending&filter[from]=2026-12-01&filter[to]=2026-12-31` | Only filters documented per endpoint. Unknown filter ⇒ 422 `VALIDATION_FAILED`. Comma = OR: `filter[status]=pending,approved`. |
| `sort` | `sort=-start_date,created_at` | Comma list, `-` = descending. Only documented fields. |
| `include` | `include=actions,user` | Only where documented (leave request `actions`, `user`, `leave_type`). |

Collection response:

```json
{
  "data": [ ],
  "meta": { "current_page": 2, "per_page": 25, "total": 61, "last_page": 3 },
  "links": { "first": "…?page[number]=1", "prev": "…?page[number]=1", "next": "…?page[number]=3", "last": "…?page[number]=3" }
}
```

Page-based pagination is enough for the data sizes of one customer. If a list ever needs cursor pagination, add `page[cursor]` as a new parameter; `links.next` stays the client contract.

## 4. Errors (RFC 9457)

Every 4xx/5xx from `/api/v1` is `application/problem+json`:

```json
{
  "type": "urn:lms:problem:leave-overlap",
  "title": "Leave overlaps an existing request",
  "status": 422,
  "detail": "You already have a pending or approved request between 2026-12-21 and 2026-12-25.",
  "instance": "/api/v1/leave-requests",
  "code": "LEAVE_OVERLAP",
  "request_id": "6f1c0e9a8b7d4c3e",
  "errors": { "start_date": ["Overlaps request 01JD3M7V1Q8Z6K2H5R9T4W0XYZ."] }
}
```

- `type` is a stable URN (`urn:lms:problem:<slug>`), the same in every white-label deployment. `code` is the stable machine code clients switch on; `title` and `detail` are human text and may change.
- `errors` (field → messages) is present on `422`.
- `request_id` echoes `X-Request-Id` for support.
- Rendered centrally in `bootstrap/app.php` `withExceptions()` for requests that match `api/*`: `ValidationException` → 422, `AuthenticationException` → 401, `AuthorizationException` → 403, `ModelNotFoundException`/`NotFoundHttpException` → 404, `InvalidTransition` → 409, `ThrottleRequestsException` → 429, domain `LeaveRuleViolation` (carries `code`) → 422. Never leak stack traces (`APP_DEBUG` ignored for the API).

| Status | `code` | When |
|---|---|---|
| 400 | `BAD_REQUEST` | Malformed JSON, bad query syntax. |
| 401 | `UNAUTHENTICATED` | Missing/invalid/expired Bearer JWT, wrong `iss`/`aud`, bad signature, user inactive or has no LMS role. Includes `WWW-Authenticate: Bearer error="invalid_token"`. |
| 403 | `FORBIDDEN` | Authenticated, but a Policy denies (e.g. not the requester's manager, approving own request, employee cancelling started leave). |
| 403 | `SELF_MODIFICATION_FORBIDDEN` | hr_admin tries to demote or disable themselves (D-22 G2). |
| 404 | `NOT_FOUND` | Unknown id, resource not visible to the caller **where existence must not leak**, or module disabled. |
| 406 | `NOT_ACCEPTABLE` | Report requested with an `Accept` other than JSON/CSV. |
| 409 | `INVALID_TRANSITION` | State conflict, e.g. approving a request that is no longer pending. |
| 409 | `IDEMPOTENCY_IN_PROGRESS` | Same `Idempotency-Key` still being processed. |
| 409 | `DEPARTMENT_NOT_EMPTY` | Deleting a department that still has users. |
| 409 | `LAST_HR_ADMIN` | Demoting/disabling the last active hr_admin (G3). |
| 409 | `IDP_CONFLICT` | Keycloak returned 409 (duplicate username/email). |
| 415 | `UNSUPPORTED_MEDIA_TYPE` | Wrong `Content-Type`. |
| 422 | `VALIDATION_FAILED` | Generic field validation. |
| 422 | `LEAVE_TYPE_INACTIVE` | V1 |
| 422 | `BACKDATE_LIMIT_EXCEEDED` | V2 |
| 422 | `INVALID_DATE_RANGE` | V3 (end before start, or spans two leave years) |
| 422 | `HALF_DAY_NOT_ALLOWED` | V4 |
| 422 | `NO_WORKING_DAYS` | V5 |
| 422 | `LEAVE_OVERLAP` | V6 (app check or Postgres `23P01`) |
| 422 | `INSUFFICIENT_BALANCE` | V7 (also re-checked on approve) |
| 422 | `NO_APPROVER` | V8 |
| 422 | `COMMENT_REQUIRED` | Reject without comment. |
| 422 | `IDEMPOTENCY_KEY_REUSED` | Same key, different request body. |
| 422 | `MODULE_NOT_LICENSED` | Enabling a module not in `MODULES_ENABLED` or not available. |
| 422 | `MANAGER_CYCLE` | Manager chain would form a cycle / self-manager. |
| 422 | `ROLE_READ_ONLY` | Payload to `PATCH /users/{id}` sets `role` (use `PUT /admin/users/{id}/roles`, D-22). |
| 422 | `IDP_READ_ONLY` | Profile/password change on a federated (LDAP/AD) user, or the provider refused a role change. |
| 429 | `RATE_LIMITED` | Throttled. `Retry-After` set. |
| 500 | `INTERNAL_ERROR` | Unexpected. Logged with `request_id`. |
| 503 | `SERVICE_UNAVAILABLE` | Dependency down (e.g. JWKS cannot be fetched and no cached copy). |
| 503 | `IDP_UNAVAILABLE` | Keycloak Admin API unreachable, timed out or 5xx; nothing written locally. `Retry-After` set. |

## 5. Authentication and authorization

- OAuth 2.0 / OIDC with **Keycloak** (D-20). Clients get an access token from Keycloak (client `lms-api-cli`, auth code + PKCE; discovery at `{KEYCLOAK_PUBLIC_URL}/realms/lms/.well-known/openid-configuration`) and send `Authorization: Bearer <jwt>`. The app has no token endpoints.
- The guard checks RS256 signature (JWKS cached in Redis), `iss` (`{KEYCLOAK_PUBLIC_URL}/realms/lms`), `aud`/`azp` in `KEYCLOAK_ALLOWED_AUDIENCES` (`lms-web,lms-api-cli`), `exp`/`nbf` with `KEYCLOAK_JWT_LEEWAY`. User is provisioned/synced from claims on every call.
- Each operation in the spec has `x-roles` (realm roles allowed to call it at all). Fine-grained rules (direct manager, not own request) are Policies and give **403**.
- Visibility: lists only return what the caller may see (employee: own; manager: own + direct reports; hr_admin: all). Single-resource reads of something invisible return **404** (`NOT_FOUND`), not 403, so ids cannot be probed. Actions on a visible resource that the caller may not perform return **403**.

## 6. Reports (content negotiation)

`GET /reports/leave-requests` and `/reports/balances` honour `Accept`:
- `application/json` (default): paginated envelope.
- `text/csv`: whole result streamed (`StreamedResponse`), `Content-Disposition: attachment; filename="leave-requests-2026-11-19.csv"`, header row, UTF-8, RFC 4180 quoting, no pagination. Cells starting with `= + - @` are prefixed with `'` (CSV injection).

## 7. Idempotency

`POST /leave-requests` and `POST /admin/users` accept `Idempotency-Key: <client-generated UUID/ULID, 8–255 chars>`.

1. Key scope = `idem:{user_public_id}:{key}` in Redis, TTL **24 h**.
2. First request: `SET NX` a placeholder `{status: processing, body_hash}` → run the action → store `{status, headers, body, body_hash}`.
3. Same key + same body hash: replay the stored status/body, add `Idempotent-Replayed: true`.
4. Same key + different body: `422 IDEMPOTENCY_KEY_REUSED`.
5. Same key while the first is still processing: `409 IDEMPOTENCY_IN_PROGRESS` with `Retry-After: 1`.
6. Only 2xx and 422 domain outcomes are stored. 5xx releases the key so the client can retry.

Implemented as middleware `idempotent` (`App\Http\Middleware\EnsureIdempotency`). Without the header the endpoint still works (no dedupe); the DB overlap constraint still prevents duplicate leave.

## 8. Rate limiting

Redis-backed `RateLimiter::for('api')`: 60 requests/min per user (by JWT `sub`), or per IP when unauthenticated. Every response carries `X-RateLimit-Limit` and `X-RateLimit-Remaining`; `429` adds `Retry-After` (seconds) and `X-RateLimit-Reset`.

## 9. Identity admin (`/admin/users`, D-22)

- Gated by module `user_management` (404 when off) and role `hr_admin`.
- Keycloak is written **first** through the `KeycloakAdminClient` interface (service account `lms-admin`, least privilege), then mirrored locally. Create compensates by deleting the Keycloak user if the local insert fails.
- `POST /admin/users` accepts `Idempotency-Key` exactly like `POST /leave-requests` (§7); a replay never creates a second Keycloak user.
- The API never accepts passwords. Creation and `password-reset` trigger Keycloak `execute-actions-email`.
- `PUT /admin/users/{id}/roles` replaces the single LMS role (`{"roles": ["manager"]}`); roles outside the allow-list are 422.
- Every call (success or failure) writes a `user_admin_audits` row.
- Keycloak errors map to `IDP_CONFLICT` (409), `IDP_READ_ONLY` (422), `IDP_UNAVAILABLE` (503). Keycloak error bodies are never passed through to clients.

## 10. Other conventions

- `X-Request-Id` accepted (or generated by nginx) and echoed on every response.
- Caching: `Cache-Control: no-store` on authenticated responses. `ETag` only on `/branding/*` assets.
- CORS: off by default; enable per deployment via env for known origins only.
- Deprecation: `Deprecation` + `Sunset` headers on endpoints scheduled for removal in the next major.
- Health checks (`/healthz`, `/readyz`) are outside `/api/v1`, unauthenticated, and not wrapped in the envelope.
- Lint the spec before merging: `docker run --rm -v "$PWD":/work redocly/cli lint /work/docs/api/openapi.yaml`. Expected result: valid, with 2 accepted warnings (`operation-4xx-response` on `/healthz` and `/readyz`, which have no 4xx by design).
