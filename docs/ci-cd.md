# CI/CD

Pipeline: `.github/workflows/ci.yml` (GitHub Actions, workflow «Docker checks»).

| Событие | Что происходит |
| --- | --- |
| push / pull request в любую ветку | `test`: сборка образа, composer/npm, TypeScript, сборка Vite, PHPUnit на `cclub_test`, Playwright, тесты агента |
| push в любую ветку, кроме `main`, после зелёных тестов | `deploy-staging`: стенд https://staging.cplusclub.tech |
| push в `main` после зелёных тестов | `deploy-production`: https://admin.cplusclub.tech, только после правил окружения `production` |

Pull request из форков не получает секретов и ничего не выкатывает.

## Стейджинг

- Каталог `/root/cplusclub-staging`, Compose-проект `cclub-staging` (`compose.staging.yaml`).
- Своя PostgreSQL, Redis, загрузки и `.env`. С продом общих сервисов и сетей нет: только `web` и Caddy
  подключены к отдельной сети `cclub-edge`.
- Telegram работает через fake transport, реальные сообщения не уходят.
- Демо-данные создаёт `StagingSeeder` один раз (работает только при `APP_ENV=staging`). Все клубы начинаются с «Демо ·».
- Входы: `demo-owner@`, `demo-lead@`, `demo-specialist@`, `demo-club@staging.cplusclub.tech`.
  Пароль генерируется при первой выкатке и хранится только на сервере:
  `grep STAGING_DEMO_PASSWORD /root/cplusclub-staging/.env`.
- Ответы помечены `X-Robots-Tag: noindex`.

## Продакшн

`scripts/deploy.sh production <sha>` на VDS:

1. проверяет, что коммит — fast-forward от текущего состояния `/root/cplusclub`;
2. делает резервную копию БД и вложений в `/root/backups/cclub` (хранятся 14 последних);
3. `artisan down`, `git merge --ff-only`, `composer install`, `npm ci && npm run build`;
4. `migrate --force`, очистка кешей, перезапуск Horizon, перезагрузка Caddy;
5. `artisan up` (также при любой ошибке) и проверка `/health/ready`.

Приватные `.env` и `compose.override.yaml` на сервере не перезаписываются.

## Однократная настройка (владелец репозитория)

1. **Ключ для деплоя.** На VDS:
   ```sh
   ssh-keygen -t ed25519 -N '' -C github-actions-deploy -f /root/.ssh/github_actions_deploy
   cat /root/.ssh/github_actions_deploy.pub >> /root/.ssh/authorized_keys
   cat /root/.ssh/github_actions_deploy        # → секрет VDS_SSH_KEY, затем удалите файл приватного ключа
   ssh-keyscan -t ed25519 2.24.162.21          # → секрет VDS_KNOWN_HOSTS
   ```
2. **Секреты** (GitHub → Settings → Secrets and variables → Actions):
   `VDS_HOST` = `2.24.162.21`, `VDS_SSH_KEY`, `VDS_KNOWN_HOSTS`.
3. **Окружения** (Settings → Environments): создайте `staging` и `production`.
   Для `production` включите «Required reviewers» и ограничьте ветку `main`.
4. **DNS:** A-запись `staging.cplusclub.tech` → `2.24.162.21`.

Маршрут Caddy для стейджинга начинает работать после первой выкатки `main` с этим Caddyfile.
