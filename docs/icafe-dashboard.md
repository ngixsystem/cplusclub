# iCafeCloud dashboard

The home page shows the financial dashboard for owner/lead and representatives.
In this application `owner` is a GLOBAL administrator, not a single-club client.
Create client accounts with role `representative` and attach only their clubs.
Specialists do not have access to financial JSON endpoints.

Create a club with license, token and currency, or connect it in its club card.
API tokens use Laravel encrypted casts and are hidden from JSON and validation
session input. Never commit tokens or enter them over public plain HTTP.
For the current HTTP deployment use an SSH tunnel, or the CLI on the VPS:

```sh
docker compose exec -T app php artisan cclub:icafe-connect CLUB_ID LICENSE < /root/.config/cplusclub/icafe.token
```

The input file must be root-readable only. Remove it after the encrypted database
connection has been verified. Keep APP_KEY backed up; changing it invalidates
encrypted connections. Rotate credentials exposed in chat and reconnect.

## Data contracts verified against live API

- shiftList requires `shift_staff_name=all`; omission returns body code 500.
- HTTP 200 does not imply success: body `code` must also be 200.
- Open shifts have negative IDs and end `-`. Multiple staff can have open shifts.
- shiftCashInHand/shiftXReport with an API token are not authoritative for all
  staff: they returned no current shift while shiftList contained two.
- Use shiftList.total_amount directly; never sum it with balance, bonuses or
  opening float. Display cash, credit_card and qr separately.
- shiftDetail accepts the negative ID. Its end_time for an open shift is the
  calculation time, NOT a close time. The list is authoritative for closure.
- reportChart income includes overlapping series; display Total only.
- Chart range is minute-granularity. Cross-day charts can be daily rather than
  hourly. Do not label all charts as hourly or force their sum to the shift total.
- The API can omit a final partial-hour category while returning its value.
  The adapter restores that label only for a verified consecutive hourly axis;
  other length mismatches are rejected, not silently truncated.
- Weekly view is seven local calendar dates and sums whole shifts by opening
  date. It is not a transaction-date cash-flow report.
- Filter pcs by pc_icafe_id and console_type=0; onlinePcList is connectivity,
  whereas pc_in_using means an active gaming session.

## Refresh and failures

Browser polls every 15 seconds while visible, without document reload. Backend
snapshots and details are cached for 30 seconds per club/credentials/timezone.
Locks prevent duplicate upstream calls. A failed refresh retains last successful
values and timestamp with a stale warning; failures are retried after the cache
interval. No polling when no one is viewing. Upstream quotas are undocumented;
increase TTL if rate limiting is observed. No mutation endpoints are called.

## Deployment / rollback

Run tests against the separate `compose.test.yaml` stack and cclub_test only.
Build frontend in Docker. Before deployment back up the production database and
public/build. Run `php artisan migrate --force`, deploy the built assets and source,
then verify /health/ready and both authenticated dashboards. A code rollback can
leave the additive columns intact; do not drop encrypted credentials as rollback.

## Verified on 2026-09-30

- Isolated PostgreSQL backend suite: 21 tests, 119 assertions passed.
- TypeScript and Vite production build passed in Docker on the VPS.
- Two Playwright flows passed: dashboard polling/detail/stale state/mobile/both
  themes and the existing club/equipment/ticket workflow.
- Separate browser smoke with real API confirmed open and closed shift charts,
  weekly shifts, computer connectivity and no mobile document overflow.
- Production migration and connection verified; anonymous dashboard returns 401,
  login and landing return 200, readiness returns ready.
- No client account was automatically granted access. Assign representatives to
  their clubs through the existing Users administration page.
