---

description: "Task list for 001-app-foundation"
---

# Tasks: Fundament aplikacji payment-gateway

**Input**: Design documents from `/specs/001-app-foundation/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: wymagane. Sekcja „Proces wytwórczy i bramki jakości” w konstytucji nakazuje testy
Pest, a ich strukturę wyznacza plan. Testy piszemy przed implementacją w danej fazie
i najpierw sprawdzamy, że nie przechodzą.

**Organization**: zadania pogrupowane według historyjek ze `spec.md`: US1 (P1) środowisko,
US2 (P1) stan systemu, US3 (P2) mechanizmy przekrojowe.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: można wykonać równolegle (inny plik, brak zależności od niedokończonych zadań)
- **[Story]**: historyjka, do której należy zadanie (US1, US2, US3)

## Path Conventions

Pojedyncza aplikacja Laravel w katalogu głównym repozytorium: `app/`, `config/`, `routes/`,
`bootstrap/`, `tests/`. Pliki Docker leżą w katalogu głównym (research R1).

## Zasady wspólne dla wszystkich zadań

- Klasy tworzymy przez `php artisan make:* --no-interaction`: `make:class`, `make:enum`,
  `make:interface`, `make:trait`, `make:middleware`, `make:controller --invokable`,
  `make:command`, `make:test --pest [--unit]`. Potem uzupełniamy treść.
- PHP: jawne typy zwracane i typy parametrów, constructor property promotion, klamry w każdej
  strukturze sterującej, PHPDoc z array shapes zamiast komentarzy inline, klucze enumów
  w TitleCase.
- Po każdej zmianie PHP: `vendor/bin/pint --dirty --format agent`.
- Po każdej zmianie testu uruchamiamy najwęższy zestaw:
  `php artisan test --compact <ścieżka>`.
- Nie dodajemy zależności Composera. Nie usuwamy istniejących testów ani migracji.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: konfiguracja środowiska i frameworka, z której korzystają wszystkie historyjki

- [X] T001 [P] Zaktualizuj `.env.example`. Wartości: `APP_NAME=payment-gateway`,
      `APP_URL=http://localhost:8000`, `DB_CONNECTION=pgsql`, `DB_HOST=pgsql`, `DB_PORT=5432`,
      `DB_DATABASE=payment_gateway`, `DB_USERNAME=payment_gateway`, `DB_PASSWORD=secret`,
      `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `REDIS_CLIENT=phpredis`, `REDIS_HOST=redis`,
      `REDIS_PORT=6379`, `REDIS_QUEUE_RETRY_AFTER=90`, `MAIL_MAILER=smtp`,
      `MAIL_HOST=sandbox.smtp.mailtrap.io`, `MAIL_PORT=2525`, `MAIL_USERNAME=` (puste),
      `MAIL_PASSWORD=` (puste), `MAIL_TIMEOUT=5`, `MAIL_FROM_ADDRESS="payments@example.test"`,
      `LOG_CHANNEL=stack`, `LOG_STACK=stderr_json,daily_json`, `LOG_LEVEL=debug`,
      `PHP_CLI_SERVER_WORKERS=4`. Zostaw `SESSION_DRIVER=database` i usuń zakomentowane
      `DB_*` dla MySQL. Nad `MAIL_USERNAME` dodaj komentarz: „uzupełnij z Mailtrap Sandbox
      → SMTP Settings”.
- [X] T002 [P] W `phpunit.xml` dodaj `<env name="DB_CONNECTION" value="sqlite"/>` i zmień
      `DB_DATABASE` na `:memory:`. Zostaw `CACHE_STORE=array`, `QUEUE_CONNECTION=sync`,
      `MAIL_MAILER=array`. Dodaj `<env name="LOG_STACK" value="null"/>`, żeby testy nie pisały
      do `storage/logs`. Testy logów używają `Log::spy()` lub kanałów skonfigurowanych w teście.
- [X] T003 [P] W `config/queue.php`:
      (a) dla połączenia `redis` ustaw `'block_for' => (int) env('REDIS_QUEUE_BLOCK_FOR', 5)`;
      (b) dodaj sekcję `'retry_policy'` z wartościami z data-model §5:
      `'tries' => 5`, `'backoff' => [10, 30, 90, 270]`, `'timeout' => 60`,
      `'max_exceptions' => 3`. Dodaj PHPDoc z niezmiennikami: „tries ≥ 4”,
      „backoff ściśle rosnące”, „timeout < connections.redis.retry_after (90)”,
      „max_exceptions ≤ tries”. Zostaw `failed.driver = database-uuids`.
- [X] T004 [P] W `config/mail.php` dla mailera `smtp` ustaw
      `'timeout' => (int) env('MAIL_TIMEOUT', 5)`.
- [X] T005 [P] Utwórz `.dockerignore` z wpisami: `.git`, `node_modules`, `vendor`,
      `storage/logs/*`, `storage/framework/cache/*`, `.env`, `specs`, `.specify`, `.claude`.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: logi JSON z maskowaniem i identyfikator korelacji. Każda historyjka loguje błędy
i korzysta z `correlation_id` (Zasada IV).

**⚠️ CRITICAL**: prace nad historyjkami zaczynamy dopiero po ukończeniu tej fazy.

### Tests (Foundational)

- [X] T006 [P] Napisz test jednostkowy `tests/Unit/Logging/RedactSensitiveDataTest.php`
      (`make:test --pest --unit Logging/RedactSensitiveDataTest`). Przypadki:
      - klucze `password`, `X-Api-Key`, `stripe-signature`, `card_number`, `Authorization`
        (także zagnieżdżone w `context.request.body` i w `extra`) mają wartość `[REDACTED]`;
      - dopasowanie działa tak: „znormalizowana nazwa klucza (małe litery, bez `-` i `_`)
        **zawiera** wzorzec”;
      - klucze niewrażliwe (`event_id`, `amount`) zostają bez zmian;
      - string dłuższy niż 10 000 znaków jest obcinany do 10 000 znaków z dopiskiem
        `…[truncated N chars]`, gdzie N to liczba usuniętych znaków;
      - procesor nie zmienia `message` ani `level`.
- [X] T007 [P] Napisz test feature `tests/Feature/Http/CorrelationIdTest.php`. Przypadki:
      - żądanie bez nagłówka → odpowiedź ma `X-Correlation-ID` w formacie UUIDv7;
      - nagłówek `X-Correlation-ID: abc-123.x:y_z` → ta sama wartość w odpowiedzi
        i w `Context::get('correlation_id')`;
      - nagłówek niezgodny z `^[A-Za-z0-9._:-]{1,128}$` (np. 129 znaków albo
        `"a b\n"`) → wygenerowany nowy UUIDv7;
      - wpis logu zapisany w trakcie żądania zawiera `correlation_id` w `extra`
        (sprawdź przez kanał testowy z `TestHandler` Monologa).

### Implementation (Foundational)

- [X] T008 Utwórz procesor Monologa `app/Logging/RedactSensitiveData.php`
      (`make:class Logging/RedactSensitiveData`). Wymagania:
      - implementuje `Monolog\Processor\ProcessorInterface`;
      - rekurencyjnie przetwarza `context` i `extra` `LogRecord`;
      - klucze do maskowania czyta z `config('logging.redact.keys')`, wartość zastępczą
        z `config('logging.redact.replacement')`, limit z
        `config('logging.redact.max_string_length')`;
      - zwraca `$record->with(context: …, extra: …)`.
      Po implementacji T006 musi przechodzić.
- [X] T009 W `config/logging.php`:
      (a) dodaj sekcję `'redact'` z wartościami z data-model §6:
      `'keys' => ['password', 'secret', 'token', 'apikey', 'authorization', 'signature',
      'cardnumber', 'pan', 'cvv', 'cvc', 'cookie', 'iban']`,
      `'replacement' => '[REDACTED]'`, `'max_string_length' => 10000`;
      (b) dodaj kanał `stderr_json`: driver `monolog`, handler `StreamHandler` na
      `php://stderr`, `formatter` `Monolog\Formatter\JsonFormatter`,
      `processors` `[App\Logging\RedactSensitiveData::class]`, level `env('LOG_LEVEL', 'debug')`;
      (c) dodaj kanał `daily_json`: driver `daily`, `path` `storage_path('logs/laravel.json')`,
      `days` 14, ten sam formatter i procesor;
      (d) domyślne `LOG_STACK` zmień na `stderr_json,daily_json`.
- [X] T010 Utwórz middleware `app/Http/Middleware/AssignCorrelationId.php`
      (`make:middleware AssignCorrelationId`). Działanie:
      - czyta nagłówek `X-Correlation-ID` i akceptuje go tylko, gdy pasuje do
        `^[A-Za-z0-9._:-]{1,128}$`; w przeciwnym razie używa `(string) Str::uuid7()`;
      - zapisuje wartość przez `Context::add('correlation_id', $id)`;
      - ustawia nagłówek odpowiedzi `X-Correlation-ID`.
- [X] T011 W `bootstrap/app.php`, w `withMiddleware`, zarejestruj `AssignCorrelationId`
      jako pierwszy middleware globalny (`$middleware->prepend(...)`). Potem T007 musi
      przechodzić.
- [X] T012 Uruchom `php artisan test --compact tests/Unit/Logging tests/Feature/Http/CorrelationIdTest.php`
      i `vendor/bin/pint --dirty --format agent`. Oba muszą być zielone.

**Checkpoint**: logi JSON z maskowaniem i korelacją działają, można zaczynać historyjki.

---

## Phase 3: User Story 1 - Uruchomienie całego środowiska jedną komendą (Priority: P1) 🎯 MVP

**Goal**: `docker compose up -d` na świeżym klonie uruchamia `init` (zależności, klucz,
migracje), `app`, `worker`, `pgsql` i `redis`. Aplikacja odpowiada na pustej bazie, a worker
jest wznawiany po awarii.

**Independent Test**: kroki 1, 3 i 4 z `quickstart.md`:
- `docker compose ps` pokazuje `init` jako exited(0), a pozostałe usługi jako running;
- `curl http://localhost:8000/up` zwraca 200;
- po `SIGKILL` worker wraca w ciągu 30 s;
- `down` i `up` zachowują dane.

### Tests for User Story 1

- [X] T013 [P] [US1] Napisz test feature `tests/Feature/AppBootTest.php`. Przypadki:
      - przy pustej bazie (`RefreshDatabase`, bez seedów) `GET /` zwraca 200 i JSON
        `{"service": "payment-gateway", "status": "running"}` z nagłówkiem `X-Correlation-ID`;
      - `GET /up` zwraca 200;
      - `Log::spy()` nie odnotowuje wywołań `error` w trakcie obu żądań.

### Implementation for User Story 1

- [X] T014 [US1] W `routes/web.php` zamień trasę `/` (widok `welcome`) na JSON:
      `response()->json(['service' => config('app.name'), 'status' => 'running'])`.
      Nie usuwaj `resources/views/welcome.blade.php`. Potem T013 i istniejący
      `tests/Feature/ExampleTest.php` muszą przechodzić.
- [X] T015 [P] [US1] Utwórz `Dockerfile` w katalogu głównym:
      - `FROM php:8.5-cli`;
      - `COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/`;
      - `RUN install-php-extensions pdo_pgsql redis pcntl zip intl`;
      - `COPY --from=composer:2 /usr/bin/composer /usr/bin/composer`;
      - zainstaluj `git unzip postgresql-client`;
      - `WORKDIR /var/www/html`;
      - użytkownik nie-root `app` z UID/GID z build args `UID=1000`, `GID=1000`;
      - `EXPOSE 8000`.
      Bez `COPY` kodu: kod przychodzi z bind mounta (research R1).
- [X] T016 [US1] Utwórz `compose.yaml` z usługami `pgsql` i `redis`:
      - `pgsql`: `image: postgres:18-alpine`; env `POSTGRES_DB`, `POSTGRES_USER`,
        `POSTGRES_PASSWORD` z `${DB_DATABASE}`, `${DB_USERNAME}`, `${DB_PASSWORD}`
        (domyślne jak w T001); wolumen `pgsql-data:/var/lib/postgresql`; healthcheck
        `pg_isready -U ${DB_USERNAME} -d ${DB_DATABASE}` (interval 5s, retries 20);
      - `redis`: `image: redis:8-alpine`; `command: redis-server --appendonly yes`; wolumen
        `redis-data:/data`; healthcheck `redis-cli ping` (interval 5s, retries 20);
      - zadeklaruj wolumeny `pgsql-data` i `redis-data`.
      Nie wystawiaj portów `pgsql` i `redis` na hosta.
- [X] T017 [US1] Dodaj do `compose.yaml` kotwicę `x-app-base` wspólną dla `init`, `app`
      i `worker`:
      - `build: .`, `image: payment-gateway-php`;
      - `volumes: ['.:/var/www/html']`;
      - `environment`: `DB_HOST=pgsql`, `REDIS_HOST=redis`, `QUEUE_CONNECTION=redis`,
        `CACHE_STORE=redis`.
      Następnie dodaj usługę `init` (`<<: *app-base`, `restart: "no"`, `depends_on` `pgsql`
      i `redis` z `condition: service_healthy`) z `command: ["sh", "-ec", "..."]`, która
      kolejno:
      1. `[ -f .env ] || cp .env.example .env`;
      2. `[ -f vendor/autoload.php ] || composer install --no-interaction --prefer-dist`;
      3. `grep -q '^APP_KEY=base64:' .env || php artisan key:generate --force`;
      4. `php artisan migrate --force --isolated --no-interaction`.
      Każdy błąd kończy `init` kodem ≠ 0 (`set -e`).
- [X] T018 [US1] Dodaj do `compose.yaml` usługę `app`:
      - `<<: *app-base`;
      - `command: php artisan serve --host=0.0.0.0 --port=8000`;
      - `environment` dodatkowo `PHP_CLI_SERVER_WORKERS=4`;
      - `ports: ['${APP_PORT:-8000}:8000']`;
      - `restart: unless-stopped`;
      - `depends_on: init: condition: service_completed_successfully`;
      - healthcheck: `curl -fsS http://localhost:8000/up` (interval 10s, start_period 20s).
      Zainstaluj `curl` w T015, jeśli go brakuje.
- [X] T019 [US1] Dodaj do `compose.yaml` usługę `worker`:
      - `<<: *app-base`;
      - `command: php artisan queue:work redis --queue=default --sleep=3 --tries=5 --backoff=10,30,90,270 --timeout=60 --max-time=3600`;
      - `restart: unless-stopped`;
      - `stop_signal: SIGTERM`, `stop_grace_period: 70s`;
      - `depends_on: init: condition: service_completed_successfully`.
      Healthcheck dodaje T037 (US2).
- [X] T020 [US1] Zweryfikuj ręcznie kroki 1, 3 i 4 z `quickstart.md`:
      - świeży stan: `docker compose down -v`, usuń `vendor/`, skopiuj `.env.example` do `.env`;
      - `docker compose up -d`: `init` kończy się exit 0, `app` jest healthy, `worker` running;
      - `curl -i localhost:8000/up` → 200, `curl localhost:8000/` → JSON z T014;
      - `docker compose logs app worker | grep '"level_name":"ERROR"'` → brak wyników (SC-004);
      - `docker compose kill -s SIGKILL worker` → worker running w ≤ 30 s (SC-003);
      - `docker compose down && docker compose up -d` → `init` pokazuje „Nothing to migrate”.
      Zanotuj odchylenia i popraw `Dockerfile` / `compose.yaml`.

**Checkpoint**: środowisko startuje jedną komendą, a MVP jest gotowe do demonstracji.

---

## Phase 4: User Story 2 - Weryfikacja stanu systemu (Priority: P1)

**Goal**: `GET /health` i `php artisan app:health` raportują stan komponentów `app`,
`database`, `queue`, `cache`, `worker` i `mail`, podając przyczynę dla każdego
niedziałającego. Mailtrap sprawdzamy połączeniem z uwierzytelnieniem, bez wysyłki.

**Independent Test**: krok 2 z `quickstart.md`:
- wszystko `up` → 200 i exit 0;
- `docker compose stop redis` → 503 oraz `queue`, `cache` i `worker` `down`, z logami `error`;
- błędne `MAIL_PASSWORD` → `mail down`.

### Tests for User Story 2

- [X] T021 [P] [US2] Napisz test feature `tests/Feature/Health/HealthEndpointTest.php`
      zgodny z `contracts/health-http.md`. Przypadki:
      - wszystkie sprawdzenia `up` (podmień implementacje `HealthCheck` w kontenerze na
        stuby) → 200, `status: "up"`, `components` w kolejności
        `app, database, queue, cache, worker, mail`, `Cache-Control: no-store`,
        `correlation_id` równy nagłówkowi;
      - jedno sprawdzenie zwraca `down` → 503, `status: "down"`, `reason` niepusty tylko
        dla tego komponentu;
      - sprawdzenie rzuca wyjątek → komponent `down`, odpowiedź 503 (nie 500)
        i `Log::error` wywołany z `component`, `reason`, `exception`;
      - `reason` w HTTP nie zawiera wartości `config('mail.mailers.smtp.host')`,
        `config('mail.mailers.smtp.username')` ani `config('database.connections.pgsql.host')`;
      - drugie wywołanie w ciągu 60 s nie uruchamia ponownie sprawdzenia `mail`
        (licznik wywołań w stubie).
- [X] T022 [P] [US2] Napisz test feature `tests/Feature/Health/CheckHealthCommandTest.php`
      zgodny z `contracts/health-cli.md`. Przypadki:
      - wszystko `up` → exit 0 i tabela z kolumnami `Component | Status | Reason | Latency`;
      - jeden `down` → exit 1;
      - `--only=worker` uruchamia tylko `WorkerCheck`;
      - `--only=foo` → exit 2 z komunikatem o dozwolonych wartościach;
      - `--json` zwraca ten sam schemat co `GET /health`;
      - komenda zawsze sprawdza `mail` na świeżo, z pominięciem cache.
- [X] T023 [P] [US2] Napisz test feature `tests/Feature/Health/WorkerHeartbeatTest.php`.
      Przypadki:
      - `WorkerHeartbeat::record()` zapisuje klucz `health:worker:heartbeat` (Unix timestamp,
        TTL 300 s);
      - dwa wywołania w odstępie < 10 s zapisują tylko raz (użyj `Carbon::setTestNow()`);
      - `WorkerCheck` zwraca `up`, gdy „now − heartbeat < 90 s”, i `down` z przyczyną
        „No heartbeat for N s”, gdy ≥ 90 s;
      - brak klucza → `down` z przyczyną „No worker heartbeat recorded”;
      - wyjątek magazynu cache → `down` z przyczyną „Heartbeat unavailable: cache store
        unreachable”.
- [X] T024 [P] [US2] Napisz test feature `tests/Feature/Health/MailTransportCheckTest.php`.
      Przypadki:
      - zamockowany transport SMTP, którego `start()` się udaje → `up`, a `stop()` zostaje
        wywołane, zaś `send()` nie;
      - `start()` rzuca `TransportException` (np. „535 Authentication failed”) → `down`
        z przyczyną „SMTP authentication failed” w trybie HTTP oraz klasą i komunikatem
        wyjątku w trybie CLI, plus `Log::error`;
      - puste `MAIL_USERNAME` → `down` z przyczyną „Mail credentials not configured”,
        bez próby połączenia.

### Implementation for User Story 2

- [X] T025 [P] [US2] Utwórz enum `app/Services/Health/Component.php` (string-backed):
      `App='app'`, `Database='database'`, `Queue='queue'`, `Cache='cache'`,
      `Worker='worker'`, `Mail='mail'`. Kolejność przypadków określa kolejność w raporcie.
- [X] T026 [P] [US2] Utwórz enum `app/Services/Health/ComponentState.php` (string-backed):
      `Up='up'`, `Down='down'`.
- [X] T027 [US2] Utwórz readonly VO `app/Services/Health/ComponentStatus.php` z polami:
      `Component $name`, `ComponentState $state`, `?string $reason`, `?int $latencyMs`,
      `CarbonImmutable $checkedAt`. Dodaj fabryki `up()` i `down(string $reason)`.
      Niezmiennik: „`reason` jest `null` wtedy i tylko wtedy, gdy `state = Up`”; w przeciwnym
      razie `InvalidArgumentException`. Dodaj `toArray()` zwracające
      `{name, status, reason, latency_ms}`, gdzie `state` jest serializowany jako `status`.
- [X] T028 [US2] Utwórz readonly VO `app/Services/Health/HealthReport.php`. Wymagania:
      - pola: `ComponentState $status` („`Up`, gdy wszystkie komponenty mają `Up`; inaczej
        `Down`”), `CarbonImmutable $checkedAt`, `string $correlationId`,
        `list<ComponentStatus> $components`;
      - `toArray()` zgodne ze schematem z `contracts/health-http.md`; PHPDoc z array shape.
- [X] T029 [US2] Utwórz interfejs `app/Services/Health/Checks/HealthCheck.php` z metodami
      `component(): Component` i `check(bool $detailed): ComponentStatus`.
      `$detailed=true` oznacza tryb CLI, w którym `reason` może zawierać klasę wyjątku,
      ale nigdy sekretów.
- [X] T030 [P] [US2] Utwórz `app/Services/Health/Checks/DatabaseCheck.php`. Wykonuje
      `DB::connection()->select('select 1')` i mierzy `latency_ms`. Przy wyjątku zwraca
      `down` z przyczyną „Database connection failed”; w trybie detailed dochodzi klasa
      i komunikat wyjątku.
- [X] T031 [P] [US2] Utwórz `app/Services/Health/Checks/QueueCheck.php`. Wykonuje
      `Queue::connection(config('queue.default'))->size('default')`. Przy wyjątku zwraca
      `down` z przyczyną „Queue broker connection refused” lub komunikatem wyjątku
      w trybie detailed.
- [X] T032 [P] [US2] Utwórz `app/Services/Health/Checks/CacheCheck.php`. Wykonuje
      `put` / `get` / `forget` klucza `health:cache:probe:<uuid>` z TTL 10 s i sprawdza,
      że odczyt zwraca zapisaną wartość. Przy rozbieżności lub wyjątku zwraca `down`
      z przyczyną „Cache store connection refused” lub „Cache read-back mismatch”.
- [X] T033 [US2] Utwórz `app/Services/Health/WorkerHeartbeat.php` z metodami `record(): void`
      i `lastSeenAt(): ?CarbonImmutable`. Wymagania:
      - klucz `health:worker:heartbeat`, wartość Unix timestamp, TTL 300 s;
      - throttling w pamięci procesu: najwyżej jeden zapis na 10 s.
      Wyjątki z `record()` nie są połykane: są logowane na poziomie `error` i rzucane dalej
      (Zasada IV).
- [X] T034 [US2] Utwórz `app/Services/Health/Checks/WorkerCheck.php`. Na podstawie
      `WorkerHeartbeat::lastSeenAt()` zwraca `up`, gdy „now − heartbeat < 90 s”.
      Pozostałe przypadki i przyczyny jak w T023. W trybie detailed `reason`/info podaje
      „last heartbeat N s ago”.
- [X] T035 [US2] Utwórz `app/Services/Health/Checks/MailTransportCheck.php`. Wymagania:
      - brak `config('mail.mailers.smtp.username')` → `down` „Mail credentials not configured”;
      - w przeciwnym razie `Mail::mailer('smtp')->getSymfonyTransport()`, potem `start()`,
        potem `stop()` w `finally`, bez wysyłki;
      - `TransportException` z kodem 535 lub tekstem „auth” → „SMTP authentication failed”,
        inne → „SMTP connection failed”;
      - gdy `$detailed=false`, wynik jest cache'owany 60 s pod kluczem
        `health:mail:last_result`; gdy `$detailed=true`, zawsze sprawdza na świeżo.
- [X] T036 [US2] Utwórz `app/Services/Health/HealthChecker.php`. Wymagania:
      - przez constructor promotion przyjmuje `iterable<HealthCheck>` (zarejestrowane
        w kontenerze jako tagged `health.checks`);
      - metoda `run(bool $detailed, ?array $only = null): HealthReport` uruchamia
        sprawdzenia w kolejności enuma `Component`; komponent `App` zawsze zwraca `up`;
      - każdy wyjątek z `check()` zamienia na `ComponentStatus::down()` i loguje
        `Log::error('Health check failed', ['component' => …, 'reason' => …, 'exception' => $e])`;
      - każdy wynik `down` loguje na poziomie `error`, nawet bez wyjątku;
      - `correlation_id` bierze z `Context::get('correlation_id')`.
- [X] T037 [US2] W `app/Providers/AppServiceProvider.php`:
      (a) w `register()` zarejestruj `DatabaseCheck`, `QueueCheck`, `CacheCheck`,
      `WorkerCheck` i `MailTransportCheck` z tagiem `health.checks` oraz `WorkerHeartbeat`
      jako singleton;
      (b) w `boot()` dodaj `Queue::looping()`, `Queue::before()` i `Queue::after()`, które
      wywołują `app(WorkerHeartbeat::class)->record()` (research R7).
      W tym samym zadaniu w `compose.yaml` dodaj healthcheck usługi `worker`:
      `php artisan app:health --only=worker` (interval 15s, start_period 30s).
- [X] T038 [US2] Utwórz invokable controller `app/Http/Controllers/HealthController.php`.
      Wywołuje `HealthChecker::run(detailed: false)` i zwraca `response()->json($report->toArray(), $status)`,
      gdzie `$status` to 200 dla `up` i 503 dla `down`, z nagłówkiem `Cache-Control: no-store`.
      W `routes/web.php` dodaj `Route::get('/health', HealthController::class)->name('health')`.
- [X] T039 [US2] Utwórz komendę `app/Console/Commands/CheckHealthCommand.php`
      (`make:command CheckHealthCommand`) z sygnaturą
      `app:health {--only=* : …} {--json : …}`. Wymagania:
      - na starcie, jeśli brak, ustawia `Context::add('correlation_id', (string) Str::uuid7())`
        i wypisuje „Correlation ID: …”;
      - waliduje `--only` względem wartości enuma `Component` (błąd → exit 2);
      - wywołuje `HealthChecker::run(detailed: true, only: …)`;
      - wypisuje tabelę `Component | Status | Reason | Latency` albo JSON;
      - zwraca exit 0 lub 1.
- [X] T040 [US2] Uruchom `php artisan test --compact tests/Feature/Health` i
      `vendor/bin/pint --dirty --format agent`, potem ręcznie wykonaj krok 2 z
      `quickstart.md` w `docker compose`: wszystko `up`, `stop redis` → 503, błędne
      `MAIL_PASSWORD` → `mail down`. Sprawdź, że wynik pojawia się w ≤ 2 min od `up` (SC-002).

**Checkpoint**: US1 i US2 działają. Kryteria akceptacji „worker i kolejki działają”
oraz „Mailtrap odpowiada poprawnie” są weryfikowalne komendą i endpointem.

---

## Phase 5: User Story 3 - Przekrojowe mechanizmy odporności i obserwowalności (Priority: P2)

**Goal**:
- uszkodzone i zbyt duże żądania kończą się 400/413 z logiem;
- wszystkie błędy HTTP mają jednolity format JSON;
- błędy walidacji i wyjątki są logowane z treścią żądania;
- porażki zadań są logowane z payloadem;
- jest gotowa polityka ponowień;
- blokady, opóźnienia i dead-letter są potwierdzone na prawdziwej infrastrukturze.

**Independent Test**: krok 5 z `quickstart.md` (400 i 413 z `correlation_id` i zamaskowanym
hasłem w logu), `queue:failed` pusty po starcie oraz zielona grupa `integration`
w kontenerze.

### Tests for User Story 3

- [X] T041 [P] [US3] Napisz test feature `tests/Feature/Http/MalformedPayloadTest.php`
      zgodny z `contracts/http-errors.md`. Przypadki:
      - `POST` z `Content-Type: application/json` i ciałem `{"event_id": "evt_1",` → 400,
        `error.code = malformed_payload`, `error.correlation_id` równy nagłówkowi;
        `Log::error` wywołany z metodą, ścieżką, nagłówkami i surowym ciałem;
      - ciało JSON z `"password":"s3cret"` → w kontekście logu po przejściu przez
        `RedactSensitiveData` nie ma `s3cret`;
      - ciało > 1 MiB (1 048 576 bajtów) → 413, `payload_too_large`;
      - poprawny JSON i puste ciało przechodzą dalej (np. `GET /health` → nie 400);
      - żadne z powyższych żądań nie zwraca 5xx.
- [X] T042 [P] [US3] Napisz test feature `tests/Feature/Http/ErrorFormatTest.php`.
      Zarejestruj w teście trasy pomocnicze przez `Route::post(...)` w `beforeEach`.
      Przypadki:
      - trasa rzucająca `RuntimeException` → 500, `error.code = internal_error`,
        brak treści wyjątku przy `APP_DEBUG=false`, `Log::error` z wyjątkiem, `correlation_id`
        i ciałem żądania;
      - trasa z `$request->validate(['event_id' => 'required'])` bez pola → 422,
        `validation_failed`, `error.details.event_id`, błąd zalogowany
        (`stopIgnoring(ValidationException)`);
      - nieistniejąca trasa → 404 `not_found`, bez logu;
      - zła metoda → 405 `method_not_allowed`.
- [X] T043 [P] [US3] Napisz test feature `tests/Feature/Queue/JobFailureLoggingTest.php`.
      Użyj klasy zadania zdefiniowanej w pliku testu (implementuje `ShouldQueue`, używa
      `UsesDefaultRetryPolicy`, a `handle()` rzuca wyjątek). Przypadki:
      - zdarzenie `JobExceptionOccurred` → `Log::error` z kluczami `job`, `queue`, `attempt`,
        `uuid`, `payload` i `exception`;
      - zdarzenie `JobFailed` → `Log::error('Job moved to dead-letter', …)` z tymi samymi
        kluczami;
      - wpis w `failed_jobs` istnieje po uruchomieniu na połączeniu `database` z `tries=1`.
- [X] T044 [P] [US3] Napisz test jednostkowy `tests/Unit/Queue/RetryPolicyTest.php`.
      Pilnuje niezmienników `config('queue.retry_policy')`:
      - „tries ≥ 4”;
      - „backoff ściśle rosnące”;
      - „timeout < config('queue.connections.redis.retry_after')”;
      - „max_exceptions ≤ tries”.
      Sprawdza też, że klasa używająca traita `UsesDefaultRetryPolicy` zwraca te wartości
      z `$tries`, `$timeout`, `$maxExceptions` i `backoff()`.
- [X] T045 [P] [US3] Napisz test integracyjny `tests/Feature/Integration/AtomicLockTest.php`
      (`->group('integration')`, wymaga Redisa). Uruchamia 100 równoległych zadań przez
      `Concurrency::driver('process')->run([...])`; każde wykonuje
      `Cache::store('redis')->lock('it:lock:<uuid testu>', 10)->get()` i zwraca bool.
      Asercja: dokładnie 1 `true` (SC-006). Brak Redisa ma powodować błąd testu, nie
      `skip` (research R16).
- [X] T046 [P] [US3] Napisz test integracyjny `tests/Feature/Integration/DelayedDispatchTest.php`
      (`->group('integration')`). Zadanie testowe wysłane na połączenie `redis`
      z `->delay(5)` nie jest dostępne przez `Queue::connection('redis')->pop()` przed
      upływem 5 s, a po upływie jest (FR-022). Użyj osobnej kolejki `it-delay-<uuid>`
      i posprzątaj ją po teście.
- [X] T047 [P] [US3] Napisz test integracyjny `tests/Feature/Integration/DeadLetterPersistenceTest.php`
      (`->group('integration')`, PostgreSQL i Redis). Przypadki:
      - zadanie z `tries=1`, które rzuca wyjątek, przetworzone przez
        `Artisan::call('queue:work', ['connection' => 'redis', '--queue' => 'it-dlq-<uuid>', '--once' => true])`,
        trafia do `failed_jobs`;
      - `payload.illuminate:log:context` zawiera `correlation_id` ustawiony przed dispatchem;
      - `queue:retry <uuid>` przywraca zadanie na kolejkę.
      Posprzątaj dane po teście.

### Implementation for User Story 3

- [X] T048 [US3] Utwórz middleware `app/Http/Middleware/RejectMalformedPayload.php`.
      Wymagania:
      - jeśli `strlen($request->getContent()) > 1048576` → 413;
      - jeśli `$request->isJson()`, ciało niepuste i `! json_validate($content)` → 400;
      - oba przypadki: `Log::error('Rejected malformed request', ['method', 'path', 'headers', 'body'])`
        oraz odpowiedź JSON zgodna z `contracts/http-errors.md`
        (`error.code`, `error.message`, `error.correlation_id`).
      Zarejestruj w `bootstrap/app.php` jako globalny middleware zaraz po `AssignCorrelationId`
      (`$middleware->append(...)` po prepend z T011).
- [X] T049 [US3] W `bootstrap/app.php`, w `withExceptions`:
      (a) zastąp `shouldRenderJsonWhen` wywołaniem `fn (): bool => true`;
      (b) dodaj `$exceptions->render(...)` mapujący wyjątki na format z
      `contracts/http-errors.md`: `NotFoundHttpException` → 404 `not_found`,
      `MethodNotAllowedHttpException` → 405 `method_not_allowed`, `ValidationException` →
      422 `validation_failed` + `details`, `HttpException` 503 → `service_unavailable`,
      pozostałe → 500 `internal_error` (komunikat ogólny, gdy `! config('app.debug')`);
      zawsze z `correlation_id` z `Context`;
      (c) `$exceptions->stopIgnoring(ValidationException::class)`;
      (d) `$exceptions->context(fn (): array => …)`: gdy aplikacja obsługuje żądanie HTTP,
      dodaje `request` z metodą, ścieżką, nagłówkami i ciałem (maskowanie robi procesor z T008);
      (e) `$exceptions->dontReport([NotFoundHttpException::class, MethodNotAllowedHttpException::class])`.
- [X] T050 [P] [US3] Utwórz trait `app/Jobs/Concerns/UsesDefaultRetryPolicy.php`
      (`make:trait Jobs/Concerns/UsesDefaultRetryPolicy`). Wymagania:
      - inicjalizuje `public int $tries`, `public int $timeout` i `public int $maxExceptions`
        z `config('queue.retry_policy.*')` w metodzie `initializeUsesDefaultRetryPolicy()`
        lub przez gettery `tries()` / `timeout()`; wybierz mechanizm wspierany przez kolejkę
        Laravel 13 i zweryfikuj go przez `search-docs`;
      - definiuje `backoff(): array` zwracające `config('queue.retry_policy.backoff')`;
      - PHPDoc wyjaśnia, że job MUSI nadal implementować `failed(Throwable $exception)`
        (Zasada III).
- [X] T051 [US3] W `app/Providers/AppServiceProvider.php::boot()` dodaj listenery
      `Event::listen(JobExceptionOccurred::class, …)` i `Event::listen(JobFailed::class, …)`.
      Logują `Log::error` z kluczami `job` (`$event->job->resolveName()`), `queue`,
      `connection`, `attempt` (`$event->job->attempts()`), `uuid`, `payload`
      (`$event->job->payload()`) i `exception`. Komunikaty: „Job attempt failed” oraz
      „Job moved to dead-letter” (research R11).
- [X] T052 [US3] Uruchom `php artisan test --compact tests/Feature/Http tests/Feature/Queue tests/Unit/Queue`.
      Potem w kontenerze uruchom
      `docker compose exec app php artisan test --compact --group=integration`
      i ręcznie krok 5 oraz 6 z `quickstart.md`.

**Checkpoint**: wszystkie trzy historyjki działają niezależnie, a fundament jest gotowy
pod specyfikację webhooków.

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: domknięcie bramek jakości z konstytucji

- [X] T053 Uruchom `vendor/bin/pint --format agent` dla wszystkich zmienionych plików PHP
      i popraw zgłoszone problemy.
- [X] T054 Uruchom pełny zestaw szybkich testów
      `php artisan test --compact --exclude-group=integration` na hoście oraz
      `docker compose exec app php artisan test --compact` (wszystkie grupy) w kontenerze.
      Wszystko ma być zielone.
- [X] T055 Przejrzyj kod pod kątem bramki code review z konstytucji. Szukaj:
      pustych `catch`, wyjątków połykanych bez logu `error`, odpowiedzi 2xx mimo błędu,
      logów sklejanych w string zamiast tablicy kontekstu,
      `grep -rn "catch" app/` i `grep -rn "Log::" app/`. Popraw znalezione naruszenia.
- [X] T056 Wykonaj pełny `quickstart.md` (kroki 1–7) od świeżego klonu
      (`docker compose down -v`, usuń `vendor/` i `.env`) i potwierdź SC-001 … SC-008.
      Zapisz wyniki i czasy w notatce do review (poza repozytorium) lub w opisie PR.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: bez zależności. T001–T005 można robić równolegle.
- **Foundational (Phase 2)**: zależy od T002 (phpunit) i T009 (konfiguracja). Blokuje
  wszystkie historyjki.
- **US1 (Phase 3)**: zależy od Phase 2.
- **US2 (Phase 4)**: zależy od Phase 2. Do testów automatycznych nie potrzebuje US1.
  Ręczna weryfikacja w T040 i healthcheck workera w T037 wymagają `compose.yaml` z US1
  (T016–T019).
- **US3 (Phase 5)**: zależy od Phase 2. Testy integracyjne T045–T047 i T052 wymagają
  środowiska z US1. Pozostałe zadania są niezależne od US1 i US2.
- **Polish (Phase 6)**: po wszystkich historyjkach.

### Konflikty plików (zadania na tym samym pliku wykonujemy sekwencyjnie)

- `bootstrap/app.php`: T011 → T048 → T049.
- `app/Providers/AppServiceProvider.php`: T037 → T051.
- `routes/web.php`: T014 → T038.
- `compose.yaml`: T016 → T017 → T018 → T019 → T037.

### Within Each User Story

- Najpierw testy, które mają nie przechodzić, potem enumy i VO, potem serwisy i sprawdzenia,
  potem kontroler, komenda i rejestracja, na końcu weryfikacja.

### Parallel Opportunities

- Phase 1: T001, T002, T003, T004, T005.
- Phase 2: T006 i T007 (testy).
- US1: T013 i T015.
- US2: testy T021–T024, enumy T025–T026, sprawdzenia T030–T032.
- US3: testy T041–T047, trait T050.
- Po Phase 2 US2 i US3 mogą iść równolegle obok US1, z zachowaniem kolejności na plikach
  wspólnych wymienionych wyżej.

---

## Parallel Example: User Story 2

```bash
# Testy US2 równolegle:
Task: "HealthEndpointTest w tests/Feature/Health/HealthEndpointTest.php"
Task: "CheckHealthCommandTest w tests/Feature/Health/CheckHealthCommandTest.php"
Task: "WorkerHeartbeatTest w tests/Feature/Health/WorkerHeartbeatTest.php"
Task: "MailTransportCheckTest w tests/Feature/Health/MailTransportCheckTest.php"

# Enumy i proste sprawdzenia równolegle:
Task: "Enum Component w app/Services/Health/Component.php"
Task: "Enum ComponentState w app/Services/Health/ComponentState.php"
Task: "DatabaseCheck w app/Services/Health/Checks/DatabaseCheck.php"
Task: "QueueCheck w app/Services/Health/Checks/QueueCheck.php"
Task: "CacheCheck w app/Services/Health/Checks/CacheCheck.php"
```

## Parallel Example: User Story 3

```bash
Task: "MalformedPayloadTest w tests/Feature/Http/MalformedPayloadTest.php"
Task: "ErrorFormatTest w tests/Feature/Http/ErrorFormatTest.php"
Task: "RetryPolicyTest w tests/Unit/Queue/RetryPolicyTest.php"
Task: "AtomicLockTest w tests/Feature/Integration/AtomicLockTest.php"
Task: "Trait UsesDefaultRetryPolicy w app/Jobs/Concerns/UsesDefaultRetryPolicy.php"
```

---

## Implementation Strategy

### MVP First (User Story 1)

1. Phase 1 (Setup), potem Phase 2 (Foundational).
2. Phase 3 (US1): `docker compose up` daje działające środowisko.
3. **STOP & VALIDATE**: T020. Pierwsze dwa kryteria akceptacji (start bez danych
   i działające `docker up`) są spełnione.

### Incremental Delivery

1. Setup + Foundational: logi i korelacja.
2. US1: środowisko (MVP).
3. US2: `/health` i `app:health`. Kryterium „Mailtrap odpowiada poprawnie” jest spełnione.
4. US3: mechanizmy pod webhooki (poison pills, backoff, dead-letter, blokady, opóźnienia).
5. Polish: bramki jakości i pełny quickstart.

---

## Notes

- [P] = inny plik i brak zależności od niedokończonych zadań.
- Etykieta [USx] pozwala śledzić zadanie wstecz do historyjki w `spec.md`.
- Testy integracyjne nigdy nie mają być pomijane po cichu (`skip`). Brak infrastruktury
  ma powodować błąd (Zasada IV).
- Commit po każdym zadaniu lub logicznej grupie zadań; repozytorium nie jest jeszcze
  zainicjalizowane jako git, więc `git init` należy do decyzji użytkownika.
