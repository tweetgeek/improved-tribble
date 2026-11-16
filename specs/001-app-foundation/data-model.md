# Data Model: Fundament aplikacji payment-gateway

**Feature**: `001-app-foundation` | **Date**: 2026-11-16

Ta funkcjonalność **nie dodaje nowych tabel**. Korzysta z istniejących migracji i wprowadza
kilka obiektów w pamięci oraz kluczy w cache.

## 1. Wpis magazynu porażek (dead-letter) — tabela `failed_jobs` (istniejąca)

Źródło: `database/migrations/0001_01_01_000002_create_jobs_table.php`, driver `database-uuids`.

| Pole | Typ | Opis | Mapowanie na specyfikację |
|------|-----|------|---------------------------|
| `id` | bigint, PK | identyfikator wewnętrzny | — |
| `uuid` | string, unique | identyfikator do `queue:retry` / `queue:forget` | identyfikator wpisu |
| `connection` | text | połączenie kolejki (`redis`) | — |
| `queue` | text | nazwa kolejki | nazwa kolejki |
| `payload` | longText (JSON) | pełny payload zadania | pełna treść zadania |
| `payload.attempts` | int | liczba wykonanych prób | liczba prób |
| `payload.illuminate:log:context` | object | zdehydratowany `Context`, w tym `correlation_id` | identyfikator korelacji |
| `exception` | longText | klasa, komunikat i stack trace ostatniego błędu | opis ostatniego błędu |
| `failed_at` | timestamp | moment przejścia na dead-letter | czas porażki |

**Reguły**:
- Wpis powstaje automatycznie, gdy zadanie wyczerpie próby lub wywoła `fail()`.
- Wpis jest trwały (PostgreSQL, wolumen `pgsql-data`) i przetrwa restart Redisa.
- Ponowienie (`queue:retry`) usuwa wpis i wrzuca zadanie z powrotem na kolejkę z tym samym
  payloadem (w tym `correlation_id`).
- Po pierwszym starcie tabela jest pusta.

**Cykl życia**:

```text
[w kolejce] → próba → (sukces) → [usunięte z kolejki]
                    → (wyjątek, próby < tries) → [opóźnione wg backoff] → próba …
                    → (wyjątek, próby = tries) lub fail() → [failed_jobs]
[failed_jobs] → queue:retry → [w kolejce]
[failed_jobs] → queue:forget / queue:flush → [usunięte]
```

## 2. Raport stanu — `HealthReport` (w pamięci)

| Pole | Typ | Opis |
|------|-----|------|
| `status` | enum `ComponentState` | `Up`, gdy wszystkie komponenty mają `Up`; inaczej `Down` |
| `checked_at` | ISO 8601 | czas wygenerowania raportu |
| `correlation_id` | string | identyfikator korelacji żądania lub komendy |
| `components` | lista `ComponentStatus` | po jednym wpisie na komponent, stała kolejność |

### `ComponentStatus` (readonly value object)

| Pole | Typ | Reguły |
|------|-----|--------|
| `name` | enum `Component`: `App`, `Database`, `Queue`, `Cache`, `Worker`, `Mail` | — |
| `state` | enum `ComponentState`: `Up`, `Down` | serializowane w JSON jako pole `status` (`up`/`down`) |
| `reason` | `?string` | wymagane, gdy `Down`; czytelny opis bez sekretów (np. „SMTP authentication failed”) |
| `latency_ms` | `?int` | czas sprawdzenia |
| `checked_at` | ISO 8601 | — |

**Reguły**:
- Każdy komponent `Down` jest logowany na poziomie `error` z `reason` i wyjątkiem.
- Wyjątek zgłoszony przez sprawdzenie jest przechwytywany **wyłącznie** w celu zamiany na
  `Down` + log `error` — nigdy nie jest pomijany.
- `reason` w odpowiedzi HTTP nie zawiera hostów, loginów ani haseł (FR-011).

## 3. Sygnał życia workera — klucz cache

| Klucz | Wartość | TTL | Zapisywany przez |
|-------|---------|-----|------------------|
| `health:worker:heartbeat` | Unix timestamp (int) ostatniej iteracji | 300 s | `Queue::looping`, `Queue::before`, `Queue::after` (najczęściej co 10 s) |
| `health:mail:last_result` | zserializowany `ComponentStatus` | 60 s | `MailTransportCheck` przy wywołaniu z HTTP |

**Reguła**: `Worker = Up` ⇔ klucz istnieje i `now - heartbeat < 90 s`.

## 4. Identyfikator korelacji (wartość w `Context`)

| Atrybut | Reguła |
|---------|--------|
| Klucz | `correlation_id` w `Illuminate\Support\Facades\Context` |
| Źródło | nagłówek `X-Correlation-ID`, jeśli pasuje do `^[A-Za-z0-9._:-]{1,128}$`; inaczej `Str::uuid7()` |
| Propagacja | automatycznie do każdego wpisu logu (pole `extra`) i do payloadu każdego zadania w kolejce |
| Ekspozycja | nagłówek odpowiedzi `X-Correlation-ID`; pole `correlation_id` w treści błędów i raportu stanu |

## 5. Konfiguracja polityki ponowień — `config('queue.retry_policy')`

| Klucz | Wartość domyślna | Niezmiennik (pilnowany testem) |
|-------|------------------|--------------------------------|
| `tries` | 5 | ≥ 4 |
| `backoff` | `[10, 30, 90, 270]` | ściśle rosnące |
| `timeout` | 60 | `< config('queue.connections.redis.retry_after')` (90) |
| `max_exceptions` | 3 | ≤ `tries` |

## 6. Konfiguracja maskowania — `config('logging.redact')`

| Klucz | Wartość domyślna |
|-------|------------------|
| `keys` | `password`, `secret`, `token`, `apikey`, `authorization`, `signature`, `cardnumber`, `pan`, `cvv`, `cvc`, `cookie`, `iban` |
| `replacement` | `[REDACTED]` |
| `max_string_length` | 10 000 |

Dopasowanie: znormalizowana nazwa klucza (małe litery, bez `-` i `_`) **zawiera** wzorzec.
