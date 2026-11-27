# MatchIQ collector

Go-сервис сбора данных (ingestion). Читает команды из RabbitMQ (`ingest.commands`), ходит в SStats.net, нормализует ответ в канонические DTO и публикует результат (`batch` / `done` / `failed`) в `ingest.results`. **В PostgreSQL не пишет** — только Laravel. Схема и контракт: [../docs/architecture/ingestion.md](../docs/architecture/ingestion.md).

Go собирается и тестируется **только в Docker** (версия Go — аргумент `GO_VERSION` в `docker/collector/Dockerfile`, сейчас 1.27); локальный `go` на хосте не нужен.

```bash
docker compose --profile tools build collector-test       # gofmt + go vet + go test (stage test; тесты идут при сборке)
docker compose build collector                            # multi-stage сборка бинарника
docker compose up -d rabbitmq collector
```

Структура: `cmd/collector` (точка входа, `/healthz`), `internal/config`, `internal/amqp` (топология, consumer, publisher), `internal/sstats` (HTTP-клиент: apikey, rate limit, ретраи), `internal/jobs` (реестр `job_type` → сборщик: `sync_catalog`, `sync_structure`, `sync_fixtures`), `internal/dto` (конверт и канонические DTO).

Env: `RABBITMQ_URL` (обязателен), `SSTATS_API_KEY`, `SSTATS_BASE_URL`, `SSTATS_RATE_PER_MINUTE` (по умолчанию 30), `COLLECTOR_PREFETCH`, `COLLECTOR_BATCH_SIZE`, `COLLECTOR_ADDR`.
