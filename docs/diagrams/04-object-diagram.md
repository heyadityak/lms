# 04 — Object diagram (snapshot)

Mermaid has no native object diagram, so this uses `classDiagram` with **instance boxes** named `object : Class` and attribute *values* instead of types. It is a snapshot of one deployment on **2026-11-19** with `LEAVE_YEAR_START_MONTH=1` (calendar leave year), `LMS_WEEKEND_DAYS=6,7` and the `holidays` module on. Ids are shortened ULIDs (`public_id`); bigint ids are shown only where they explain foreign keys.

Story:
- Alice (employee, IT) reports to Bob (manager). Carol is the HR admin. All three are the dev users from the Keycloak realm import.
- Alice carried 12 EL days from 2025 and got the 30-day EL grant on 2026-01-01.
- Request #41: EL 2026-10-07 (Wed) … 2026-10-09 (Fri) = **3 days**, approved by Bob → `debit −3`.
- Request #42: EL 2026-12-21 (Mon) … 2026-12-25 (Fri). 25 Dec is a holiday, so **4 days**. Still `pending`.

Balances for Alice, EL, leave year 2026:

| Figure | Formula | Value |
|---|---|---|
| balance | SUM(ledger) = 12 + 30 − 3 | **39.0** |
| used | −(debit + reversal) = −(−3) | 3.0 |
| pending | SUM(days of pending requests in 2026) = 4 | 4.0 |
| available | balance − pending = 39 − 4 | **35.0** |
| entitled | carry_forward + grant (+ lapse + adjustment) = 12 + 30 | 42.0 |

```mermaid
classDiagram
    direction LR

    class it["it : Department"] {
        id = 2
        public_id = 01JB8YZZ…H8J
        code = IT
        name = Information Technology
    }
    class carol["carol : User"] {
        id = 1
        keycloak_sub = 5b1e…c7d2
        name = Carol Iyer
        email = carol@example.test
        role = hr_admin
        manager_id = null
        date_of_joining = 2019-07-01
        is_active = true
    }
    class bob["bob : User"] {
        id = 2
        keycloak_sub = 9f04…a1b3
        name = Bob Mehta
        email = bob@example.test
        role = manager
        department_id = 2
        manager_id = null
        date_of_joining = 2021-02-15
        is_active = true
    }
    class alice["alice : User"] {
        id = 3
        public_id = 01JB8Z0K…V1W
        keycloak_sub = 3c8a…e910
        name = Alice Rao
        email = alice@example.test
        role = employee
        department_id = 2
        manager_id = 2
        date_of_joining = 2024-06-03
        is_active = true
    }
    class el["el : LeaveType"] {
        id = 3
        code = EL
        annual_quota = 30.0
        tracks_balance = true
        carry_forward_cap = null
        max_balance = 300.0
        allow_half_day = false
        max_backdate_days = 0
    }
    class xmas["xmas : Holiday"] {
        date = 2026-12-25
        name = Christmas
    }
    class req41["req41 : LeaveRequest"] {
        id = 41
        public_id = 01JC1A2B…K1M
        user_id = 3
        leave_type_id = 3
        start_date = 2026-10-07
        end_date = 2026-10-09
        day_part = full
        days = 3.0
        status = approved
        decided_by = 2
        decided_at = 2026-09-30T09:12Z
        decision_comment = Enjoy!
    }
    class req42["req42 : LeaveRequest"] {
        id = 42
        public_id = 01JD3M7V…XYZ
        user_id = 3
        leave_type_id = 3
        start_date = 2026-12-21
        end_date = 2026-12-25
        day_part = full
        days = 4.0
        status = pending
        decided_by = null
    }
    class a1["a1 : LeaveRequestAction"] {
        leave_request_id = 41
        actor_id = 3
        action = submitted
        from_status = null
        to_status = pending
        created_at = 2026-09-28T14:02Z
    }
    class a2["a2 : LeaveRequestAction"] {
        leave_request_id = 41
        actor_id = 2
        action = approved
        from_status = pending
        to_status = approved
        comment = Enjoy!
    }
    class a3["a3 : LeaveRequestAction"] {
        leave_request_id = 42
        actor_id = 3
        action = submitted
        from_status = null
        to_status = pending
        created_at = 2026-11-19T10:15Z
    }
    class l1["l1 : LeaveLedgerEntry"] {
        user_id = 3
        leave_type_id = 3
        year = 2026
        kind = carry_forward
        days = 12.0
        actor_id = null
        note = from 2025
    }
    class l2["l2 : LeaveLedgerEntry"] {
        user_id = 3
        leave_type_id = 3
        year = 2026
        kind = grant
        days = 30.0
        actor_id = null
    }
    class l3["l3 : LeaveLedgerEntry"] {
        user_id = 3
        leave_type_id = 3
        year = 2026
        kind = debit
        days = -3.0
        leave_request_id = 41
        actor_id = 2
    }
    class aud["aud : UserAdminAudit"] {
        actor_id = 1
        target_user_id = 2
        action = assign_role
        changes = role employee to manager
        outcome = success
        created_at = 2026-03-02T11:40Z
    }

    it -- bob : department_id
    it -- alice : department_id
    bob -- alice : manager_id
    alice -- req41 : submits
    alice -- req42 : submits
    el -- req41 : of
    el -- req42 : of
    req41 *-- a1
    req41 *-- a2
    req42 *-- a3
    bob -- a2 : actor
    alice -- l1 : owns
    alice -- l2 : owns
    alice -- l3 : owns
    req41 -- l3 : debit
    xmas .. req42 : excluded from day count
    carol -- aud : actor
    bob -- aud : target
```

Checks:
- No ledger row for req42: pending requests reduce **available** (35) but not **balance** (39). A debit is written only on approval (USE_CASES §3.5).
- req41 and req42 do not overlap, so the Postgres exclusion constraint accepts both.
- 12 + 30 = 42 ≤ `max_balance` 300, so the grant was not clipped. EL `carry_forward_cap = null`, so the 2025 closing balance carried in full and no `lapse` row exists.
- If Alice cancels req41 before 2026-10-07 (not possible any more: it has started), a `reversal +3` would bring balance back to 42. If Bob approves req42 now, a `debit −4` makes balance 35 = available.
- The audit row records Carol promoting Bob through UC-U6 (Keycloak first, then the local `role` mirror).
