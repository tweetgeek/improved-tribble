# Quickstart: walidacja webhooków płatności i powiadomień

Przewodnik po scenariuszach, które potwierdzają działanie funkcjonalności end-to-end.
Kontrakty: [webhook-http](contracts/webhook-http.md), [webhook-signature](contracts/webhook-signature.md),
[notification-content](contracts/notification-content.md),
[notification-channels](contracts/notification-channels.md).

## Wymagania wstępne

1. Fundament (`001-app-foundation`) jest ukończony i działa: `docker compose up -d`,
   a `curl -s localhost:8000/health` pokazuje wszystkie komponenty jako `up`.
2. W `.env` są dane Mailtrap Sandbox (`MAIL_USERNAME`, `MAIL_PASSWORD`) oraz:

   ```dotenv
   WEBHOOK_GENERIC_SECRET=whsec_dev_secret
   NOTIFICATIONS_SALES_TEAM_EMAILS=sales@example.test
   SALESTEAM_MOCK_MODE=success
   ```

3. Migracje są wykonane (usługa `init` przy starcie). Po każdej zmianie `.env` wykonaj
   `docker compose exec app php artisan queue:restart`. Worker wznowi się sam.

## Pomocnik: podpisane wysłanie

Zdefiniuj w powłoce. Funkcja wysyła plik bez zmiany bajtów i podpisuje go zgodnie
z kontraktem podpisu.

```bash
send_webhook() {  # użycie: send_webhook <plik.json> [sekret] [przesunięcie_czasu_s] [provider]
  local body; body="$(cat "$1")"
  local secret="${2:-whsec_dev_secret}" t=$(( $(date +%s) + ${3:-0} )) provider="${4:-generic}"
  local sig; sig=$(printf '%s.%s' "$t" "$body" | openssl dgst -sha256 -hmac "$secret" -hex | sed 's/^.* //')
  curl -s -w '\nHTTP %{http_code}\n' -X POST "localhost:8000/api/v1/webhooks/payment" \
    -H 'Content-Type: application/json' -H "X-Payment-Provider: $provider" \
    -H "X-Webhook-Signature: t=$t,v1=$sig" --data-binary "$body"
}
cp ../payment_payload_example.json /tmp/pay.json
```

Do śledzenia logów: `docker compose logs -f app worker | grep -E 'webhook\.|notification|salesteam'`.

## Scenariusze

| # | Kroki | Oczekiwany wynik | Wymagania |
|---|-------|------------------|-----------|
| 1 | `send_webhook /tmp/pay.json` | `HTTP 200`, `"status":"accepted"`; log `webhook.accepted`; w ciągu 1 min mail w skrzynce Mailtrap z tematem `Płatność: charge.successful · zamówienie ORD-2024-8839 · 299,90 PLN` i czasem `10.06.2024 16:20 CEST`; log `salesteam.mock.received` | US1-1, US3-1/2, SC-004 |
| 2 | Powtórz krok 1 | `HTTP 200`, `"status":"duplicate"`; log `webhook.duplicate`; **brak** drugiego maila i drugiego `salesteam.mock.received` | US2-1, FR-011 |
| 3 | `send_webhook /tmp/pay.json zly_sekret` | `HTTP 401`, `invalid_signature`; log `webhook.rejected` z `reason=invalid_signature` i zamaskowanym nagłówkiem podpisu | US1-2, SC-001 |
| 4 | Wyślij bez nagłówka podpisu (`curl -X POST localhost:8000/api/v1/webhooks/payment -H 'Content-Type: application/json' -H 'X-Payment-Provider: generic' --data-binary @/tmp/pay.json`) | `HTTP 401`, log `reason=missing_signature` | US1-2 |
| 5 | Zmień `event_id` w kopii pliku, potem `send_webhook /tmp/pay2.json whsec_dev_secret -400` | `HTTP 401`, log `reason=signature_expired` | US1-3, FR-002a |
| 6 | Plik z uciętym JSON-em (`{"event":`), podpisany poprawnie | `HTTP 400`, `malformed_payload` | US1-4 |
| 7 | Ten sam ucięty JSON z błędnym sekretem | `HTTP 401` (nie 400) | Edge: kolejność kontroli |
| 8 | Nowy `event_id`, `"value": -5`, podpisany poprawnie | `HTTP 422`, `details` zawiera `data.transaction.amount.value` | US1-5 |
| 9 | `send_webhook /tmp/pay.json whsec_dev_secret 0 stripe` (nieznany dostawca) oraz ten sam `curl` co w kroku 4, ale bez `-H 'X-Payment-Provider: generic'` | w obu przypadkach `HTTP 401`, `unknown_provider`, log `webhook.rejected` z `reason=unknown_provider` | US1-6, FR-001 |
| 10 | Nowy `event_id`, `"event": "charge.failed"` | `HTTP 200`; powiadomienia z `Zdarzenie: Płatność nieudana (charge.failed)` | US3-6, FR-005 |
| 11 | Nowy `event_id`, `"livemode": false` (środowisko `local`) | mail z prefiksem `[TEST] ` | US3-4 |
| 12 | Jak 11, ale `NOTIFICATIONS_NOTIFY_TEST_EVENTS=false` + `queue:restart` | `HTTP 200`, log `notifications.test_event_skipped`, brak powiadomień | US3-5, FR-019a |

### Rotacja sekretu (FR-002b)

1. Ustaw `WEBHOOK_GENERIC_PREVIOUS_SECRET=whsec_dev_secret`,
   `WEBHOOK_GENERIC_SECRET=whsec_new_secret`.
2. Nowy `event_id` podpisany **starym** sekretem → `200`, log `verified_with=previous`.
3. Nowy `event_id` podpisany **nowym** sekretem → `200`, log `verified_with=current`.
4. Wyczyść `PREVIOUS_SECRET` → podpis starym sekretem daje `401`.

### Ponowienia i automatyczny powrót (US4, SC-006)

1. `SALESTEAM_MOCK_MODE=fail_first_n`, `SALESTEAM_MOCK_FAIL_FIRST=2`, `queue:restart`.
2. Wyślij nowe zdarzenie.
3. Oczekiwane wyniki:
   - Mailtrap dostaje maila od razu (izolacja, SC-008);
   - log `notification.attempt_failed` (`channel=salesteam`, `attempt=1`), po ~1 min
     `attempt=2`, po kolejnych ~5 min `notification.delivered` z `attempt=3`;
   - w tabeli `notification_deliveries` wiersz `salesteam` ma `status=delivered`
     i `attempts=3`.

### Dead-letter i ręczne ponowienie (US4-4, US4-5, FR-029)

1. **Błąd trwały**: `SALESTEAM_MOCK_MODE=permanent`, `queue:restart`, nowe zdarzenie.
   Oczekiwany wynik: od razu `notification.dead_lettered`, a w
   `docker compose exec app php artisan queue:failed` widać `SendSalesTeamNotification`.
2. **Wyczerpanie prób**: `SALESTEAM_MOCK_MODE=transient`, nowe zdarzenie. Oczekiwany wynik:
   4 wpisy `notification.attempt_failed` w odstępach ~1, ~5, ~15 min, potem
   `notification.dead_lettered` (≈ 21–23 min, SC-007). Wpis w `queue:failed`
   zawiera `correlation_id`.
3. **Odzyskanie**: `SALESTEAM_MOCK_MODE=success`, `queue:restart`,
   `php artisan queue:retry <uuid>`. Oczekiwany wynik: `notification.delivered`.
   Ponowny `queue:retry` tego samego wpisu (jeśli zostanie dodany ponownie) daje
   `notification.already_delivered` bez drugiej wysyłki.

### Diagnostyka po identyfikatorze (SC-010)

```bash
docker compose exec app php artisan tinker --execute 'dump(App\Models\NotificationDelivery::where("event_id", "evt_9f8d7c6b5a4321")->get(["channel","status","attempts","last_error"])->toArray());'
grep '"correlation_id":"<id>"' storage/logs/laravel-*.json
```

## Testy automatyczne

```bash
php artisan test --compact --exclude-group=integration          # szybkie (host lub kontener)
docker compose exec app php artisan test --compact --group=integration   # PostgreSQL + Redis
vendor/bin/pint --dirty --format agent
```

Oczekiwany wynik: wszystkie testy zielone. Grupa `integration` obejmuje równoczesne
duplikaty (10 procesów → 1 wiersz, 1 dispatch; SC-005) oraz trwałość wpisu dead-letter
z `correlation_id`.
