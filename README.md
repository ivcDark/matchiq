# MatchIQ

Футбольная статистика с вероятностями событий по матчу (MVP: **РПЛ**).  
Стек: **Laravel + Vue + Inertia**, **PostgreSQL**, **Redis**. Сбор данных: **Laravel** создаёт джобы и **единственный пишет в БД**; **Go-коллектор** получает команды из **RabbitMQ**, собирает данные **SStats.net**, нормализует и возвращает результат в RabbitMQ (схема — [docs/architecture/ingestion.md](docs/architecture/ingestion.md)).

> Это каркас репозитория: маршруты и заглушки страниц, Docker Compose, stub collector.  
> Парсинг, ML/скоринг и продакшен-дизайн — отдельно, позже.

## Структура

```
.
├── web/                 # Laravel + Vue + Inertia
├── collector/           # Go collector (заглушка /healthz; в БД не пишет — RabbitMQ ↔ SStats.net)
├── docker/              # Dockerfiles для app и collector
├── docker-compose.yml
└── .env.example         # переменные Compose (секреты не коммитить)
```

## Архитектура (заложено с дня 1)

```
[Laravel scheduler / artisan] --команда--> [RabbitMQ] --> [Go collector] --> SStats.net
        |                                                        |
   ingestion_runs                                    normalize в канон DTO
        ^                                                        |
        |                                                        v
 [Laravel consumer] <------------ результат ------------ [RabbitMQ]
        |
        v
  [PostgreSQL]  (пишет только Laravel)
        |
 [Laravel / Inertia] --> [Vue UI]
```

- **Laravel:** все модели, репозитории и миграции. Artisan по расписанию создаёт `ingestion_runs` и кладёт команду в RabbitMQ; consumer забирает результат и сохраняет его через репозитории, обновляя `ingestion_runs`, `ingestion_run_errors`, `external_ids`. Клиента источника в PHP нет.
- **Collectors (Go):** читают команды из RabbitMQ, по типу джобы запускают сборщик, ходят в **SStats.net** (primary API, [обзор](docs/ingestion/README.md)), нормализуют в канон DTO и публикуют результат в RabbitMQ. **В PostgreSQL не пишут.** Смена источника — новый адаптер с тем же контрактом.
- **Domain в Postgres:** лиги, сезоны, команды, матчи, события — нейтрально к источнику. Мульти-лиговость: `leagues` (`rpl`, …), `providers`, маппинг внешних ID.
- **Схема БД (черновик):** [docs/database/](docs/database/README.md) — ER-диаграммы, таблицы по доменам, открытые вопросы.
- **Архитектура приложения и ingestion:** [docs/architecture/](docs/architecture/README.md) — слои Laravel (Services / Domains / Repositories / Form Requests / DTO / Enums, контроллеры только REST), [загрузка данных и наблюдаемость](docs/architecture/ingestion.md) (прогоны, ошибки по сущностям, смена источника).
- **Predictions:** пишутся отдельным job/сервисом; веб читает готовые вероятности.
- **Redis:** кэш, сессии, очереди Laravel. **RabbitMQ:** команды и результаты ingest между Laravel и Go.
- **MVP-лига:** РПЛ; UI и схема готовы к другим лигам без ветвления монолита.

## Как запустить (Docker / WSL)

Ожидаемый путь локально: `~/projects/matchiq` (или клон этого репо).

Требования: Docker + Docker Compose.

```bash
cp .env.example .env
# Сгенерируйте APP_KEY (нужен для Laravel внутри compose):
#   docker run --rm -v "$PWD/web:/app" -w /app composer:2 php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
# и вставьте в .env как APP_KEY=...

cp web/.env.example web/.env
# Синхронизируйте APP_KEY в web/.env (тот же ключ)

docker compose up --build
```

Сервисы (порты с хоста настраиваются через `.env`; см. `.env.example`):

| Сервис       | URL / порт (по умолчанию) | Назначение                |
|--------------|---------------------------|---------------------------|
| `app`        | http://localhost:8000     | Laravel                   |
| `vite`       | http://localhost:5173     | Vite HMR (`VITE_HOST_PORT`) |
| `postgres`   | localhost:5432            | PostgreSQL                |
| `redis`      | localhost:6379            | Redis                     |
| `collector`  | http://localhost:8081     | Go stub (`/healthz`)      |

Если `Bind for 0.0.0.0:5173 failed: port is already allocated` — остановите старый стек (`docker compose down`) или задайте свободный хост-порт, например `VITE_HOST_PORT=5174` в `.env`. Контейнер Vite по-прежнему слушает `5173` внутри сети; Laravel получает URL через `VITE_DEV_SERVER_URL`, HMR — через `clientPort`.

Остановка: `docker compose down`. Данные БД в volume `postgres_data`.

### Без полного Compose

```bash
# web (нужны локальные PHP 8.3+, Composer, Node 22, Postgres, Redis)
cd web
composer install
cp .env.example .env   # поправьте DB_HOST=127.0.0.1 и REDIS_HOST=127.0.0.1
php artisan key:generate
php artisan migrate
npm install && npm run dev

# collector
cd collector
go run ./cmd/collector
```

## MVP-маршруты (Inertia)

| URL                 | Page                    |
|---------------------|-------------------------|
| `GET /`             | `Matches/Index`         |
| `GET /matches/{id}` | `Matches/Show`          |
| `GET /leaderboard`  | `Leaderboard/Index`     |
| `GET /register`     | `Auth/Register`         |
| `GET /login`        | `Auth/Login`            |
| `GET /forgot-password` | `Auth/ForgotPassword` |
| `GET /me`           | `Profile/Show` (auth)   |

Страницы — минимальные заглушки (RU), без полированного UI.

## Что намеренно не сделано

- Скрапинг / адаптеры провайдеров
- Модель вероятностей и AI-превью
- Схема доменных таблиц матчей/событий (кроме стандартных users/cache/jobs Laravel)
- Продакшен-дизайн

## Лицензия / статус

Приватный MVP. Рабочее имя MatchIQ.
