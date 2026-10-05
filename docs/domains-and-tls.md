# Domains and TLS

Deployment: /root/cplusclub on the VDS.

- Public site: https://cplusclub.tech
- Application: https://admin.cplusclub.tech
- Both DNS A records must point to 2.24.162.21. Do not add an AAAA record without working IPv6 routing.
- Caddy obtains and renews free Let's Encrypt certificates automatically and redirects HTTP to HTTPS.
- Keep TCP 80/443 and optionally UDP 443 open. Caddy certificates and ACME account keys live in persistent Docker volumes caddy-data and caddy-config, never Git. Never remove those volumes during deployment.

## Configuration

The VDS uses compose.yaml plus compose.override.yaml, copied from the tracked compose.domains.yaml. The base Compose remains usable locally.

```sh
cp compose.domains.yaml compose.override.yaml
docker compose config --quiet
docker compose up -d
```

Set APP_URL=https://admin.cplusclub.tech and SESSION_SECURE_COOKIE=true in the private .env before recreating app, queue and scheduler. Clear any old config cache with docker compose exec app php artisan config:clear.

Only Caddy exposes public HTTP/HTTPS. Landing has no published port; application Nginx binds 8080 to loopback for local diagnostics. The dedicated https-upstream.conf marks FastCGI requests HTTPS because TLS terminates at Caddy. Do not expose this upstream directly or use this config for plain HTTP deployments.

## Operations

```sh
docker compose logs --tail=100 caddy
docker compose exec caddy caddy validate --config /etc/caddy/Caddyfile
docker compose exec caddy caddy reload --config /etc/caddy/Caddyfile
curl -I https://cplusclub.tech
curl -I https://admin.cplusclub.tech/login
```

The Caddy image is pinned by digest. Renewals require the service to remain running, persistent volumes to remain writable, and domain DNS/ports to stay valid. No host cron or separate Certbot is required.

Private pre-migration settings are backed up outside Git at /root/cclub-domain-backup-20261005. Rollback: stop Caddy, restore the previous .env and compose.override.yaml from that directory, recreate app/queue/scheduler/nginx/landing, clear config cache. This restores the previous unencrypted endpoints; use only for recovery.

## Verified on 2026-10-05

Both domain HTTPS endpoints return 200 with publicly trusted Let's Encrypt certificates. HTTP requests redirect to the matching HTTPS URL. Certificate expiry: 2027-01-03; Caddy renewal automation is active. Application login cookies have Secure enabled; the landing has three HTTPS admin login links. Nginx and Caddy configuration validation passed. External requests from the development machine also returned 200 for both sites.
