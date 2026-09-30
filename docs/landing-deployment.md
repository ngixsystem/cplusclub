# Landing page on the VPS

The static files in `landing/` are served by the independent `landing` service
in `compose.yaml` on port 80. Login links point to
`http://2.24.162.21:8080/login`. The existing application keeps port 8080;
the VPS's untracked Compose override publishes that application port.

Deploy on the VPS from `/root/cplusclub`:

```sh
git pull --ff-only
docker compose config --quiet
docker compose up -d --no-deps landing
docker compose exec -T landing nginx -t
curl -f http://127.0.0.1/
curl -f http://127.0.0.1:8080/login
```

No dependency installation, application rebuild or database migration is needed.
Port 80 must be free. To disable the landing without touching the application:

```sh
docker compose stop landing
```

The current addresses use unencrypted HTTP. Configure a domain and HTTPS before
using credentials over untrusted networks. Update all three login links when
the application address changes.
