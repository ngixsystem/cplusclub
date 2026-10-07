# Service reports

`/reports` defaults to closed tickets. `?view=work` shows the existing work-log
ledger. Representatives see only their clubs; owner/lead see all clubs; specialist
reports remain scoped to club membership. Both HTML and CSV use the same query.

## Closed tickets

- Includes all currently closed tickets, even without equipment, assignee or logs.
- Shows problem, cause, solution, performed work, verification and closing date.
- Work minutes are summed per ticket. Costs are grouped by currency; currencies
  are never added together. A zero-log ticket has zero minutes and no posted costs.
- Filters: club, equipment, assigned specialist, inclusive date range. Dates use
  Asia/Tashkent and the ticket's `closed_at` (not creation or work-log date).
- A reopened ticket no longer appears until closed again. This is a current-state
  report, not an immutable historical month-end accounting snapshot.
- Cost totals include all work logs of the selected tickets, including work logged
  outside the closing-date range. Proposals are not posted expenses.

## Work ledger and CSV

- Work-log date range uses `work_logs.created_at`, independently of ticket status.
- Filters persist across pages. Changing tabs retains filters but not page number.
- CSV uses applied server filters, not unsaved form edits, and exports all pages.
- CSV dates explicitly use Asia/Tashkent. Monetary values are integer minor units;
  the closed-ticket CSV cost column contains JSON grouped by currency.
- Text cells beginning with spreadsheet formula markers are escaped. Downloads
  require authentication and authorization and use private/no-store cache headers.
- Foreign club/equipment/assignee filter IDs cannot bypass membership restrictions.

## Verification

40 backend tests / 429 assertions passed in isolated VDS Docker, including zero-log
tickets, multiple currencies, date boundaries, membership, CSV formula protection
and export of more than one page. TypeScript and production build passed.
Five Playwright scenarios passed, including the representative viewing a newly
closed zero-log ticket, filtering, authenticated CSV and mobile layout checks.
