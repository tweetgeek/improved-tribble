# Research: Fundament aplikacji payment-gateway

**Feature**: `001-app-foundation` | **Date**: 2026-11-16

Każda decyzja ma format: Decyzja / Uzasadnienie / Rozważone alternatywy.

---

## R1. Środowisko kontenerowe: własny `Dockerfile` + `compose.yaml` zamiast Sail

**Decyzja**: własny `Dockerfile` (obraz `php:8.5-cli` + rozszerzenia `pdo_pgsql`, `redis`, `pcntl`,
`zip`, `intl` + binarka Composera) i `compose.yaml` w katalogu głównym repozytorium. Usługi:
`init` (jednorazowa), `app`, `worker`, `pgsql`, `redis`. Kod montowany jako bind mount.

**Uzasadnienie**: Sail buduje obraz z `vendor/laravel/sail/runtimes/8.5`, więc w świeżym klonie
(bez `vendor/`) `docker compose up` nie zadziała — a SC-001 wymaga startu od świeżego klonu.
Własny obraz zawiera Composera, a usługa `init` instaluje zależności przy pierwszym starcie.
Pliki leżą w katalogu głównym, więc nie tworzymy nowego katalogu bazowego (wymóg `CLAUDE.md`).

**Alternatywy**:
- *Sail (`sail:install`)* — odrzucone: wymaga `vendor/` przed pierwszym buildem.
- *`composer install` w trakcie builda obrazu* — odrzucone: bind mount przykrywa `vendor/`
  z obrazu; trzeba by dodatkowego wolumenu na `vendor/` i rozjeżdżałby się z hostem (IDE).
- *Katalog `docker/` z entrypointem* — odrzucone: nowy katalog bazowy wymaga zgody; skrypt
  inicjalizacyjny jest na tyle krótki, że mieści się w `command` usługi `init`.

## R2. Kolejność startu i automatyczne przygotowanie schematu

**Decyzja**: usługa `init` (`restart: "no"`) wykonuje kolejno: skopiowanie `.env.example` do
`.env`, jeśli brak; `composer install`, jeśli brak `vendor/autoload.php`; `key:generate`, jeśli
`APP_KEY` jest pusty; `php artisan migrate --force --isolated`. `init` zależy od `pgsql`
i `redis` z `condition: service_healthy`. `app` i `worker` zależą od `init` z
`condition: service_completed_successfully`.

**Uzasadnienie**: spełnia FR-002, FR-003 (migracje są idempotentne, `--isolated` blokuje
równoległe migracje), FR-005 (healthchecki `pg_isready` i `redis-cli ping` zamiast „sleep”).
Błąd w `init` zatrzymuje start `app`/`worker` i jest widoczny w `docker compose ps` / logach —
nic nie startuje „w połowie”.

**Alternatywy**:
- *Migracje w entrypoincie `app`* — odrzucone: wyścig między `app` i `worker` przy starcie.
- *Ręczne `php artisan migrate`* — odrzucone: łamie FR-003 i kryterium „docker up”.

## R3. Baza danych: PostgreSQL 18

**Decyzja**: `postgres:18-alpine`, wolumen nazwany `pgsql-data`.

**Uzasadnienie**: przyszłe funkcjonalności potrzebują twardych gwarancji unikalności
(`UNIQUE` na `event_id` jako druga linia obrony przed race condition), transakcyjnego DDL
i `SELECT … FOR UPDATE SKIP LOCKED`. Rozszerzenie `pdo_pgsql` jest dostępne, nie dodajemy
zależności Composera.

**Alternatywy**:
- *MySQL 8* — równie dobre dla tego zakresu; PostgreSQL wybrany dla transakcyjnego DDL.
- *SQLite* — odrzucone: brak współbieżnych zapisów z kilku kontenerów.

## R4. Kolejka i cache: Redis 8 z trwałością AOF

**Decyzja**: `redis:8-alpine` z `--appendonly yes`, wolumen `redis-data`.
`QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, klient `phpredis`.
Połączenie kolejki: `retry_after=90`, `block_for=5`.

**Uzasadnienie**: wymóg konstytucji. AOF sprawia, że kolejka przetrwa restart środowiska.
`block_for=5` zmniejsza liczbę zapytań przy pustej kolejce, a jednocześnie worker regularnie
wraca do pętli (sygnał życia, R7).

**Alternatywy**: kolejka `database` — odrzucona przez konstytucję.

## R5. Magazyn porażek (dead-letter): tabela `failed_jobs` w PostgreSQL

**Decyzja**: wbudowany mechanizm Laravela, driver `database-uuids`, tabela `failed_jobs`
z istniejącej migracji `0001_01_01_000002_create_jobs_table.php`. Ponowienie:
`php artisan queue:retry <uuid>`; przegląd: `php artisan queue:failed`.

**Uzasadnienie**: trwały (PostgreSQL, a nie Redis — przetrwa utratę Redisa), przeglądalny,
z gotowym ponowieniem (FR-019, Zasada III). Payload zawiera zdehydratowany `Context`, więc
identyfikator korelacji trafia do wpisu automatycznie (R6). Liczba prób jest w payloadzie
(`attempts`). Żadnej nowej tabeli ani zależności.

**Alternatywy**:
- *Osobna kolejka Redis „dead-letter”* — odrzucone: mniej trwała, brak gotowego przeglądu.
- *Własna tabela `dead_letters`* — odrzucone na tym etapie (YAGNI); wrócimy, jeśli
  funkcjonalność powiadomień będzie wymagała pól, których `failed_jobs` nie ma.

## R6. Identyfikator korelacji: middleware + Laravel `Context`

**Decyzja**: globalny middleware `AssignCorrelationId`. Nagłówek `X-Correlation-ID` przyjmowany,
jeśli pasuje do `^[A-Za-z0-9._:-]{1,128}$`; w przeciwnym razie generowany `Str::uuid7()`.
Wartość zapisywana przez `Context::add('correlation_id', …)` i zwracana w nagłówku odpowiedzi.
Komendy Artisana i worker bez kontekstu HTTP generują własny identyfikator.

**Uzasadnienie**: `Context` jest automatycznie dołączany do każdego wpisu logu i dehydratowany
do payloadu każdego zadania w kolejce, a przy wykonaniu hydratowany z powrotem (dokumentacja
Laravel 13, „Context”). To spełnia FR-015 łącznie z propagacją do przyszłych zadań
— bez własnego kodu do przekazywania identyfikatora. Walidacja formatu chroni przed
wstrzyknięciem do logów.

**Alternatywy**:
- *`Log::shareContext()`* — odrzucone: nie propaguje się do zadań w kolejce.
- *Ślepe przyjmowanie dowolnego nagłówka* — odrzucone: ryzyko log injection.

## R7. Status workera: sygnał życia z pętli workera

**Decyzja**: w `AppServiceProvider` rejestrujemy `Queue::looping()`, `Queue::before()`
i `Queue::after()`; każdy z nich wywołuje `WorkerHeartbeat::record()`, który zapisuje znacznik
czasu w cache (klucz `health:worker:heartbeat`, TTL 300 s) nie częściej niż co 10 s.
Worker jest „działa”, gdy ostatni sygnał jest młodszy niż 90 s (`= retry_after`).

**Uzasadnienie**: FR-010 wymaga stanu opartego na rzeczywistym przetwarzaniu, a nie na
istnieniu procesu. `looping` wykonuje się w każdej iteracji workera, także przy pustej kolejce,
więc nie potrzebujemy żadnego zadania diagnostycznego (zgodne z decyzją A i zakresem „bez
jobów”). Próg 90 s nie daje fałszywego alarmu podczas najdłuższego dozwolonego zadania (60 s).

**Alternatywy**:
- *Sprawdzanie procesu (`pgrep`)* — odrzucone: proces może wisieć bez przetwarzania.
- *Zadanie „ping” przez kolejkę* — odrzucone: wymaga joba, poza zakresem.
- *Horizon* — odrzucone: nowa zależność.

## R8. Weryfikacja Mailtrap bez wysyłki

**Decyzja**: `MailTransportCheck` pobiera transport Symfony mailera `smtp`
(`Mail::mailer('smtp')->getSymfonyTransport()`), wywołuje `start()` (połączenie, EHLO, STARTTLS,
AUTH), a potem `stop()`. Timeout SMTP: 5 s (`MAIL_TIMEOUT`). Wynik dla endpointu HTTP
jest cache'owany przez 60 s; komenda CLI zawsze sprawdza na świeżo.

**Uzasadnienie**: realizuje decyzję A ze specyfikacji (FR-013). `start()` w `EsmtpTransport`
uwierzytelnia, więc błędne dane dostępowe kończą się wyjątkiem z czytelnym komunikatem.
Cache chroni przed limitami połączeń Mailtrap przy częstym odpytywaniu `/health`.

**Alternatywy**: surowe gniazdo TCP — odrzucone: nie sprawdza uwierzytelnienia.

## R9. Logi strukturalne z maskowaniem i obcinaniem

**Decyzja**: dwa kanały Monologa z `JsonFormatter`: `stderr_json` (dla `docker compose logs`)
i `daily_json` (`storage/logs/laravel.json`, 14 dni). `LOG_STACK=stderr_json,daily_json`.
Oba kanały mają procesor `App\Logging\RedactSensitiveData`:
- maskuje rekurencyjnie wartości kluczy, których znormalizowana nazwa (małe litery, bez `-`/`_`)
  zawiera jeden ze wzorców z `config('logging.redact.keys')`, np. `password`, `secret`, `token`,
  `apikey`, `authorization`, `signature`, `cardnumber`, `pan`, `cvv`, `cvc`, `cookie`;
  wartość zastępowana przez `[REDACTED]`;
- obcina stringi dłuższe niż `config('logging.redact.max_string_length')` = 10 000 znaków
  z dopiskiem `…[truncated N chars]`.

**Uzasadnienie**: FR-016, FR-017, SC-008. Jedna centralna lista kluczy w konfiguracji.
Procesor działa na `context` i `extra`, więc obejmuje też dane z `Context` i z raportów wyjątków.

**Alternatywy**:
- *Maskowanie w miejscu logowania* — odrzucone: łatwo pominąć; brak jednego źródła prawdy.
- *Dokładne dopasowanie nazw kluczy* — odrzucone: nie złapie `x-api-key`, `stripe-signature`.

## R10. Uszkodzone i zbyt duże żądania (poison pills)

**Decyzja**: globalny middleware `RejectMalformedPayload` (po `AssignCorrelationId`):
- ciało > 1 MiB → `413 payload_too_large`;
- `Content-Type` JSON, niepuste ciało i `json_validate()` = false → `400 malformed_payload`;
- każde odrzucenie jest logowane na poziomie `error` z metodą, ścieżką, nagłówkami
  i surowym ciałem (maskowanie i obcinanie z R9).

Dodatkowo w `bootstrap/app.php`: wszystkie wyjątki renderowane jako JSON w jednolitym formacie
(kontrakt `contracts/http-errors.md`), `stopIgnoring(ValidationException::class)`, aby błędy
walidacji (niekompletne żądania) były logowane, oraz `context()` dodające treść żądania
do raportu każdego wyjątku.

**Uzasadnienie**: domyślnie Laravel przy niepoprawnym JSON zwraca pustą tablicę wejścia — błąd
byłby niewidoczny (łamie Zasadę IV). Middleware globalny działa przed routingiem, więc chroni
wszystkie przyszłe endpointy, w tym webhooki (FR-014, SC-005).

**Alternatywy**: walidacja tylko w Form Requestach — odrzucone: nie łapie składni JSON.

## R11. Logowanie błędów zadań w kolejce (siatka bezpieczeństwa)

**Decyzja**: w `AppServiceProvider` nasłuch `JobExceptionOccurred` (każda nieudana próba)
i `JobFailed` (przejście na dead-letter); oba logują na poziomie `error`: klasę zadania,
kolejkę, numer próby, `uuid`, surowy payload i wyjątek.

**Uzasadnienie**: Zasada IV wymaga logowania każdej porażki z payloadem. Siatka działa
niezależnie od tego, czy przyszłe zadanie poprawnie zaimplementuje `failed()`.

## R12. Domyślna polityka ponowień

**Decyzja**: sekcja `config('queue.retry_policy')`: `tries=5`, `backoff=[10, 30, 90, 270]`,
`timeout=60`, `max_exceptions=3`. Trait `App\Jobs\Concerns\UsesDefaultRetryPolicy` udostępnia
`$tries`, `$timeout`, `backoff()` z tej konfiguracji. Worker uruchamiany z tymi samymi
wartościami jako siatką bezpieczeństwa:
`queue:work redis --tries=5 --backoff=10,30,90,270 --timeout=60 --sleep=3 --max-time=3600`.

**Uzasadnienie**: FR-020 i Zasada II (≥ 4 próby, rosnący odstęp, `timeout 60 < retry_after 90`).
Test jednostkowy pilnuje niezmienników: odstępy ściśle rosnące, `timeout < retry_after`.

**Alternatywy**: klasa bazowa `BaseJob` — odrzucone: trait nie wymusza dziedziczenia.

## R13. Blokada współdzielona i opóźnione zadania

**Decyzja**: bez własnych klas. Blokada: `Cache::lock($key, $seconds)` na magazynie Redis
(`SET NX PX`, atomowa między procesami). Opóźnienie: natywne `delay()` / `release($seconds)`
kolejki Redis. Oba mechanizmy są potwierdzane testami integracyjnymi na prawdziwym Redisie
(grupa Pest `integration`): 100 równoległych procesów przez `Concurrency::run()` (driver
`process`) → dokładnie 1 sukces (SC-006).

**Uzasadnienie**: FR-021, FR-022. Fundament ma potwierdzić, że infrastruktura daje te
gwarancje; opakowanie ich w serwisy bez konsumenta byłoby przedwczesne.

## R14. Endpoint stanu i komenda CLI

**Decyzja**: `GET /health` (JSON, 200 gdy wszystko działa, 503 w przeciwnym razie, bez
uwierzytelnienia, bez szczegółów konfiguracji) oraz `php artisan app:health` (tabela lub
`--json`, kod wyjścia 0/1, `--only=<component>`). Wbudowany `/up` zostaje jako liveness
procesu HTTP. Kontrakty: `contracts/health-http.md`, `contracts/health-cli.md`.

**Uzasadnienie**: FR-008–FR-011. `/up` sprawdza tylko, czy aplikacja wstała; `/health`
weryfikuje wszystkie zależności. Komenda daje operatorowi szczegóły bez wystawiania ich
przez HTTP.

## R15. Serwer HTTP

**Decyzja**: `php artisan serve --host=0.0.0.0 --port=8000` z `PHP_CLI_SERVER_WORKERS=4`.

**Uzasadnienie**: środowisko jest deweloperskie i demonstracyjne (założenie specyfikacji).
Brak nowych zależności.

**Alternatywy**: Octane/FrankenPHP, nginx + php-fpm — odrzucone: nowe zależności lub więcej
usług bez potrzeby na tym etapie.

## R16. Strategia testów

**Decyzja**:
- Testy Unit/Feature działają na hoście i w kontenerze: `sqlite :memory:`, cache `array`,
  kolejka `sync`, mail `array` (`phpunit.xml`). Uruchamianie:
  `php artisan test --compact --exclude-group=integration`.
- Testy z grupy `integration` wymagają prawdziwego Redisa i PostgreSQL; uruchamiane w kontenerze:
  `docker compose exec app php artisan test --compact --group=integration`. Brak Redisa
  kończy się błędem testu, a nie jego pominięciem (Zasada IV).

**Uzasadnienie**: host nie ma rozszerzenia `redis`; testy szybkie nie mogą od niego zależeć,
a gwarancje atomowości da się udowodnić tylko na prawdziwym Redisie.
