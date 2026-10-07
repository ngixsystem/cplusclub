# MVP readiness audit: 2026-10-07

## Scope and baseline

Baseline: `a11b7d9`, identical in GitHub, local checkout and production VDS.
Production's existing `.env.example` modification is preserved. Builds and tests
run only inside Docker in `/root/cplusclub-icafe-check`, using `cclub_test`.
No test accounts or test records are added to production.

This is an initial release-readiness audit of tickets, reports, permissions and
operations, not a complete security audit or acceptance of all requirements.

## Prioritized findings

| Priority | Finding and evidence | Acceptance criterion |
| --- | --- | --- |
| P1 | Telegram `bot_token` is not excluded from validation old input in `bootstrap/app.php`. Encryption on the model does not protect flashed form input. | A failed channel form retains ordinary fields but never flashes the token; regression test passes. |
| P1 | `TicketPolicy::update` rejects representatives; `TransitionTicket` has no explicit quote/approval decision. Staff can move approval back to working without a recorded customer decision. | Versioned proposal, amount/currency, approve/reject actions scoped to the club, actor/time/history, mandatory rejection reason and concurrency tests. |
| P1 | `ReportController` starts from `work_logs`; closed tickets without logs cannot appear. No period/club/status filters. | Separate closed-ticket report includes zero-log tickets, filters, pagination and matching CSV; no cross-club data or totals. |
| P1 | Backup scripts exist, but no matching backup schedule was found in the inspected cron/systemd files on VDS. Recovery has not been tested in this audit. | Scheduled database and attachment backups, restricted permissions, off-host copy, failure notification and isolated restore drill. Protect APP_KEY separately. |
| P2 | Ticket assignment and transitions do not enqueue lifecycle notifications. Telegram kinds include critical tickets, but not ordinary assignment/approval/completion. | Transactional outbox events, deduplication and tested failure/retry behavior for each lifecycle event. |
| P2 | Representatives cannot confirm completion or return work for revision; interface exposes generic status transitions to staff. | Explicit action buttons and narrow representative permissions, without granting general ticket-edit permission. |
| P2 | Boot temperatures are not integrated. Earlier source inspection found WebSocket `pcs_status`, `data.pcs[].id/cpu_temp/gpu_temp`; REST API token did not authorize staffInfo. | Authorized read-only channel verified with real TeamPro readings, identifier mapping, freshness, disconnect/reconnect and no fabricated readings for SPOT. |

## Delivery batches

1. Audit and immediate secret-handling regression fix; establish current test baseline.
2. Ticket proposal/approval and completion confirmation with narrowly scoped policies,
   immutable decision history and optimistic locking. Keep existing ticket records
   valid through additive migrations; do not infer historical approvals.
3. Closed-ticket report and filtered export, then work-log totals and owner overview.
4. Lifecycle notifications and operational backup/restore acceptance.
5. Two-club pilot, role-specific browser scenarios and integration acceptance.

Thermal WebSocket integration is a separate track gated on supported authorization.
It must not block ticket-service MVP or expose staff credentials to the browser.
Financial dashboard figures still require comparison against real club statements.

## Verification boundaries

- Secret-handling regression reproduced before the fix and passed after adding
  `bot_token` to `dontFlash`. Final backend run: 33 tests, 285 assertions passed.
- Four existing Playwright scenarios and four agent unit tests passed on VDS.
- Production HTTPS login and readiness returned 200; Horizon and scheduler health passed.
- All current production migrations are applied.
- Original backend baseline: 30 tests, 208 assertions passed in isolated VDS Docker.
- TypeScript checking and production frontend build passed for the current baseline.
- Existing browser tests exercise the administrator workflow and mocked dashboard/
  thermal responses; they do not prove real sensor collection or financial accuracy.
- Report role tests cover owner, lead, specialist, representative, anonymous and
  disabled accounts. They are not a replacement for a full browser pass per role.
- No database restoration, actual Telegram delivery, load test, complete financial
  reconciliation or physical-PC sensor acceptance is claimed by this audit.

## Release procedure

Test in isolation, commit and push, preserve private VDS settings, back up before
data/schema changes, fast-forward production and verify HTTPS readiness. Do not
run destructive migrations or restore test data into the production database.
