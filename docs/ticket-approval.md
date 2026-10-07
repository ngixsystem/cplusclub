# Ticket approval and customer acceptance

## Workflow

1. Staff opens a working ticket and submits a proposal: description, amount in
   minor currency units (100 = 1), currency and expected duration. Zero is valid
   for included/free work. Submission moves the ticket to approval.
2. An active representative belonging to that club approves or rejects the latest
   pending proposal. Rejection requires a reason and keeps the ticket in approval.
3. Approval moves the ticket to working. Staff records work, verification, cause
   and solution before marking it resolved.
4. The club representative confirms completion (closed), or supplies a reason and
   returns it to working. Rework clears resolved/closed timestamps, not history.

Platform owners/leads and specialists cannot impersonate customer approval.
Representatives do not acquire general edit, assignment or work-log permissions.
Each production club needs an active representative with club membership.

## Revisions and compatibility

- A changed scope/price is submitted as a new proposal, never edited in place.
  Pending older proposals become superseded; accepted/rejected records remain.
- Decisions target the latest proposal ID and current ticket version. All writes
  lock the ticket inside a transaction. Stale requests return 409; invalid states
  return 422. A rejected or superseded proposal cannot be accepted later.
- The old generic transition API cannot enter or leave approval. Legacy tickets
  already in approval need an explicit proposal; migration fabricates no approvals.
- Once a ticket has a proposal, staff cannot close it through the transition API;
  the representative must confirm the result. Tickets without proposals keep the
  previous staff-closing path for compatibility, and can also be accepted by the
  representative once resolved.
- Proposal price is an estimate, not an automatically posted expense or enforced
  work-log spending cap. Actual work logs remain separate.
- Proposals record author, decision actor, timestamps and decision comment. Ticket
  events preserve proposal and acceptance/rework decisions.
- Notifications, closed-ticket reports and budget enforcement are separate stages;
  this release does not claim they are implemented.

## Deployment and rollback

Back up PostgreSQL, private attachments and frontend assets before migration.
Apply the additive `2026_10_07_000001_create_ticket_proposals` migration and publish
the matching built frontend. Existing ticket rows are not rewritten.

If rollback is required, restore the previous code and asset manifest without
dropping `ticket_proposals`: preserve newly recorded decisions. The old code does
not enforce proposal approval, so pause ticket writes until the fixed version is
restored. Never roll back by deleting proposal history or restoring an old dump
over new customer decisions without a separately reviewed recovery procedure.

## Verification

37 backend tests (335 assertions), TypeScript and production build passed in
isolated VDS Docker. Approval tests cover role/membership, stale versions, quote
revision, rejection reason, completion/rework and generic-transition bypasses.
No tests run against production or on the developer workstation.
All five Playwright scenarios passed, including a separate customer browser
session approving a quote and closing the ticket, and a mobile layout check.
