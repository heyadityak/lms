# 2026-11-22 — architect — class diagram: LeaveRequestValidator

## Goal
Close the phase-11 hand-off: put `LeaveRequestValidator` on `docs/diagrams/03-class-diagram.md` so the arrows match the PHP constructors, re-render that file's SVGs with the documented Mermaid workflow, and judge whether a new ADR is needed. Docs only. No commit. Did not edit `.logs/MEMORY.md`, `.logs/BUILD_PLAN.md`, `.logs/decisions.md`, PHP, or `web/**`.

## What I did (files)
- `docs/diagrams/03-class-diagram.md`
  - Section 2: class `LeaveRequestValidator` (`validate`, static `assertNoOverlap`).
  - `SubmitLeaveRequest --> LeaveRequestValidator`. Kept `SubmitLeaveRequest --> ApproverResolver` (constructor still takes it, for the submitted notification). Removed the old `SubmitLeaveRequest` arrows to `WorkingDayCalculator`, `LeaveBalanceService` and `LeaveYear`.
  - Constructor edges: `LeaveRequestValidator --> WorkingDayCalculator`, `--> LeaveBalanceService`, `--> ApproverResolver`.
  - `LeaveYear` is not injected. `validate()` calls `LeaveYear::of` and `LeaveYear::end`, so the edge is `LeaveRequestValidator ..> LeaveYear : of, end` (same dotted style as the static `assertNoOverlap` call), not a solid constructor arrow.
  - `ApproveLeaveRequest ..> LeaveRequestValidator : assertNoOverlap`. Kept `ApproveLeaveRequest --> LeaveBalanceService` (that is the only constructor argument).
  - Section 4: `LeaveRequestController::preview(StoreLeaveRequestRequest r, LeaveRequestValidator v)`. Note that preview reuses the store Form Request and is not behind `EnsureIdempotency`.
  - Class-name table row for `App\Services\LeaveRequestValidator`.
- `docs/diagrams/svg/03-class-diagram-{1,2,3,4}.svg` re-rendered (mmdc writes one SVG per block in the file; blocks 1 and 3 were unchanged in source). Deleted the generated `docs/diagrams/svg/03-class-diagram.md` copy, per `docs/diagrams/README.md`.
- `docs/api/openapi.yaml` not edited. `POST /leave-requests/preview` (`previewLeaveRequest`) is already there.
- `docs/SYSTEM_DESIGN.md` not edited. The §5 line that `SubmitLeaveRequest` runs V1–V8 inside the transaction and the user-row lock is still true: `validate()` is called inside that lock.

## Commands run + results
- `docker run … minlag/mermaid-cli` (the command in `docs/diagrams/README.md` and `.logs/2026-11-19-architect-review.md`): failed. Docker daemon socket `/var/run/docker.sock` is not present.
- Same renderer, host CLI, because the image's entrypoint is `mmdc`: `npx -y @mermaid-js/mermaid-cli -i docs/diagrams/03-class-diagram.md -o docs/diagrams/svg/03-class-diagram.md -e svg` → exit 0, "Found 4 mermaid charts", four SVGs written. No `Syntax error` in any of them. Text check: diagram 2 contains `LeaveRequestValidator` and `assertNoOverlap`; diagram 4 contains `preview(StoreLeaveRequestRequest`.
- Redocly not run. The documented openapi check applies when `docs/api/openapi.yaml` changes; it did not.

## Decisions
No new ADR. Next free number would be **D-51** (last recorded is D-50). Not used.

D-49 already records this: V1–V8 and `assertNoOverlap` live in `LeaveRequestValidator`, shared by `SubmitLeaveRequest`, `ApproveLeaveRequest` and `LeaveRequestController::preview()`; preview reuses `StoreLeaveRequestRequest`, writes nothing, and ignores `Idempotency-Key`. The phase-11 log asked for that ADR before the orchestrator wrote D-49. This run only draws the class that D-49 already decided. It does not change a decision.

## Bugs found / open issues
- Pre-existing, left as drawn: `ApproveLeaveRequest --> ApproverResolver` does not match the constructor (`LeaveBalanceService` only). `canDecide` is not called from the action. Not part of the phase-11 hand-off, and not made false by the validator move.
- `CancelLeaveRequest` injects `ApproverResolver` and the diagram has no edge for it. Same pre-existing gap; not changed.
- Docker was down, so SVGs came from host `npx @mermaid-js/mermaid-cli` rather than `minlag/mermaid-cli`. Output is still mmdc SVG. Re-run the README docker loop if a byte-identical image render is required.
- `.logs/MEMORY.md` was not indexed, per this task's do-not-edit list. Orchestrator should add this log to the index.

## Next steps
- Orchestrator: index this log in `.logs/MEMORY.md`. Do not append a new ADR.
- No domain or frontend follow-up from this diagram change.
