# C+CLub

Веб-приложение для команды обслуживания компьютерных клубов с CCBoot/iCafeCloud.

**Статус: рабочая версия в разработке; полная приёмка MVP ещё не завершена.**
См. [полное ТЗ](docs/requirements.md) и [состояние реализации](docs/implementation-status.md).
Локальная среда Docker работает; сайт доступен на http://127.0.0.1:8080.
Фронтенд собран и проверен TypeScript в Docker. Полный статус проверок указан в docs/implementation-status.md.

## Стек
Laravel 13 / PHP 8.4 / PostgreSQL 17 / Redis / Horizon / Vue 3 / TypeScript / Inertia / Tailwind / Nginx.
Все Composer, npm, Artisan и тестовые команды выполняются в Docker. Не устанавливайте PHP/Node на хост.

## Подготовка Windows (однократно)
В PowerShell **от имени администратора**:
```powershell
winget install --exact --id Microsoft.WSL --source winget --accept-package-agreements --accept-source-agreements
wsl --install --no-distribution
```
Перезагрузите Windows самостоятельно, запустите Docker Desktop и дождитесь готовности Engine.
Проверьте `docker version` (должны быть Client И Server) и `docker compose version`.
Может потребоваться включение виртуализации в UEFI/BIOS.
Документация: https://docs.docker.com/desktop/setup/install/windows-install/

## Установка зависимостей и локальный запуск
Скопируйте .env.example в .env; установите случайный DB_PASSWORD. Не коммитьте .env.
В текущем рабочем каталоге .env уже создан со случайным паролем БД.

```sh
docker compose build app
docker compose run --rm --no-deps app composer install --no-interaction
docker compose run --rm --no-deps node npm ci
docker compose run --rm --no-deps app php artisan key:generate
docker compose up -d postgres redis
docker compose run --rm app php artisan migrate --force
docker compose run --rm node npm run typecheck
docker compose run --rm node npm run build
docker compose up -d app nginx queue scheduler
docker compose exec app php artisan cclub:admin owner@example.com --name="Анвар"
```

Пароль вводится скрыто интерактивно, минимум 14 символов. Стандартных аккаунтов нет.
Откройте http://localhost:8080. 
Lock-файлы созданы Composer/npm в Docker. Используйте
`composer install` / `npm ci` на последующих чистых checkout. Lock-файлы нельзя выдумывать вручную.

## Проверки
Только отдельная тестовая БД:
```sh
docker compose exec postgres createdb -U cclub cclub_test
docker compose run --rm app php artisan test
docker compose run --rm node npm run typecheck
docker compose run --rm node npm run build
docker compose exec app php artisan horizon:status
```
Значение cclub_test принудительно задано в phpunit.xml. Тесты удаляют/создают таблицы этой БД.
При изменении DB_USERNAME измените пользователя в createdb.
Начальные тесты: календарные даты, автомат статусов, запрет чужого доступа, завершение/переоткрытие,
проверка устаревшей версии. Это ещё не вся приёмочная матрица.

## Область реализации и ограничения
В исходниках реализованы осмотры/выезды, приватные вложения/QR, управление пользователями, аудит, агент/телеметрия,
тревоги, обновления игр, доставка Telegram, отчёты, production Compose и CI. Нагрузочный стенд и полная приёмка ещё не завершены.
Production stages, восстановление резервных копий и CI требуют отдельной проверки перед эксплуатацией.
Никакие реальные уведомления не отправлялись. Внешние сервисы не подключены.

## Git
Репозиторий: https://github.com/ngixsystem/cplusclub. Ветка разработки: codex/cclub-mvp.
Коммиты разработки могут иметь технического автора Codex <codex@localhost>, если автор Git на машине не настроен.

