# MatchIQ — архитектура Laravel-приложения

**Статус:** правила согласованы с пользователем (2026-11-25). Код под них создаётся по мере появления функциональности — пустых каталогов «на вырост» не заводим.  
**Принцип:** архитектура **не сложнее, чем нужно**, но чистая, понятная и легко расширяемая. Новый слой/абстракцию вводим, когда есть второй потребитель или дублирование, а не «на всякий случай».

Связанные документы: [ingestion.md](ingestion.md) — загрузка данных (Laravel ↔ RabbitMQ ↔ Go, SStats.net) и наблюдаемость; [../database/](../database/README.md) — схема БД.

**Ingestion (решение № 36, уточнено 2026-11-26):** с БД работает **только Laravel**. Laravel (`artisan` + scheduler; позже админка) создаёт `ingestion_runs` и кладёт **команду** в RabbitMQ; Go-коллектор (`collector/`) читает команду, ходит в SStats.net, нормализует в канон DTO и возвращает **результат** в RabbitMQ — в PostgreSQL Go не пишет; Laravel consumer сохраняет результат через репозитории и ведёт `ingestion_runs` / `ingestion_run_errors` / `external_ids`. Парсера/клиента источника в `web/app` нет. Подробности — [ingestion.md](ingestion.md).

## Слои и их ответственность

| Слой | Каталог (`web/app/`) | Что делает | Чего НЕ делает |
|------|----------------------|------------|----------------|
| **Controllers** | `Http/Controllers/` | **Только REST-методы** (`index`, `show`, `store`, `update`, `destroy`; для одиночных действий — invokable). Принимает Form Request, зовёт сервис/репозиторий, отдаёт Response/DTO | Бизнес-логика, запросы к БД, форматирование, приватные helper-методы, моки |
| **Form Requests** | `Http/Requests/` | Валидация и авторизация входа (`rules()`, `authorize()`); при необходимости `toDto()` | Бизнес-логика, обращения к БД кроме валидационных правил |
| **Responses / DTO** | `Http/Responses/`, `Data/` | `Http/Responses` — сборка ответа для Inertia/JSON (props страницы, API-ресурсы); `Data/` — неизменяемые DTO для передачи между слоями Laravel | Логика, обращения к БД. Канон DTO результата ingest (зеркало контракта Go) здесь допустим |
| **Services** | `Services/` | Сценарии приложения («use case»): оркестрируют домены, репозитории, события. Один класс — один сценарий или связный набор (`Ingestion/…` — старт прогона, применение результата из RabbitMQ, итог; без fetch/parse источника; `Predictions/…`) | HTTP (`Request`/`Response`), прямая вёрстка ответа, fetch/parse источника |
| **Domains** | `Domains/` | Чистая предметная логика без фреймворка: правила начисления очков, расчёт таблицы, тай-брейки, нормализация названий. Работает с DTO/значениями | БД, HTTP, очереди, `Auth` |
| **Repositories** | `Repositories/` | Все нетривиальные чтения/записи БД: сложные запросы, upsert, keyset-пагинация, срезы для горячих страниц ([performance.md](../database/performance.md)). Возвращают модели/коллекции/DTO | Бизнес-правила, HTTP |
| **Models** | `Models/` | Eloquent: связи, касты, scope'ы. Без тяжёлой логики | Сценарии, сложные запросы (они в репозиториях) |
| **Traits** | `Models/Concerns/` (для моделей), `Support/Concerns/` (общие) | Повторяемое поведение: `HasExternalIds`, `HasI18nNames` | Скрытая бизнес-логика; если trait растёт — выносим в сервис |
| **Enums** | `Enums/` | Строковые backed-enum'ы для статусов, типов, периодов (`MatchStatus`, `IngestionStatus`, …) — значения совпадают с `varchar`/`CHECK` в БД | Логика, не связанная со значением (допустимы `label()`, `isFinal()`) |
| **Helpers / Support** | `Support/` | Маленькие stateless-утилиты без зависимостей от слоёв (`RatingSeason`, `OAuthProviders`, `Database/PgSchema`). Глобальные функции-хелперы — только в `Support/helpers.php`, подключённом через Composer `autoload.files`, и только если действительно нужны | Складывать сюда сервисы и репозитории |
| **Policies / Middleware** | `Policies/`, `Http/Middleware/` | Доступ к ресурсам (админка: `users.role = admin`) | Бизнес-логика |
| **Console / Jobs** | `Console/Commands/`, `Jobs/` | Тонкие точки входа: разбирают аргументы и вызывают сервис | Своя логика — всё в сервисах |

Поток вызовов — только сверху вниз, без циклов:

```
Controller → FormRequest (DTO) → Service → Domain (чистая логика)
                                    └──────→ Repository → Model → PostgreSQL
Service → DTO / Model → Response (Inertia / JSON)
```

Правила зависимостей: `Domains` ни от чего не зависят; `Repositories` не вызывают сервисы; `Services` не знают про HTTP; контроллеры не знают про Eloquent-запросы.

## Дерево каталогов (целевое)

Уже существует (не ломаем): `Models/` (+ `Concerns/`), `Enums/`, `Support/` (+ `Database/`), `Http/{Controllers,Requests,Middleware}`, `Providers/`. Остальное появляется при первом использовании.

```
web/app/
├── Console/Commands/              # artisan-команды (тонкие): ingest:dispatch, ingest:consume, ingest:reap-stuck, standings:rebuild
├── Data/                          # DTO (readonly) между слоями Laravel, в т.ч. канон DTO результата ingest
├── Domains/                       # чистая предметная логика, без БД и HTTP
│   ├── Standings/                 #   TieBreakers, StandingsCalculator
│   ├── Rating/                    #   правила очков (сейчас Support/RatingRules → перенос по мере роста)
│   └── Naming/                    #   NameNormalizer (alias-ключи)
├── Enums/                         # MatchStatus, IngestionStatus, IngestionPhase, …
├── Http/
│   ├── Controllers/               # только REST-методы
│   │   └── Admin/                 #   Admin\IngestionRunController (index, show) и т.п.
│   ├── Middleware/
│   ├── Requests/                  # Form Requests (по ресурсам: Admin/, Auth/)
│   └── Responses/                 # сборка Inertia-props / JSON (MatchPageResponse, IngestionRunResponse)
├── Jobs/                          # очереди Laravel: RebuildStandings и др. (команды/результаты ingest идут через RabbitMQ, см. ingestion.md)
├── Models/
│   └── Concerns/                  # HasExternalIds, HasI18nNames
├── Policies/
├── Providers/
├── Repositories/                  # MatchRepository, StandingsRepository, IngestionRunRepository, ExternalIdRepository (вся запись ingest — здесь)
├── Services/
│   ├── Ingestion/                 # создать ingestion_runs и команду в RabbitMQ, применить результат (resolve + upsert), закрыть прогон. Fetch/parse источника — в collector/
│   └── Predictions/               # позже
└── Support/                       # мелкие утилиты (RatingSeason, OAuthProviders, Database/PgSchema)
    └── Concerns/                  # общие traits (если понадобятся)
```

Переходный момент: `Support/Mock*.php` — демо-данные до подключения БД; контроллеры пока читают их напрямую. При переходе на БД контроллер перестаёт обращаться к моку: данные приходят из сервиса/репозитория, а мок удаляется (или остаётся только в тестах/сидерах). Новые контроллеры такой логики и приватных helper-методов не получают.

## Контроллеры

- Только стандартные REST-действия (`index`, `create`, `store`, `show`, `edit`, `update`, `destroy`); нестандартное действие = отдельный invokable-контроллер или отдельный ресурс (`POST /admin/ingestion-runs` → `IngestionRunController@store`).
- Тело метода — 3–7 строк: валидированный вход → вызов сервиса/репозитория → ответ.
- Вспомогательная логика (фильтры дат, выбор лиг, форматирование) — в сервисах, `Data/`, `Http/Responses/` или `Support/`; не в контроллерах. Существующие приватные helper'ы в `MatchController` и др. при переходе с моков переезжают в эти слои.

## Именование и мелочи

- Классы — `PascalCase`, один класс на файл; сервис — глагол/сценарий (`RunIngestion`, `ResolveExternalEntity`), репозиторий — `<Сущность>Repository`, DTO — существительное (`NormalizedMatch`), Form Request — `<Действие><Сущность>Request`.
- Внешний мир кроме источников данных (платёжки, ML) — за интерфейсами в `Services/…`, привязка в `AppServiceProvider`/отдельном провайдере. Источники данных — Go-коллектор (общение с Laravel только через RabbitMQ): смена источника = новый адаптер в `collector/` без смены домена ([ingestion.md](ingestion.md)).
- Enum'ы вместо «магических строк» везде, где значения хранятся в БД; каст — в модели.
- Тесты: Feature — маршруты/БД (`tests/Feature`), Unit — `Domains` и `Support` (`tests/Unit`). Число запросов на горячих страницах проверяем тестами ([performance.md](../database/performance.md)).
- Миграции — как в [../database/](../database/README.md); «позже»-таблицы заранее не создаём.

## Что сознательно НЕ вводим

- Абстрактные базовые репозитории/сервисы, generic CRUD-обёртки, CQRS, Event Sourcing, пакеты модульного монолита.
- Интерфейс на каждый класс: интерфейсы — только на границе с внешним миром (платёжки, ML) и там, где реально будет вторая реализация. Адаптер источника — в Go-коллекторе, не интерфейс в `web/app`; Go не получает доступа к БД.
- Отдельный слой «Actions» поверх сервисов — сервис с одним публичным методом выполняет эту роль.
