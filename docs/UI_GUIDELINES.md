# LMS — UI guidelines

Status: v0.2 (architect, 2026-11-19, D-43, D-45: antd v6). Applies to the Next.js frontend in `web/` (owner: frontend agent). Architecture, auth and routing: [SYSTEM_DESIGN.md](SYSTEM_DESIGN.md) §6.1 and §8.2. Business rules: [USE_CASES.md](USE_CASES.md).

## 1. Visual reference

The dashboard UI follows the Figma community file **"Employee Leave Management"**: <https://www.figma.com/community/file/1473575090164873432/employee-leave-management>. It is built from **Ant Design v6** components (§3), not as a pixel copy. Customer branding (§4) replaces the file's colours and logo.

| Item | Value |
|---|---|
| Working copy | <https://www.figma.com/design/R5KzPQs6IgzS3JYvXS9z1E/Employee-leave-management--Community-> (fileKey `R5KzPQs6IgzS3JYvXS9z1E`) |
| Implementation page | "Final One" (node `484:0`) |
| How it is read | The **frontend agent** pulls design context per screen through the Figma MCP at build time (`get_design_context`, `get_screenshot`, and `get_variable_defs` for exact colours, spacing and type). Other agents do not fetch from Figma. |

> **License check pending.** Confirm the community file's license and attribution terms before shipping. The orchestrator is confirming this with the user. Until then, no Figma assets (images, icons, illustrations, avatars) are copied into `web/`; only layout and interaction patterns are used.

## 2. Screen inventory

From page "Final One". Node ids are Figma node ids in the working copy.

| # | Screen | Figma node(s) | LMS route | Roles | Module | In MVP | antd components | API |
|---|---|---|---|---|---|---|---|---|
| S1 | Sign-in landing (not in Figma) | — | `/signin` (button → `POST /auth/sign-in/social`, D-45) | public | core | brand logo, name, "Sign in with SSO" | `Card`, `Button` | `GET /branding` |
| S2 | Dashboard | `484:2` (variants `484:186`, `484:370`, `484:555`, `484:740`) | `/` | any | core | **Available Leaves** stat + Apply button; **pending approvals** stat (manager, hr_admin); **Team Time Off** (manager, hr_admin only, D-43; employee-wide view is UC-E13 Later); **Next Holidays** (`holidays` module); alert row "absent → Apply Leave" only if backed by data (not in MVP) | `Layout` + `Sider`, `Card` + `Statistic`, `Carousel` or `Listy` with `Avatar` (initials, no Figma photos), `Button` | `/me`, `/me/balances`, `/approvals`, `/leave-requests?filter[status]=approved,pending&filter[from]&filter[to]&include=user`, `/holidays` |
| S3 | Leaves (my requests + balances) | `484:925` | `/leave-requests` | any | core | stat cards = one per tracked leave type (available) + Pending requests count; Figma "Compensatory" and "Deducted" cards have no backing in the model and are replaced by per-type balances; Apply Leave button + filters; table (type, from, to, days, status tag, reason, approver); row action **Cancel** (Figma "delete"); Figma "edit" is out of MVP (no update endpoint) | `Card` + `Statistic`, `Table`, `Tag`, `Select`/`DatePicker` filters, `Popconfirm`, `Pagination` via `Table` | `/me/balances`, `/me/leave-requests`, `/leave-requests/{id}/cancel` |
| S4 | Apply Leave modal | `484:1597`, `484:1874`, `484:2153`, `484:2432` | modal on `/leave-requests` (deep link `/leave-requests/new`) | any | core | leave type radio, half-day (day part), from/to dates, days (from the server preview), reason, optional handover note | `Modal` + `Form`, `Radio.Group`, `DatePicker.RangePicker`, `Input.TextArea` | `/leave-types`, `POST /leave-requests/preview` (debounced), `POST /leave-requests` (`Idempotency-Key`) |
| S5 | Request detail | (derived) | `/leave-requests/[id]` or `Drawer` | per policy | core | details + action history | `Drawer`, `Descriptions`, `Timeline` | `/leave-requests/{id}?include=actions` |
| S6 | Approvals inbox | (derived from S3 table) | `/approvals` | manager, hr_admin | core | table, approve (`Popconfirm`), reject (`Modal` with required comment) | `Table`, `Popconfirm`, `Modal` | `/approvals`, `/approve`, `/reject` |
| S7 | Holidays | (derived from S2 Next Holidays) | `/holidays` | any | `holidays` | year list / calendar | `Calendar`, `Listy` | `/holidays` |
| S8+ | HR and admin screens | (derived; check misc nodes) | `/admin/**` | hr_admin / admin | per SYSTEM_DESIGN §6.1 | | `Table` + `Drawer`/`Modal` forms, `Upload`, `ColorPicker`, `Switch` | per SYSTEM_DESIGN §6.1 |
| — | Misc alternate layouts / components | `6501:314`, `6501:792`, `3693:313` | — | — | — | frontend agent inspects and reuses patterns | — | — |

**Out of scope** (present in Figma, not built): Swipe Data and regularize modal (`484:1104`, `484:1323`), Timesheet, Payslip, Assets, Refer Employee card, Work hours chart, Missing Swipe / Missing Timesheet cards, comp-off-expiring alerts, global search (no search endpoint). The Sider shows only LMS items: **Dashboard, Leaves, Approvals** (manager, hr_admin), **Holidays** (`holidays` module), **Admin** (hr_admin and/or admin; sub-items per role and module).

## 3. antd component mapping

| UI need | antd component | Notes |
|---|---|---|
| App shell | `Layout` + `Layout.Sider` (collapsible, icon nav as in Figma) + `Menu` (`items`, not children) + `Layout.Header` + `Space` (`orientation`, `separator`; not `direction`, `split`) | Sider nav lists only the modules and roles the user has (§2). Header: brand logo, brand name, notifications bell only if backed by data (not in MVP), user menu (`Dropdown`, v6 `popupRender` / `classNames.root`; `Dropdown.Button` is deprecated → `Space.Compact` + `Dropdown` + `Button`) with sign-out (`POST /bff/logout`). |
| Balances | `Card` + `Statistic` (one per leave type: available, with used and pending as secondary text), `Progress` optional | Values come from `/me/balances`, never computed client-side. v6: `Card variant` (not `bordered`), `Card styles.header/body` (not `headStyle`/`bodyStyle`), `Statistic styles.content` (not `valueStyle`), sizes `medium` (not `default`/`middle`). |
| Lists | `Table` (server-side pagination from `meta`, `sort=` / `filter[...]` mapped to the API) | Status as `Tag`: pending = `warning` (yellow), approved = `success` (green), rejected = `error` (red), cancelled = `default` (grey), as in Figma. Paginate with `meta`, not `links` (they are absolute API URLs). v6: `pagination.placement` (not `position`), `size="medium"` (not `middle`), `filterDropdownProps.open`; `Tag variant="filled"`/`"solid"` replaces `bordered={false}` / `*-inverse` colours. |
| Calendars | `Calendar` (holidays, team leave) | Weekend days from `/settings/working-week`. v6: `cellRender` / `fullCellRender` (not `dateCellRender` / `monthCellRender`). |
| Apply form | `Modal` + `Form` + `DatePicker.RangePicker` + `Radio.Group` | `Form.Item` `name`s equal the API field names, so problem+json `errors` map straight to `form.setFields`. Day count and remaining balance come from `POST /leave-requests/preview` (debounced ~300 ms, on type/date/day-part change); no client-side working-day maths. Generate one `Idempotency-Key` per submit attempt. v6: `Modal destroyOnHidden` (not `destroyOnClose`), `mask.closable` (not `maskClosable`), `styles.body`; RangePicker `variant`, `classNames.popup.root`, `onCalendarChange` (not `onSelect`). |
| Details | `Drawer` (request detail + `Timeline` of actions), `Descriptions` | v6: `Drawer size` (not `width`), `Drawer styles.*`; `Timeline items` (not `Timeline.Item` children; `mode` `start`/`end`); `Descriptions items`, `styles.label/content`. |
| Confirm / comment | `Popconfirm` (approve, cancel), `Modal` + `Form` (reject comment, required) | |
| Feedback | `App` (`message`, `notification`, `modal` hooks), `Alert`, `Result` (403/404/503 pages), `Skeleton`, `Empty` | Map problem `code` to messages; show `request_id` on 5xx. v6: `notification` / `Alert` use `title` (not `message`), `notification actions` (not `btn`), `Alert closable={{ onClose }}`. |
| Admin forms | `Form`, `Upload` (logo/favicon, `accept` PNG/JPEG/WebP or PNG/ICO), `ColorPicker` (hex only), `Switch` (modules, disabled when `locked`) | |
| Icons | `@ant-design/icons` **v6** (v5 icons do not work with antd 6) | |

Rules:
- antd components are **client components**. Fetch initial data in server components (with the session's token) and pass it as props, or use TanStack Query in client components through `/bff/api`. Do not fetch inside antd render callbacks.
- Use the `App` component wrapper so `message`/`modal` pick up the theme (no static `message.success`).
- Dates: antd v6 uses `dayjs`. Send and receive `YYYY-MM-DD` strings; do not use local time-zone conversion for dates.
- One component library only (antd). No extra CSS framework.
- **antd v6 API names only.** Deprecated v5 props still work in v6 but print dev warnings and disappear in v7: treat an antd deprecation warning as a bug. `List` is deprecated since 6.6: use `Listy`. Import sub-components from their own path in server-rendered files (antd's Next.js note, e.g. no `<Select.Option />`; prefer `options` / `items` props).

## 4. Theming tokens (white-label)

**Default tokens** come from the Figma file: indigo/blue primary (around `#3F5BC6`, Sider), darker navy for primary buttons, light grey page background, white cards. The frontend agent reads the exact values with `get_variable_defs` and puts them in the env defaults (`BRAND_PRIMARY_COLOR`, `BRAND_ACCENT_COLOR`; platform updates `.env.example`) and in fixed antd tokens (`colorBgLayout`, `colorBgContainer`, Sider/Menu component tokens). Customers override the brand colours through branding (D-14, D-16); the fixed tokens are not per customer in MVP.

The root layout (server component) fetches `GET /api/v1/branding` and renders `AntdRegistry` → `ConfigProvider` with `theme.token` built from it. antd v6 always emits CSS variables (`theme.cssVar` only sets `prefix`/`key`; leave it unset), so a brand change is new variable values, not a new build. Keep `theme.zeroRuntime` off: the brand tokens are only known at request time.

| Branding field | antd token | Notes |
|---|---|---|
| `primary_color` | `colorPrimary`, `colorLink`, `colorInfo` | Hex re-validated (`^#[0-9a-fA-F]{6}$`) before use; fallback to the default brand. |
| `accent_color` | `colorSuccess` | Used for approved status and positive balances. |
| `primary_contrast` | `Button` component token `primaryColor` | Text on primary-filled controls. |
| `name` | `generateMetadata` title, header text | |
| `logo_url`, `favicon_url` | header `<img alt={name}>`, `generateMetadata` `icons` | |
| fixed | `borderRadius: 6`, `fontFamily` system stack | Not per customer in MVP. |

Contrast: if a customer's `primary_color` fails 4.5:1 against white for link text, the frontend uses `primary_contrast` for text on primary backgrounds and keeps the link colour; the branding admin screen shows a contrast warning (no hard block, the API already validates format only).

Dark mode: out of MVP (`theme.algorithm` stays `defaultAlgorithm`).

## 5. Responsive breakpoints

antd's grid breakpoints are used as-is:

| Name | Min width | Layout |
|---|---|---|
| `xs` | 0 | Sider hidden behind a menu button (`Drawer`); cards in one column; tables scroll horizontally or show a condensed column set. |
| `sm` | 576 px | Two balance cards per row. |
| `md` | 768 px | Sider collapsed (icons only). |
| `lg` | 992 px | Sider expanded; detail opens in a `Drawer`. |
| `xl` | 1200 px | Four balance cards per row. |
| `xxl` | 1600 px | Max content width 1440 px, centred. |

Use `Grid.useBreakpoint()` or `Row`/`Col` spans; no custom media queries unless a component needs them.

## 6. Accessibility

Target WCAG 2.2 AA. Every Playwright e2e page visit runs `@axe-core/playwright`; serious and critical violations fail the build.

Known antd gaps to check (verify with axe and by keyboard; record confirmed ones here with a workaround):

| Area | Concern | Mitigation |
|---|---|---|
| `DatePicker` / `RangePicker` panel | Keyboard navigation inside the calendar panel is limited | Typed input stays enabled (`YYYY-MM-DD` format hint in the label). |
| `Select` | Screen reader announcements of the active option vary by browser | Always give a visible `Form.Item` label; test with keyboard. |
| Placeholder / disabled text | Default grey can fall below 4.5:1 | Do not put required information in placeholders. |
| Icon-only buttons | No accessible name by default | Always set `aria-label`. |
| `Table` sorters | Sort state is not always announced | Add `aria-sort` via `onHeaderCell` if axe flags it. |
| Brand colours | Customer colours can fail contrast | See §4 contrast rule. |

## 7. Version note

**antd v6** (D-45, user decision 2026-11-19; supersedes the v5 choice in D-43): `antd` 6.x and `@ant-design/icons` 6.x, exact versions in `web/package-lock.json`. v6 supports React ≥ 18, so `@ant-design/v5-patch-for-react-19` is not used. `@ant-design/nextjs-registry` stays for App Router first-screen style extraction (v6 still generates styles at runtime). `@ant-design/cssinjs` is pinned as a direct dependency to the version antd resolves, so the registry and antd share one style cache (`npm ls @ant-design/cssinjs` shows one copy). v6 supports modern browsers only (no IE). Migration reference: <https://ant.design/docs/react/migration-v6>.
