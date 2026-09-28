# Архитектура C+CLub

Модульный монолит: Laravel/PHP, PostgreSQL, Redis/Horizon, Inertia/Vue 3/TypeScript, Tailwind, Nginx.
Доменная логика в app/Domain; HTTP-контроллеры валидируют вход, Policies проверяют каждую карточку/изменение.
ClubAccess фильтрует списки, но не заменяет Policies. Пользователь специалиста дополнительно видит назначенную заявку.
Связь заявки с оборудованием защищается составным FK (equipment_id, club_id), номера мест уникальны в клубе.

Переход заявки: транзакция, SELECT FOR UPDATE, сравнение optimistic version, проверка автомата, запись истории.
Закрытие защищено и сервисом, и CHECK PostgreSQL. Первый ответ хранится отдельно от решения.
Часовые пояса: события UTC/timestamptz, сроки осмотров date; два календарных месяца без переполнения месяца.
Например 31.12.2025 → 28.02.2026, 31.12.2023 → 29.02.2024.

Критическая заявка создаёт outbox в той же транзакции. Диспетчер/доставка — следующий этап.
Секреты, внешние интеграции и телеметрия не должны попадать в эту таблицу без схемы/ограничений.

## Источники совместимости

- https://laravel.com/docs/13.x/releases — PHP >= 8.3; выбран PHP 8.4.
- https://laravel.com/docs/13.x/horizon — Redis, pcntl/posix; запуск в Linux-контейнере.
- https://repo.packagist.org/p2/laravel/horizon.json — Horizon 5.50.0 допускает Illuminate 13.
- https://repo.packagist.org/p2/inertiajs/inertia-laravel.json — адаптер 3.4.0 допускает Laravel 13.
- https://github.com/docker-library/official-images — версии базовых образов проверены по каталогу.
- npm manifests — Vite 8 требует Node >=22.12; выбран Node 24.

Окончательная совместимость зависимостей будет подтверждена Composer/npm и тестами после запуска Docker.
