# 07 — State diagram: leave request lifecycle

Statuses are the `LeaveStatus` enum (`pending`, `approved`, `rejected`, `cancelled`, D-07). There is no `draft`; `taken` is **derived** (approved and `start_date ≤ today`), never stored. Allowed edges live in `LeaveStatus::canTransitionTo()`; *who* may fire them is decided by `LeaveRequestPolicy` (USE_CASES §3.6). Any other edge throws `InvalidTransition` → 409 `INVALID_TRANSITION`.

```mermaid
stateDiagram-v2
    direction LR
    [*] --> pending : submit (owner) V1–V9 pass, action submitted
    pending --> approved : approve (resolved approver, never owner), debit −days
    pending --> rejected : reject + comment (same as approve)
    pending --> cancelled : cancel (owner or hr_admin), no ledger row
    approved --> cancelled : cancel, reversal +days

    state approved {
        [*] --> upcoming
        upcoming --> taken : start_date ≤ today (derived, no write)
    }

    state cancel_check <<choice>>
    approved --> cancel_check : cancel requested
    cancel_check --> cancelled : owner and start_date > today
    cancel_check --> cancelled : hr_admin, not own started leave
    cancel_check --> approved : otherwise 403, unchanged

    rejected --> [*]
    cancelled --> [*]

    note right of pending
        Counts against available balance
        (pending days), not against balance.
        Covered by the overlap exclusion constraint.
    end note
    note right of cancelled
        Terminal. Also reached when HR deactivates
        a user (pending requests auto-cancelled).
    end note
```

| From → To | Event | Who | Ledger | Notification (if module on) |
|---|---|---|---|---|
| — → pending | submit | owner | none | `LeaveRequestSubmitted` → approver(s) |
| pending → approved | approve | the approver set in USE_CASES §3.6 (line manager, delegate, or hr_admin), never owner | `debit −days` (tracked types) | `LeaveRequestDecided` → owner |
| pending → rejected | reject (comment required) | same as approve | none | `LeaveRequestDecided` → owner |
| pending → cancelled | cancel | owner or hr_admin (or system on deactivation) | none | `LeaveRequestCancelled` → approver if owner cancelled |
| approved → cancelled | cancel | owner if `start_date > today`; hr_admin any time (not own started leave) | `reversal +days` (full amount) | `LeaveRequestCancelled` → other party |
| anything else | — | nobody | — | 409 `INVALID_TRANSITION` |
