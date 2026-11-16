# payment-gateway

Mikro-system dla platformy e-commerce, który odbiera webhooki o zdarzeniach płatności od
dostawcy (np. Stripe/PayU), weryfikuje je, eliminuje duplikaty i asynchronicznie powiadamia
dział sprzedaży na kanałach Mailtrap (e-mail) i SalesTeam (mock).

Stos: Laravel 13, PHP 8.5, PostgreSQL 18, Redis 8 (kolejka i cache), Docker Compose.

## Co robi aplikacja

| Obszar | Opis |
|--------|------|
| Webhook płatności | `POST /api/v1/webhooks/payment` z podpisem HMAC-SHA256, walidacją treści i deduplikacją po `event_id` |
| Powiadomienia | osobny job w kolejce na każdy kanał: Mailtrap (e-mail) i SalesTeam (mock) |
| Odporność na awarie | 4 próby z ponowieniami po 1, 5 i 15 minutach, potem dead-letter (`failed_jobs`) z ręcznym ponawianiem |
| Diagnostyka | `GET /health`, `php artisan app:health`, logi JSON z identyfikatorem korelacji i maskowaniem sekretów |
| Rozszerzalność | nowy dostawca płatności lub kanał to nowa klasa i wpis w `config/webhooks.php` albo `config/notifications.php` |

## Wymagania

- Docker z Compose v2+
- wolny port `8000` na hoście (zmienisz go zmienną `APP_PORT`)
- konto Mailtrap ze skrzynką Sandbox
- `curl`, `openssl` i opcjonalnie `jq` na hoście

## Konfiguracja `.env`

```bash
cp .env.example .env
```

Jeśli pominiesz ten krok, kontener `init` sam skopiuje `.env.example` do `.env`.

### Co ustawić przed pierwszym startem

| Zmienna | Co wpisać |
|---------|-----------|
| `MAIL_USERNAME`, `MAIL_PASSWORD` | dane z Mailtrap: Email Testing → Inboxes → SMTP Settings |
| `WEBHOOK_GENERIC_SECRET` | sekret podpisu HMAC dostawcy `generic` (w `.env.example`: `whsec_dev_secret`); bez niego webhook odpowiada `500` |
| `NOTIFICATIONS_SALES_TEAM_EMAILS` | adresaci powiadomień e-mail, po przecinku (domyślnie `sales@example.test`) |
| `DB_PASSWORD` | hasło do PostgreSQL (domyślnie `secret`) |

### Zmienne opcjonalne

| Zmienna | Domyślnie | Znaczenie |
|---------|-----------|-----------|
| `WEBHOOK_GENERIC_PREVIOUS_SECRET` | puste | poprzedni sekret, akceptowany tylko na czas rotacji |
| `WEBHOOK_SIGNATURE_TOLERANCE` | `300` | dopuszczalna różnica w sekundach między znacznikiem czasu podpisu a zegarem serwera |
| `NOTIFICATIONS_MAILTRAP_ENABLED` | `true` | włącza kanał e-mail |
| `NOTIFICATIONS_SALESTEAM_ENABLED` | `true` | włącza kanał SalesTeam |
| `NOTIFICATIONS_NOTIFY_TEST_EVENTS` | `true` poza produkcją | czy zdarzenia z `livemode: false` wyzwalają powiadomienia |
| `SALESTEAM_MOCK_MODE` | `success` | zachowanie mocka: `success`, `transient`, `permanent`, `fail_first_n` |
| `SALESTEAM_MOCK_FAIL_FIRST` | `2` | dla `fail_first_n`: ile pierwszych prób kończy się błędem przejściowym |
| `MAIL_FROM_ADDRESS` | `payments@example.test` | nadawca powiadomień e-mail |
| `APP_PORT` | `8000` | port HTTP na hoście (brak w `.env.example`, dopisz ręcznie) |
| `LOG_LEVEL` | `debug` | próg logowania |

### Czego nie ustawiasz ręcznie

- `APP_KEY` generuje kontener `init` przy pierwszym starcie.
- `DB_HOST`, `REDIS_HOST`, `QUEUE_CONNECTION` i `CACHE_STORE` są nadpisywane w `compose.yaml`,
  więc wartości z `.env` nie mają znaczenia w kontenerach.

Po każdej zmianie `.env` przy działającym stosie wykonaj
`docker compose exec app php artisan queue:restart`. Worker zakończy bieżące zadanie, a Compose
uruchomi go ponownie z nową konfiguracją.

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
curl -s http://localhost:8000/health | jq
```

Kontener `init` instaluje zależności Composera, generuje `APP_KEY` i wykonuje migracje, po czym
kończy pracę z kodem `0`. Dopiero wtedy startują `app` (serwer HTTP na porcie 8000) i `worker`
(`queue:work` na kolejce `default`).

Oczekiwany stan: `init` zakończony (`exited (0)`), pozostałe usługi `healthy`, a `/health`
zwraca `"status": "up"` dla komponentów `app`, `database`, `queue`, `cache`, `worker` i `mail`.
Gdy któryś komponent nie działa, `/health` odpowiada `503` i podaje przyczynę w polu `reason`.
Więcej szczegółów (np. klasę wyjątku SMTP) pokazuje wersja konsolowa:

```bash
docker compose exec app php artisan app:health
docker compose exec app php artisan app:health --only=mail
```

Przydatne polecenia:

```bash
docker compose logs init            # instalacja, klucz, migracje
docker compose down                 # zatrzymanie z zachowaniem danych
docker compose down -v              # zatrzymanie i usunięcie bazy oraz Redisa
```

## Webhook płatności

### Żądanie

```http
POST /api/v1/webhooks/payment
Content-Type: application/json
X-Payment-Provider: generic
X-Webhook-Signature: t=1718029200,v1=b8e80ecc…
X-Correlation-ID: <opcjonalny>
```

| Nagłówek | Reguła |
|----------|--------|
| `X-Payment-Provider` | wymagany; identyfikator dostawcy z `config/webhooks.php`, w tej wersji `generic` |
| `X-Webhook-Signature` | wymagany; `t=<unix_seconds>,v1=<hex_hmac>` |
| `X-Correlation-ID` | opcjonalny; przyjmowany, gdy pasuje do `^[A-Za-z0-9._:-]{1,128}$`, inaczej aplikacja generuje UUIDv7 |

Adres jest wspólny dla wszystkich dostawców, a dostawcę wskazuje nagłówek. Ciało może mieć
najwyżej 1 MiB.

### Podpis

```text
signed_payload = t + "." + surowe_ciało
v1             = hex( HMAC-SHA256( klucz = WEBHOOK_GENERIC_SECRET, wiadomość = signed_payload ) )
```

Podpis liczony jest z surowych bajtów ciała, więc wysłane ciało musi być identyczne co do bajtu
z podpisanym. Znacznik `t` może różnić się od zegara serwera najwyżej o 300 sekund. W czasie
rotacji sekretu nagłówek może zawierać dwie wartości `v1`.

### Payload

Przykład jest w pliku `payment_payload_example.json` w katalogu głównym:

```json
{
  "event": "charge.successful",
  "event_id": "evt_9f8d7c6b5a4321",
  "timestamp": 1718029200,
  "livemode": true,
  "data": {
    "transaction": {
      "id": "txn_8a7b6c5d4e3f",
      "provider_reference": "PAYU-9876543210",
      "amount": { "value": 29990, "currency": "PLN" },
      "payment_method": { "type": "blik", "issuer": "mBank" },
      "status": "COMPLETED"
    },
    "customer": {
      "id": "cus_11223344",
      "email": "jan.kowalski@example.com",
      "billing_address": {
        "first_name": "Jan",
        "last_name": "Kowalski",
        "company": "Kowalski Dev Ltd",
        "country": "PL"
      }
    },
    "metadata": {
      "order_id": "ORD-2024-8839",
      "store_id": "store_main_pl",
      "channel": "mobile_app"
    }
  }
}
```

Pola wymagane:

- `event`, `event_id`, `timestamp`
- `data.transaction.id`
- `data.transaction.amount.value` (liczba całkowita większa od 0, w jednostkach podrzędnych)
- `data.transaction.amount.currency` (trzy wielkie litery)
- `data.transaction.status`
- `data.metadata.order_id`

Pozostałe pola są opcjonalne, a `livemode` domyślnie przyjmuje `true`. Każdy typ `event` jest
przyjmowany i powiadamiany.

### Odpowiedzi

Kontrole wykonywane są w kolejności z tabeli, a pierwsza niespełniona kończy przetwarzanie.

| # | Kontrola | Odpowiedź przy niepowodzeniu |
|---|----------|------------------------------|
| 1 | ciało najwyżej 1 MiB | `413 payload_too_large` |
| 2 | `X-Payment-Provider` wskazuje zarejestrowanego dostawcę | `401 unknown_provider` |
| 3 | dostawca ma ustawiony sekret | `500 internal_error` |
| 4 | podpis obecny, poprawny i w oknie czasowym | `401 invalid_signature` |
| 5 | ciało niepuste i poprawny JSON | `400 malformed_payload` |
| 6 | pola zgodne z regułami dostawcy | `422 validation_failed` z polem `details` |
| 7 | para dostawca + `event_id` jeszcze nieznana | `200` ze `"status": "duplicate"` |
| 8 | zapis zdarzenia i przekazanie do kolejki | `500 internal_error` |

Zdarzenie przyjęte:

```json
{
  "status": "accepted",
  "message": "Event accepted for processing.",
  "event_id": "evt_9f8d7c6b5a4321",
  "correlation_id": "01933f6e-8f3a-7c1d-9b2e-5a4c3d2e1f00"
}
```

Duplikat ma ten sam kształt, ze `"status": "duplicate"` i komunikatem
`Event already received and is being processed.`

Błąd:

```json
{
  "error": {
    "code": "invalid_signature",
    "message": "Webhook signature verification failed.",
    "correlation_id": "01933f71-…"
  }
}
```

Odpowiedź `401 invalid_signature` celowo nie mówi, co jest nie tak z podpisem. Dokładna
przyczyna (`missing_signature`, `signature_expired`, `invalid_signature`) trafia tylko do logu.

## Testowanie za pomocą curl

### Pomocnik do podpisanych żądań

Zdefiniuj w powłoce, w katalogu głównym projektu:

```bash
send_webhook() {  # użycie: send_webhook <plik.json> [sekret] [przesunięcie_czasu_s] [provider]
  local body; body="$(cat "$1")"
  local secret="${2:-whsec_dev_secret}" t=$(( $(date +%s) + ${3:-0} )) provider="${4:-generic}"
  local sig; sig=$(printf '%s.%s' "$t" "$body" | openssl dgst -sha256 -hmac "$secret" -hex | sed 's/^.* //')
  curl -s -w '\nHTTP %{http_code}\n' -X POST "localhost:8000/api/v1/webhooks/payment" \
    -H 'Content-Type: application/json' -H "X-Payment-Provider: $provider" \
    -H "X-Webhook-Signature: t=$t,v1=$sig" --data-binary "$body"
}
```

W drugim terminalu włącz podgląd logów:

```bash
docker compose logs -f app worker | grep -E 'webhook\.|notification|salesteam'
```

### Cały proces

```bash
send_webhook payment_payload_example.json
```

Co powinno się wydarzyć:

1. Odpowiedź `HTTP 200` ze `"status": "accepted"`, log `webhook.accepted`.
2. Zdarzenie zostaje zapisane w tabeli `webhook_events`, a listener zleca po jednym jobie na
   włączony kanał (log `notifications.queued`).
3. W ciągu minuty w skrzynce Mailtrap pojawia się mail z tematem
   `Płatność: charge.successful · zamówienie ORD-2024-8839 · 299,90 PLN`.
4. Mock SalesTeam zapisuje log `salesteam.mock.received`.
5. Dla każdego kanału pojawia się log `notification.delivered`.

Wyślij to samo jeszcze raz. Odpowiedź to `HTTP 200` ze `"status": "duplicate"`, w logu jest
`webhook.duplicate`, a drugi mail i drugi wpis mocka nie powstają.

### Odrzucenia

Kolejne zdarzenia wymagają nowego `event_id`, bo powtórzony zostanie uznany za duplikat.
Kopię payloadu z innym identyfikatorem przygotujesz tak:

```bash
jq '.event_id = "evt_test_2"' payment_payload_example.json > /tmp/pay2.json
```

| Scenariusz | Komenda | Wynik |
|------------|---------|-------|
| błędny sekret | `send_webhook payment_payload_example.json zly_sekret` | `401 invalid_signature`, log `reason=invalid_signature` |
| podpis sprzed 400 s | `send_webhook /tmp/pay2.json whsec_dev_secret -400` | `401 invalid_signature`, log `reason=signature_expired` |
| nieznany dostawca | `send_webhook /tmp/pay2.json whsec_dev_secret 0 stripe` | `401 unknown_provider` |
| ucięty JSON | `echo '{"event":' > /tmp/bad.json && send_webhook /tmp/bad.json` | `400 malformed_payload` |
| ucięty JSON z błędnym sekretem | `send_webhook /tmp/bad.json zly_sekret` | `401`, bo podpis jest sprawdzany przed treścią |
| ujemna kwota | `jq '.event_id = "evt_test_3" \| .data.transaction.amount.value = -5' payment_payload_example.json > /tmp/neg.json && send_webhook /tmp/neg.json` | `422 validation_failed`, `details` wskazuje `data.transaction.amount.value` |

Brak nagłówka podpisu:

```bash
curl -i -X POST localhost:8000/api/v1/webhooks/payment \
  -H 'Content-Type: application/json' -H 'X-Payment-Provider: generic' \
  --data-binary @payment_payload_example.json
# 401 invalid_signature, w logu reason=missing_signature
```

### Inne typy zdarzeń i zdarzenia testowe

```bash
jq '.event_id = "evt_test_4" | .event = "charge.failed"' payment_payload_example.json > /tmp/failed.json
send_webhook /tmp/failed.json     # powiadomienie z "Zdarzenie: Płatność nieudana (charge.failed)"

jq '.event_id = "evt_test_5" | .livemode = false' payment_payload_example.json > /tmp/test.json
send_webhook /tmp/test.json       # temat maila z prefiksem "[TEST] "
```

Przy `NOTIFICATIONS_NOTIFY_TEST_EVENTS=false` zdarzenie testowe dostaje `200`, jest zapisywane
i deduplikowane, ale powiadomienia nie są wysyłane (log `notifications.test_event_skipped`).

### Rotacja sekretu

1. Ustaw `WEBHOOK_GENERIC_PREVIOUS_SECRET` na stary sekret, a `WEBHOOK_GENERIC_SECRET` na nowy.
2. Zdarzenie podpisane starym sekretem daje `200`, w logu `verified_with=previous`.
3. Zdarzenie podpisane nowym sekretem daje `200`, w logu `verified_with=current`.
4. Po wyczyszczeniu `WEBHOOK_GENERIC_PREVIOUS_SECRET` podpis starym sekretem daje `401`.

### Awarie kanału

Zachowanie mocka SalesTeam ustawiasz zmienną `SALESTEAM_MOCK_MODE` (potem `queue:restart`).

| Tryb | Co zobaczysz po wysłaniu nowego zdarzenia |
|------|-------------------------------------------|
| `fail_first_n` z `SALESTEAM_MOCK_FAIL_FIRST=2` | mail dochodzi od razu; `notification.attempt_failed` z `attempt=1`, po około 1 min `attempt=2`, po kolejnych 5 min `notification.delivered` z `attempt=3` |
| `transient` | 4 wpisy `notification.attempt_failed` w odstępach około 1, 5 i 15 min, potem `notification.dead_lettered` (łącznie 21–23 min) |
| `permanent` | od razu `notification.dead_lettered`, bez ponowień |

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
| `message` | stały klucz zdarzenia, np. `webhook.accepted` |
| `context` | dane zdarzenia (dostawca, `event_id`, kanał, numer próby, wyjątek) |
| `extra.correlation_id` | identyfikator korelacji; ten sam w odpowiedzi HTTP, w logach żądania i w logach jobów, które z niego powstały |
| `datetime` | czas wpisu |

### Wpisy webhooka i powiadomień

| `message` | Poziom | Kluczowe pola `context` |
|-----------|--------|-------------------------|
| `webhook.accepted` | info | `provider`, `event_id`, `event_type`, `livemode`, `verified_with` |
| `webhook.duplicate` | info | `provider`, `event_id` |
| `webhook.duplicate_payload_mismatch` | warning | `provider`, `event_id`, `stored_sha256`, `received_sha256` |
| `webhook.rejected` | error | `provider`, `reason`, `headers`, `body` |
| `webhook.provider_misconfigured` | error | `provider`, `missing` |
| `notifications.queued` | info | `provider`, `event_id`, `channels` |
| `notifications.test_event_skipped` | info | `provider`, `event_id` |
| `notifications.no_channels_enabled` | warning | `provider`, `event_id` |
| `notification.delivered` | info | `channel`, `provider`, `event_id`, `attempt` |
| `notification.already_delivered` | info | `channel`, `provider`, `event_id` |
| `notification.attempt_failed` | error | `channel`, `provider`, `event_id`, `attempt`, `max_attempts`, `transient`, `notification`, `exception` |
| `notification.dead_lettered` | error | jak wyżej oraz `failed_at` |
| `salesteam.mock.received` | info | `mode`, `notification` (temat i wszystkie pola powiadomienia) |

Pole `reason` w `webhook.rejected` przyjmuje wartości `unknown_provider`, `missing_signature`,
`signature_expired`, `invalid_signature`, `malformed_payload` i `validation_failed`.

Awaria joba daje dodatkowo ogólne wpisy `Job attempt failed` i `Job moved to dead-letter`
z surowym payloadem zadania, więc przy błędzie kanału zobaczysz dwa wpisy `ERROR`.

### Typowe zapytania

```bash
# Wszystko, co dotyczy jednego żądania (webhook i jego joby)
docker compose logs app worker | grep '<correlation_id>'
grep '"correlation_id":"<correlation_id>"' storage/logs/laravel-*.json | jq

# Historia jednego zdarzenia
docker compose logs app worker | grep 'evt_9f8d7c6b5a4321'

# Same błędy
docker compose logs app worker | grep '"level_name":"ERROR"'
```

Stan doręczeń dla zdarzenia jest też w bazie, w tabeli `notification_deliveries`:

```bash
docker compose exec pgsql psql -U payment_gateway -d payment_gateway \
  -c "select channel, status, attempts, last_error from notification_deliveries where event_id = 'evt_9f8d7c6b5a4321'"
```

### Maskowanie

Wartości pól, których nazwa zawiera `password`, `secret`, `token`, `apikey`, `authorization`,
`signature`, `cardnumber`, `pan`, `cvv`, `cvc`, `cookie` lub `iban`, są zastępowane tekstem
`[REDACTED]`, więc nagłówek `X-Webhook-Signature` nie trafia do logów jawnie. Teksty dłuższe niż
10 000 znaków są obcinane. Dane kontaktowe klienta (imię, e-mail) nie są maskowane. Listę kluczy
zmienisz w `config/logging.php` w sekcji `redact`.

## Dead-letter i ponawianie jobów

Job powiadomienia ma 4 próby z ponowieniami po 1, 5 i 15 minutach. Po wyczerpaniu prób albo
po błędzie trwałym kanału trafia do tabeli `failed_jobs` w PostgreSQL razem z pełnym payloadem,
identyfikatorem korelacji i treścią wyjątku, a w logu pojawia się `notification.dead_lettered`.
Każdy kanał ma osobny job (`SendMailtrapNotification`, `SendSalesTeamNotification`), więc awaria
jednego nie zatrzymuje drugiego.

```bash
# Lista jobów w dead-letter (UUID, kolejka, klasa, czas porażki)
docker compose exec app php artisan queue:failed

# Treść wyjątku
docker compose exec pgsql psql -U payment_gateway -d payment_gateway \
  -c "select uuid, queue, failed_at, left(exception, 500) from failed_jobs order by failed_at desc"

# Ponowienie jednego joba, kilku albo wszystkich
docker compose exec app php artisan queue:retry <uuid>
docker compose exec app php artisan queue:retry <uuid-1> <uuid-2>
docker compose exec app php artisan queue:retry all

# Usunięcie wpisu bez ponawiania / wyczyszczenie dead-letter
docker compose exec app php artisan queue:forget <uuid>
docker compose exec app php artisan queue:flush
```

Kolejność przy odzyskiwaniu:

1. Usuń przyczynę porażki, np. popraw dane Mailtrap w `.env` albo ustaw `SALESTEAM_MOCK_MODE=success`.
2. Wykonaj `docker compose exec app php artisan queue:restart`, żeby worker wczytał konfigurację.
3. Wykonaj `queue:retry <uuid>`. Job wraca na kolejkę z wyzerowanym licznikiem prób.
4. Sprawdź w logach `notification.delivered`. Jeśli job znów zawiedzie, wróci do `queue:failed`.

Ponowienie jest bezpieczne: kanał, który już potwierdził doręczenie, nie dostanie drugiego
powiadomienia (log `notification.already_delivered`). Ponowne wysłanie tego samego webhooka
przez dostawcę nie wznawia powiadomień z dead-letter, bo dostaje odpowiedź o duplikacie.
Odzyskanie odbywa się wyłącznie przez `queue:retry`.

## Testy

```bash
docker compose exec app php artisan test --compact --exclude-group=integration   # szybkie
docker compose exec app php artisan test --compact --group=integration           # PostgreSQL + Redis
```

Szybkie testy używają SQLite w pamięci, kolejki `sync` i mailera `array`, więc nie dotykają bazy
ani Redisa z Compose.

## Dokumentacja projektowa

- `specs/001-app-foundation/` zawiera specyfikację, plan i kontrakty fundamentu (Docker, stan
  systemu, logowanie, obsługa błędów).
- `specs/002-payment-webhook-notifications/` zawiera specyfikację, plan i kontrakty webhooków:
  - `contracts/webhook-http.md` opisuje endpoint, kolejność kontroli i odpowiedzi,
  - `contracts/webhook-signature.md` opisuje podpis, rotację sekretu i wektor testowy,
  - `contracts/notification-content.md` opisuje temat i pola powiadomienia,
  - `contracts/notification-channels.md` opisuje dodawanie kanału lub dostawcy,
  - `quickstart.md` zawiera pełną listę scenariuszy weryfikacyjnych.
