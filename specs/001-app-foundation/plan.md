# Implementation Plan: Fundament aplikacji payment-gateway

**Branch**: `001-app-foundation` | **Date**: 2026-11-16 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/001-app-foundation/spec.md`

## Summary

Fundament przygotowuje uruchomienie całego środowiska jedną komendą `docker compose up`
(aplikacja, worker, PostgreSQL, Redis i jednorazowa usługa `init` z migracjami), sprawdzanie
stanu wszystkich zależności (`GET /health` i `php artisan app:health`, Mailtrap sprawdzany
połączeniem z uwierzytelnieniem, bez wysyłki) oraz mechanizmy wspólne dla przyszłych
webhooków. Te mechanizmy to:

- identyfikator korelacji w Laravel `Context`,
- logi JSON z maskowaniem sekretów,
- odrzucanie uszkodzonych żądań (400/413) z logiem,
- dead-letter w tabeli `failed_jobs`,
- domyślna polityka ponowień z rosnącym backoffem,
- siatka logowania błędów zadań,
- atomowe blokady i opóźnione zadania potwierdzone testami integracyjnymi.

Szczegóły decyzji są w [research.md](research.md).

## Technical Context

**Language/Version**: PHP 8.5 (host 8.5.11; obraz `php:8.5-cli`)

**Primary Dependencies**: Laravel 13.42, Pest 5.3, Laravel Pint 1.32. Bez nowych zależności
Composera. Obrazy Docker: `php:8.5-cli`, `composer:2`, `mlocati/php-extension-installer`,
`postgres:18-alpine`, `redis:8-alpine`.

**Storage**: PostgreSQL 18 (dane aplikacji i dead-letter `failed_jobs`); Redis 8 z AOF
(kolejka, cache, blokady, sygnał życia workera).

**Testing**: Pest. Testy Unit/Feature używają sqlite `:memory:`, cache `array`, kolejki `sync`
i maila `array`. Grupa `integration` korzysta z prawdziwego Redisa i PostgreSQL w kontenerze.

**Target Platform**: kontenery Linux uruchamiane przez Docker Compose na maszynie dewelopera.

**Project Type**: web service (API bez frontendu).

**Performance Goals**: `/health` odpowiada w ≤ 10 s także przy niedostępnej zależności
(limit ~5 s na sprawdzenie). Od `docker compose up` do pełnego stanu `up` mija ≤ 2 min
(SC-002).

**Constraints**:
- timeout zadania 60 s < `retry_after` 90 s;
- maks. ciało żądania 1 MiB;
- maks. 10 000 znaków na pole w logu;
- wznowienie workera ≤ 30 s (SC-003);
- brak nowego katalogu bazowego w repozytorium.

**Scale/Scope**: środowisko deweloperskie i demo: 1 instancja `app`, 1 worker, 1 kolejka
`default`.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Zasada / sekcja | Wymóg | Jak plan go spełnia | Wynik |
|-----------------|-------|---------------------|-------|
| I. Queue-First | Powiadomienia przez kolejkę; listenery z I/O jako `ShouldQueue`; Redis jako kolejka i cache; `sync` tylko w testach; workery jako osobne procesy | Ta funkcjonalność nie wysyła powiadomień. Sprawdzenie Mailtrap (bez wysyłki) nie jest powiadomieniem. `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`; `sync` tylko w `phpunit.xml`. Worker działa jako osobna usługa z `restart: unless-stopped` | ✅ |
| II. Odporność i backoff | `tries`/`timeout`/rosnący `backoff`; `timeout < retry_after`; idempotencja | `config('queue.retry_policy')` i trait `UsesDefaultRetryPolicy`. Worker dostaje te same flagi jako siatkę bezpieczeństwa. Niezmienniki pilnuje test (R12). Idempotencja nie dotyczy: brak zadań biznesowych | ✅ |
| III. Dead-letter | Trwały, przeglądalny, z ponowieniem; `failed()` w każdym jobie | `failed_jobs` w PostgreSQL (R5); `queue:failed` / `queue:retry`. `failed()` nie dotyczy (brak jobów); globalny listener `JobFailed` loguje porażki (R11) | ✅ |
| IV. Żadnych ukrytych błędów | Log `error` z payloadem i korelacją; zakaz połykania wyjątków; 4xx dla odrzuconych; logi strukturalne; maskowanie sekretów | Logi JSON (R9), `Context` (R6), `RejectMalformedPayload` (R10), `stopIgnoring(ValidationException)`, listenery `JobExceptionOccurred`/`JobFailed` (R11). Sprawdzenia stanu przechwytują wyjątek tylko po to, by zamienić go na `down` i zalogować `error` (data-model §2) | ✅ |
| V. Izolowane kanały | Wspólny kontrakt kanałów; Mailtrap jako jedyny transport maili | Kanały nie są w zakresie. `MAIL_MAILER=smtp` wskazuje na Mailtrap Sandbox; w testach `array` | ✅ (N/A dla kontraktu kanałów) |
| Stos technologiczny | Laravel 13 / PHP 8.5, Redis, dead-letter uzasadniony, Mailtrap, Pest, Pint, brak nowych zależności, uwierzytelnianie webhooków | Zgodne; magazyn dead-letter uzasadniony w R5. Webhooków brak w zakresie | ✅ |
| Bramki jakości | Testy Pest, Pint, Constitution Check | Struktura testów poniżej; `vendor/bin/pint --dirty --format agent` przed zakończeniem | ✅ |

**Wynik wstępny**: PASS, brak naruszeń.

**Ponowna ocena po Fazie 1**: PASS. Projekt danych (bez nowych tabel) i kontrakty
(`/health`, `app:health`, format błędów) nie wprowadzają odstępstw. Jedyna świadoma
decyzja, którą warto zauważyć, to przechwytywanie wyjątków w sprawdzeniach stanu. Jest ona
zgodna z Zasadą IV, bo każdy przechwycony wyjątek jest logowany na poziomie `error`
i raportowany jako `down`.

## Project Structure

### Documentation (this feature)

```text
specs/001-app-foundation/
├── plan.md              # ten plik
├── research.md          # Faza 0
├── data-model.md        # Faza 1
├── quickstart.md        # Faza 1
├── contracts/
│   ├── health-http.md   # GET /health
│   ├── health-cli.md    # php artisan app:health
│   └── http-errors.md   # X-Correlation-ID + jednolity format błędów
├── checklists/
│   └── requirements.md
└── tasks.md             # Faza 2 (/speckit-tasks — jeszcze nie utworzony)
```

### Source Code (repository root)

```text
Dockerfile                                   # NOWY  – runtime PHP 8.5 + rozszerzenia + Composer
compose.yaml                                 # NOWY  – init, app, worker, pgsql, redis
.dockerignore                                # NOWY
.env.example                                 # ZMIANA – pgsql, redis, smtp Mailtrap, LOG_STACK
phpunit.xml                                  # ZMIANA – DB_CONNECTION=sqlite, DB_DATABASE=:memory:

bootstrap/app.php                            # ZMIANA – middleware globalne, format błędów JSON,
                                             #          stopIgnoring(ValidationException), context()
config/
├── logging.php                              # ZMIANA – kanały stderr_json, daily_json; sekcja redact
├── queue.php                                # ZMIANA – sekcja retry_policy; redis block_for=5
└── mail.php                                 # ZMIANA – timeout SMTP z MAIL_TIMEOUT

routes/web.php                               # ZMIANA – GET /health; „/” zwraca JSON z nazwą usługi

app/
├── Console/Commands/
│   └── CheckHealthCommand.php               # app:health
├── Http/
│   ├── Controllers/HealthController.php     # invokable, GET /health
│   └── Middleware/
│       ├── AssignCorrelationId.php
│       └── RejectMalformedPayload.php
├── Jobs/Concerns/
│   └── UsesDefaultRetryPolicy.php           # trait dla przyszłych zadań
├── Logging/
│   └── RedactSensitiveData.php              # procesor Monologa
├── Providers/AppServiceProvider.php         # ZMIANA – heartbeat (looping/before/after),
│                                            #          listenery JobExceptionOccurred / JobFailed
└── Services/Health/
    ├── Component.php                        # enum
    ├── ComponentState.php                   # enum
    ├── ComponentStatus.php                  # readonly VO
    ├── HealthReport.php                     # readonly VO + toArray()
    ├── HealthChecker.php                    # uruchamia sprawdzenia, loguje down
    ├── WorkerHeartbeat.php                  # record() / lastSeenAt()
    └── Checks/
        ├── HealthCheck.php                  # interfejs
        ├── DatabaseCheck.php
        ├── QueueCheck.php
        ├── CacheCheck.php
        ├── WorkerCheck.php
        └── MailTransportCheck.php

tests/
├── Unit/
│   ├── Logging/RedactSensitiveDataTest.php
│   └── Queue/RetryPolicyTest.php
└── Feature/
    ├── Health/HealthEndpointTest.php
    ├── Health/CheckHealthCommandTest.php
    ├── Health/WorkerHeartbeatTest.php
    ├── Http/CorrelationIdTest.php
    ├── Http/MalformedPayloadTest.php
    ├── Http/ErrorFormatTest.php
    ├── Queue/JobFailureLoggingTest.php
    └── Integration/                         # ->group('integration'), wymaga docker compose
        ├── AtomicLockTest.php               # 100 procesów → 1 sukces (SC-006)
        ├── DelayedDispatchTest.php          # FR-022
        └── DeadLetterPersistenceTest.php    # FR-019, correlation_id w payloadzie
```

**Structure Decision**: standardowa struktura aplikacji Laravel (jeden projekt). Nowe
podkatalogi powstają tylko wewnątrz istniejącego `app/` (`Logging`, `Services/Health`,
`Jobs/Concerns`, `Http/Middleware`, `Console/Commands`), zgodnie z konwencjami Laravela.
Nie ma nowych katalogów bazowych: pliki Docker leżą w katalogu głównym (R1). Nowe klasy
tworzymy przez `php artisan make:*` (`make:command`, `make:controller --invokable`,
`make:middleware`, `make:class`, `make:enum`, `make:interface`, `make:trait`,
`make:test --pest`). Istniejących migracji i modelu `User` nie zmieniamy.

## Complexity Tracking

Brak naruszeń konstytucji, więc sekcja nie wymaga wpisów.
