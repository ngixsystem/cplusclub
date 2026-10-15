# Landing and connection requests

Design rollback (2026-10-15): the classic landing is restored, including its
calculator and login links. It does not show the connection form or motion demo.
The request API, admin inbox and saved data remain intact. The request-flow
description below documents that retained backend, not the current landing UI.

The static `landing/` site is served by the independent nginx `landing` service.
Production uses Caddy and the private Compose override based on
`compose.domains.yaml`: https://cplusclub.tech and https://admin.cplusclub.tech.
Do not overwrite the host override or expose the application port publicly.

## Request Flow

- The form posts to same-origin `/api/connection-requests`. Landing nginx proxies
  this exact path to application nginx; no browser CORS configuration is needed.
- The database migration creates `connection_requests`. The admin inbox is
  `/connection-requests`, available only to active `owner` and `lead` users.
- Requests contain contact details, club name, PC count, selected plan and an
  optional message. Statuses: new, contacted, closed. No automatic payment,
  contract, club creation or external notifications are triggered.
- UUID-based retries are idempotent. Validation, a honeypot and a conservative
  limit of 20 requests/minute protect the endpoint. Behind the current proxy,
  the limiter can share a bucket across visitors; this is not a CAPTCHA.
- JavaScript is required for form submission. Animation is optional; reduced
  motion, pause, keyboard navigation and no-JavaScript content are supported.
- The animated service card is explicitly illustrative, not live club telemetry.

## Deployment

Build and test in the isolated VPS checkout with `compose.test.yaml` and the
`cclub_test` database. Never run test migrations or E2E seeders in production.
Run the backend suite, TypeScript checks, Vite build and
`npx playwright test tests/e2e/landing.spec.ts`. Start the test `landing` service
as well as application nginx before browser tests.

Before production deployment, use `scripts/backup.sh` with a protected
`BACKUP_DIR` outside the repository. Preserve the current revision, landing
files, nginx configuration and `public/build` directory for rollback.

From `/root/cplusclub`, during a short maintenance window:

```sh
docker compose exec -T app php artisan down --retry=30
git pull --ff-only
docker compose exec -T app php artisan migrate --force
# Copy the verified build assets and manifest from the isolated checkout.
docker compose exec -T app php artisan view:clear
docker compose up -d --no-deps --force-recreate landing
docker compose exec -T landing nginx -t
docker compose exec -T app php artisan up
curl -f https://cplusclub.tech/
curl -f https://admin.cplusclub.tech/health/ready
```

Always restore application availability if a deployment step fails. Verify an
invalid JSON POST returns 422 without inserting a production test lead. A GET
to the POST-only endpoint should return 405. Verify authenticated inbox access.
Static files have a version query string; increment it with subsequent edits.

## Rollback

Revert the release commit through Git, restore the backed-up build, recreate
landing nginx and clear compiled views. Keep `connection_requests` and any
submitted customer data; do not run a destructive migration rollback. Restore
a database backup only under a separate recovery plan that accounts for writes
since that backup. Backups contain personal data and must remain protected.
