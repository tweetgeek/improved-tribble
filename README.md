# payment-gateway

Mikro-system dla platformy e-commerce, który odbiera webhooki o zdarzeniach płatności od
dostawcy (np. Stripe/PayU), weryfikuje je, eliminuje duplikaty i asynchronicznie powiadamia
dział sprzedaży na kanałach Mailtrap (e-mail) i SalesTeam (mock).

Stos: Laravel 13, PHP 8.5, PostgreSQL 18, Redis 8 (kolejka i cache), Docker Compose.

## Stan projektu

Projekt powstaje etapami według specyfikacji w `specs/`. README opisuje to, co działa dziś,
a elementy jeszcze niezaimplementowane są wyraźnie oznaczone jako planowane.

| Obszar | Stan | Źródło |
|--------|------|--------|
| Uruchomienie w Dockerze (`app`, `worker`, `pgsql`, `redis`, `init`) | działa | `compose.yaml` |
| Identyfikator korelacji `X-Correlation-ID` | działa | `app/Http/Middleware/AssignCorrelationId.php` |
| Logi JSON z maskowaniem sekretów | działa | `config/logging.php`, `app/Logging/RedactSensitiveData.php` |
| Dead-letter (`failed_jobs`) i ręczne ponawianie | działa (mechanizm Laravela) | `config/queue.php` |
| `GET /health`, `php artisan app:health` | planowane | `specs/001-app-foundation/contracts/` |
| Jednolity format błędów 4xx/5xx | planowane | `specs/001-app-foundation/contracts/http-errors.md` |
| Endpoint webhooków, deduplikacja, kanały powiadomień | planowane | `specs/002-payment-webhook-notifications/spec.md` |

## Wymagania

- Docker z Compose v2+
- wolny port `8000` na hoście (zmienisz go zmienną `APP_PORT`)
- konto Mailtrap ze skrzynką Sandbox (potrzebne dopiero do kanału e-mail)

## Konfiguracja `.env`

```bash
cp .env.example .env
```

Jeśli pominiesz ten krok, kontener `init` sam skopiuje `.env.example` do `.env`.

Co ustawić przed pierwszym startem:

| Zmienna | Co wpisać |
|---------|-----------|
| `MAIL_USERNAME`, `MAIL_PASSWORD` | dane z Mailtrap: Email Testing → Inboxes → SMTP Settings |
| `MAIL_FROM_ADDRESS` | adres nadawcy powiadomień (domyślnie `payments@example.test`) |
| `DB_PASSWORD` | hasło do PostgreSQL (domyślnie `secret`) |
| `APP_PORT` | port HTTP na hoście, jeśli `8000` jest zajęty (brak w `.env.example`, dopisz ręcznie) |
| `LOG_LEVEL` | próg logowania (domyślnie `debug`) |

Czego nie ustawiasz ręcznie:

- `APP_KEY` generuje kontener `init` przy pierwszym starcie.
- `DB_HOST`, `REDIS_HOST`, `QUEUE_CONNECTION` i `CACHE_STORE` są nadpisywane w `compose.yaml`
  (`pgsql`, `redis`, `redis`, `redis`), więc wartości z `.env` nie mają znaczenia w kontenerach.

Hasło i nazwa bazy są zapisywane w wolumenie `pgsql-data` przy pierwszym starcie. Jeśli zmienisz
`DB_PASSWORD`, `DB_USERNAME` lub `DB_DATABASE` później, usuń wolumeny (`docker compose down -v`),
co kasuje dane.

Na Linuksie obraz tworzy użytkownika `1000:1000`. Jeśli twój użytkownik ma inne identyfikatory
(sprawdzisz je przez `id -u` i `id -g`), dopisz je do `.env` przed zbudowaniem obrazu, żeby pliki
tworzone w kontenerze należały do ciebie:

```dotenv
UID=1001
GID=1001
```

## Uruchomienie

```bash
docker compose up -d
docker compose ps
```

Kontener `init` instaluje zależności Composera, generuje `APP_KEY` i wykonuje migracje, po czym
kończy pracę z kodem `0`. Dopiero wtedy startują `app` (serwer HTTP na porcie 8000) i `worker`
(`queue:work` na kolejce `default`).

Oczekiwany stan: `init` zakończony (`exited (0)`), `app`, `pgsql` i `redis` w stanie `healthy`,
`worker` w stanie `running`.

Przydatne polecenia:

```bash
docker compose logs init            # instalacja, klucz, migracje
docker compose restart worker       # po zmianie kodu jobów (worker trzyma kod w pamięci)
docker compose down                 # zatrzymanie z zachowaniem danych
docker compose down -v              # zatrzymanie i usunięcie bazy oraz Redisa
```

## Testowanie za pomocą curl

### Co działa dziś

```bash
# Liveness procesu HTTP
curl -i http://localhost:8000/up

# Informacja o usłudze
curl -s http://localhost:8000/
# {"service":"payment-gateway","status":"running"}

# Własny identyfikator korelacji wraca w nagłówku odpowiedzi
curl -i http://localhost:8000/ -H 'X-Correlation-ID: readme-test-1'
```

Identyfikator korelacji jest przyjmowany, gdy pasuje do `^[A-Za-z0-9._:-]{1,128}$`. W przeciwnym
razie (oraz gdy go brak) aplikacja generuje własny UUIDv7. Ta sama wartość trafia do nagłówka
odpowiedzi i do każdego wpisu w logu z danego żądania.

### Webhook płatności (planowane)

Endpoint webhooków nie jest jeszcze zaimplementowany. Adres, nazwa nagłówka z podpisem i nazwy
zmiennych `.env` z sekretem dostawcy zostaną ustalone w planie do specyfikacji
`002-payment-webhook-notifications`, dlatego README nie podaje jeszcze gotowych komend `curl`.
Docelowy proces według specyfikacji:

1. Dostawca wysyła `POST` na adres przypisany do dostawcy, z podpisem HMAC-SHA256 wyliczonym
   z surowej treści żądania i znacznika czasu (okno ważności 5 minut).
2. Aplikacja kolejno uwierzytelnia nadawcę, sprawdza składnię JSON, waliduje pola i sprawdza
   duplikat po parze dostawca + `event_id`.
3. Zdarzenie jest zapisywane w bazie, publikowane jest wewnętrzne zdarzenie o jego przyjęciu,
   a dostawca dostaje odpowiedź bez czekania na wysyłkę powiadomień.
4. Listener zleca osobny job na każdy włączony kanał (Mailtrap, SalesTeam).
5. Nieudany job jest ponawiany po 1, 5 i 15 minutach (łącznie 4 próby), a potem trafia do
   dead-letter. Błąd trwały kanału trafia tam od razu.

Odpowiedzi, które będzie można sprawdzić curlem:

| Sytuacja | Kod |
|----------|-----|
| poprawny webhook | `200`, komunikat o przyjęciu, `event_id`, `correlation_id` |
| ten sam `event_id` drugi raz | `200`, komunikat, że zdarzenie jest już przetwarzane |
| brak lub błędny podpis, znacznik czasu poza oknem 5 minut | `401` |
| treść pusta lub niepoprawny JSON | `400` |
| brak pola wymaganego lub błędna wartość | `422` z listą pól |
| nieobsługiwany dostawca | `404` |
| nieudany zapis lub publikacja zdarzenia | `5xx` (ponowne doręczenie nie jest duplikatem) |

## Logi

Aplikacja pisze logi w formacie JSON (jeden wpis na linię) równolegle w dwa miejsca:

| Gdzie | Jak czytać |
|-------|------------|
| stderr kontenerów | `docker compose logs -f app worker` |
| pliki dzienne, przechowywane 14 dni | `storage/logs/laravel-RRRR-MM-DD.json` (katalog jest widoczny na hoście) |

Najważniejsze pola wpisu:

| Pole | Znaczenie |
|------|-----------|
| `level_name` | poziom: `DEBUG`, `INFO`, `WARNING`, `ERROR` |
| `message` | opis zdarzenia |
| `context` | dane przekazane do wpisu (treść żądania, wyjątek, kanał, numer próby) |
| `extra.correlation_id` | identyfikator korelacji żądania |
| `datetime` | czas wpisu |

Typowe zapytania:

```bash
# Wszystko, co dotyczy jednego żądania
docker compose logs app worker | grep 'readme-test-1'

# Same błędy
docker compose logs app worker | grep '"level_name":"ERROR"'

# To samo z pliku, z formatowaniem
grep '"level_name":"ERROR"' storage/logs/laravel-$(date +%F).json | jq
```

Wartości pól, których nazwa zawiera `password`, `secret`, `token`, `apikey`, `authorization`,
`signature`, `cardnumber`, `pan`, `cvv`, `cvc`, `cookie` lub `iban`, są zastępowane tekstem
`[REDACTED]`. Teksty dłuższe niż 10 000 znaków są obcinane. Listę kluczy zmienisz w
`config/logging.php` w sekcji `redact`.

Planowane (jeszcze niezaimplementowane): wpis `ERROR` dla każdego odrzuconego żądania z jego
treścią oraz dla każdej nieudanej próby joba z nazwą kanału, numerem próby i `event_id`.

## Dead-letter i ponawianie jobów

Job, który wyczerpał próby, trafia do tabeli `failed_jobs` w PostgreSQL razem z pełnym payloadem
i treścią wyjątku. Worker ponawia domyślnie 5 razy z odstępami 10, 30, 90 i 270 sekund
(joby powiadomień będą miały własną politykę: 4 próby, odstępy 1, 5 i 15 minut).

```bash
# Lista jobów w dead-letter (UUID, kolejka, klasa, czas porażki)
docker compose exec app php artisan queue:failed

# Pełny wyjątek dla konkretnego wpisu
docker compose exec pgsql psql -U payment_gateway -d payment_gateway \
  -c "select uuid, queue, failed_at, left(exception, 500) from failed_jobs order by failed_at desc"

# Ponowienie jednego joba
docker compose exec app php artisan queue:retry <uuid>

# Ponowienie kilku naraz lub wszystkich
docker compose exec app php artisan queue:retry <uuid-1> <uuid-2>
docker compose exec app php artisan queue:retry all

# Usunięcie wpisu bez ponawiania / wyczyszczenie dead-letter
docker compose exec app php artisan queue:forget <uuid>
docker compose exec app php artisan queue:flush
```

`queue:retry` odkłada job z powrotem na kolejkę z wyzerowanym licznikiem prób, a działający
`worker` podejmuje go sam. Zanim ponowisz, usuń przyczynę porażki (np. popraw dane Mailtrap
w `.env`) i zrestartuj worker (`docker compose restart worker`), żeby wczytał nową konfigurację.
Wynik sprawdzisz w `docker compose logs -f worker`, a jeśli job znów się nie powiedzie, wróci do
`queue:failed` po wyczerpaniu prób.

## Testy

```bash
docker compose exec app php artisan test --compact
```

Testy używają SQLite w pamięci, kolejki `sync` i mailera `array`, więc nie dotykają bazy ani
Redisa z Compose.

## Dokumentacja projektowa

- `specs/001-app-foundation/` zawiera specyfikację, plan i kontrakty fundamentu (Docker, stan
  systemu, logowanie, obsługa błędów).
- `specs/002-payment-webhook-notifications/spec.md` opisuje webhooki, deduplikację i kanały
  powiadomień.
