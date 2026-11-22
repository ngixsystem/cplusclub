# C+CLub — внешний API (v1), только чтение

Для внешних систем клуба (например, панели собственника). Каждый токен привязан к **одному клубу**,
может быть ограничен IP-адресами и даёт доступ только к выбранным разделам. Все запросы записываются в журнал.

## Подключение

- **Базовый адрес:** `https://admin.cplusclub.tech/api/ext/v1`
- **Авторизация:** заголовок `Authorization: Bearer <токен>` (токен начинается с `ccx_`)
- **Формат:** JSON, UTF-8
- **Время:** ISO 8601 в часовом поясе клуба, например `2026-11-22T16:30:00+05:00`
- **Лимит:** 120 запросов в минуту на токен; при превышении — `429` и заголовок `Retry-After` (секунды)

```bash
curl -H "Authorization: Bearer ccx_..." https://admin.cplusclub.tech/api/ext/v1/me
```

## Ошибки

Любая ошибка возвращается так:

```json
{ "error": { "code": "ip_not_allowed", "message": "Запрос с этого IP-адреса не разрешён для токена." } }
```

| HTTP | code | Когда |
|---|---|---|
| 401 | `unauthorized` | нет токена, токен неверный или отозван |
| 403 | `ip_not_allowed` | запрос не с разрешённого IP |
| 403 | `scope_denied` | у токена нет доступа к этому разделу |
| 409 | `not_connected` | клуб не подключён к iCafeCloud |
| 422 | `bad_request`, `bad_range` | неверные параметры |
| 429 | `rate_limited` | больше 120 запросов в минуту |
| 503 | `upstream_unavailable` | iCafeCloud временно не отвечает — повторите позже |

---

## GET /me

Проверка токена: какой клуб, какие разделы доступны.

```json
{
  "client": "Панель собственника SPOT",
  "club": { "id": 1, "name": "SPOT Gaming", "timezone": "Asia/Tashkent", "currency": "UZS" },
  "scopes": ["pcs", "shifts"],
  "rate_limit_per_minute": 120,
  "server_time": "2026-11-22T16:30:00+05:00"
}
```

## GET /pcs — текущий статус мест

Раздел `pcs`. Данные в реальном времени из iCafeCloud (кешируются на 15 секунд — чаще опрашивать нет смысла).
Включены и ПК, и игровые приставки.

| Поле | Тип | Описание |
|---|---|---|
| `id`, `name` | string | номер места, как в iCafeCloud (`PC042`) |
| `type` | `pc` \| `console` | ПК или приставка |
| `zone` | string \| null | зона (`Standard`, `VIP`, …) |
| `group_id` | int \| null | группа в iCafeCloud |
| `enabled` | bool | место включено в iCafeCloud |
| `status` | `busy` \| `free` \| `offline` | занят / свободен / ПК не на связи |
| `online` | bool \| null | клиент iCafe на ПК на связи (у приставок `null`) |
| `session` | object \| null | только для занятых мест |
| `session.member_id` | int \| null | id клиента в iCafeCloud (`null` — гость) |
| `session.member_account` | string \| null | логин клиента |
| `session.guest` | bool | гостевая сессия без аккаунта |
| `session.started_at` | string | начало сессии |
| `session.ends_at` | string \| null | плановое окончание |
| `session.minutes_left` | int \| null | минут осталось |

```json
{
  "data": [
    { "id": "PC001", "name": "PC001", "type": "pc", "zone": "Standard", "group_id": 1, "enabled": true,
      "status": "free", "online": true, "session": null },
    { "id": "PC042", "name": "PC042", "type": "pc", "zone": "VIP", "group_id": 3, "enabled": true,
      "status": "busy", "online": true,
      "session": { "member_id": 77, "member_account": "ali", "guest": false,
                   "started_at": "2026-11-22T15:10:00+05:00", "ends_at": "2026-11-22T17:10:00+05:00", "minutes_left": 95 } }
  ],
  "meta": { "total": 139, "busy": 41, "free": 90, "offline": 8, "fetched_at": "2026-11-22T16:30:05+05:00" }
}
```

## GET /shifts — кассовые смены

Раздел `shifts`. Смены из iCafeCloud, синхронизируются каждые 15 минут.

| Параметр | По умолчанию | Описание |
|---|---|---|
| `from` | `to` − 7 дней | начало периода по времени открытия смены (`2026-11-01` или `2026-11-01T08:00:00`) |
| `to` | сейчас | конец периода |
| `status` | все | `open` или `closed` |
| `limit` | 1000 | 1–1000 |

Период — не больше 92 дней за запрос. Сортировка по времени открытия.

| Поле | Тип | Описание |
|---|---|---|
| `id` | int | уникальный id записи в C+CLub |
| `shift_id` | string | id смены в iCafeCloud |
| `operator` | string | учётка оператора |
| `status` | `open` \| `closed` | открыта / закрыта |
| `started_at`, `ended_at` | string, string \| null | открытие и закрытие |
| `cash`, `card`, `qr`, `total` | number | суммы по способам оплаты и итог |
| `refund` | number \| null | возвраты (заполняется после закрытия смены) |
| `currency` | string | валюта |
| `updated_at` | string | когда запись последний раз обновлялась |

```json
{
  "data": [
    { "id": 812, "shift_id": "1401412", "operator": "operator1", "status": "closed",
      "started_at": "2026-11-21T20:13:02+05:00", "ended_at": "2026-11-22T08:23:18+05:00",
      "cash": 2765917.6, "card": 0, "qr": 0, "total": 2765917.6, "refund": 0, "currency": "UZS",
      "updated_at": "2026-11-22T16:15:03+05:00" }
  ],
  "meta": { "from": "2026-11-15T16:30:00+05:00", "to": "2026-11-22T16:30:00+05:00", "count": 1,
            "note": "Открытые смены обновляются каждые 15 минут; итог смены окончательный после её закрытия." }
}
```

Совет: чтобы держать смены в актуальном состоянии, раз в 15 минут запрашивайте последние 2 суток и обновляйте
записи по `id` — открытая смена закрывается и получает итоговые суммы.

---

## Дальше

| Раздел | Статус |
|---|---|
| Логи биллинга по событиям (`/billing-logs`, курсор `since`, история за 3 месяца) | в разработке |
| Чеки бара (`/bar-receipts`, позиции, отменённые и возвратные) | в разработке |
| Вебхуки (POST на ваш URL при событии) | после логов биллинга |
