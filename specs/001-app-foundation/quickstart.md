# Quickstart: walidacja fundamentu aplikacji

**Feature**: `001-app-foundation` | Kontrakty: [health-http](contracts/health-http.md),
[health-cli](contracts/health-cli.md), [http-errors](contracts/http-errors.md) |
Model: [data-model.md](data-model.md)

## Wymagania wstępne

- Docker z Compose v2+.
- Konto Mailtrap z utworzoną skrzynką Sandbox (Email Testing → Inboxes → SMTP Settings).
- Wolne porty: `8000` (HTTP). PostgreSQL i Redis nie są domyślnie wystawiane na hosta.

## 1. Uruchomienie od zera (US1, SC-001)

```bash
cp .env.example .env
# uzupełnij w .env: MAIL_USERNAME i MAIL_PASSWORD z Mailtrap Sandbox
docker compose up -d
docker compose ps
```

**Oczekiwane**:
- `init` ma status `exited (0)`; `app`, `worker`, `pgsql`, `redis` mają `running (healthy)`.
- `docker compose logs init` pokazuje instalację zależności, wygenerowanie klucza (tylko za
  pierwszym razem) i wykonane migracje.
- `curl -i http://localhost:8000/up` → `200`.
- `docker compose logs app worker | grep '"level_name":"ERROR"'` → brak wyników (SC-004).

## 2. Stan systemu (US2, SC-002)

```bash
curl -s http://localhost:8000/health | jq
docker compose exec app php artisan app:health
```

**Oczekiwane**: `"status": "up"` i 6 komponentów `up`; komenda kończy się kodem `0`.

### Komponent niedziałający

```bash
docker compose stop redis
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8000/health   # → 503
docker compose exec app php artisan app:health; echo "exit=$?"         # → queue/cache/worker down, exit=1
docker compose logs app | grep '"level_name":"ERROR"' | tail -n 3      # → wpisy z reason i correlation_id
docker compose start redis
```

### Błędne dane Mailtrap

Zmień `MAIL_PASSWORD` w `.env` na błędne, następnie:

```bash
docker compose exec app php artisan app:health --only=mail; echo "exit=$?"
```

**Oczekiwane**: `mail down` z przyczyną typu „authentication failed”, `exit=1`; aplikacja
nadal odpowiada na `/up`. Przywróć poprawne hasło.

## 3. Odporność workera (US1 scen. 3, SC-003)

```bash
docker compose kill -s SIGKILL worker
sleep 30
docker compose ps worker                                  # → running
docker compose exec app php artisan app:health --only=worker   # → up
```

## 4. Restart z zachowaniem danych (US1 scen. 4, FR-006)

```bash
docker compose down        # bez -v
docker compose up -d
docker compose logs init | grep -i 'nothing to migrate'   # migracje idempotentne
```

## 5. Uszkodzone żądania i korelacja (US3, SC-005)

```bash
curl -i -X POST http://localhost:8000/health \
  -H 'Content-Type: application/json' -H 'X-Correlation-ID: qs-test-1' \
  --data '{"event_id": "evt_1", "password": "s3cret",'
```

**Oczekiwane**: `400`, nagłówek `X-Correlation-ID: qs-test-1`, treść zgodna
z [http-errors](contracts/http-errors.md) (`code: malformed_payload`).

```bash
docker compose logs app | grep 'qs-test-1'
```

**Oczekiwane**: wpis JSON na poziomie `ERROR` z surowym ciałem, w którym zamiast `s3cret`
jest `[REDACTED]` (SC-008).

```bash
head -c 2000000 /dev/zero | tr '\0' 'a' | \
  curl -s -o /dev/null -w '%{http_code}\n' -X POST http://localhost:8000/health \
  -H 'Content-Type: application/json' --data-binary @-                     # → 413
```

## 6. Dead-letter i polityka ponowień (FR-019, FR-020)

```bash
docker compose exec app php artisan queue:failed        # → "No failed jobs found."
```

## 7. Testy automatyczne

```bash
# szybkie (host lub kontener)
php artisan test --compact --exclude-group=integration

# integracyjne: atomowa blokada (SC-006), opóźnione zadania (FR-022), trwałość dead-letter
docker compose exec app php artisan test --compact --group=integration
```

**Oczekiwane**: wszystkie testy zielone; test blokady raportuje dokładnie 1 sukces na 100 prób.
