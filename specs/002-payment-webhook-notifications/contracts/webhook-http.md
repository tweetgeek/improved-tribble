# Contract: Endpoint przyjmowania webhooków

Uzupełnia wspólny kontrakt błędów z fundamentu:
[`specs/001-app-foundation/contracts/http-errors.md`](../../001-app-foundation/contracts/http-errors.md).
Format błędów, nagłówek `X-Correlation-ID` i zasady logowania obowiązują bez zmian.

## Żądanie

```http
POST /api/v1/webhooks/payment
Content-Type: application/json
X-Payment-Provider: generic
X-Webhook-Signature: t=1718029200,v1=b8e80ecc…
X-Correlation-ID: <opcjonalny>
```

| Element | Reguła |
|---------|--------|
| `X-Payment-Provider` | wymagany; identyfikator zarejestrowanego dostawcy (`^[a-z0-9-]{1,50}$`); w tej wersji: `generic` |
| `X-Webhook-Signature` | wymagany; format i algorytm: [webhook-signature.md](webhook-signature.md) |
| ciało | maks. 1 MiB (fundament); JSON w formacie dostawcy |

## Kolejność kontroli

Kontrole wykonywane są w tej kolejności. Pierwsza niespełniona kończy przetwarzanie.

| # | Kontrola | Wynik przy niepowodzeniu |
|---|----------|--------------------------|
| 1 | rozmiar ciała ≤ 1 MiB (globalny middleware fundamentu) | `413 payload_too_large` |
| 2 | nagłówek `X-Payment-Provider` obecny i wskazuje zarejestrowanego dostawcę | `401 unknown_provider` |
| 3 | dostawca poprawnie skonfigurowany (jest sekret bieżący) | `500 internal_error` |
| 4 | podpis: nagłówek obecny, poprawny format, znacznik w oknie ±300 s, HMAC zgodny z aktywnym sekretem | `401 invalid_signature` |
| 5 | ciało niepuste i poprawny JSON (obiekt) | `400 malformed_payload` |
| 6 | struktura zgodna z regułami dostawcy | `422 validation_failed` (+ `details`) |
| 7 | klucz `(provider, event_id)` nieznany | `200` duplikat (patrz niżej) |
| 8 | zapis zdarzenia i publikacja w jednej transakcji | `500 internal_error` |

Każdy wynik 4xx/5xx z tej tabeli jest logowany na poziomie `error` jako `webhook.rejected`
lub `webhook.provider_misconfigured`, z nagłówkami i ciałem po maskowaniu
(research R13). Odpowiedź 401 `invalid_signature` nie mówi, która część podpisu jest błędna.

## Odpowiedzi

### 200: zdarzenie przyjęte

```json
{
  "status": "accepted",
  "message": "Event accepted for processing.",
  "event_id": "evt_9f8d7c6b5a4321",
  "correlation_id": "01933f6e-8f3a-7c1d-9b2e-5a4c3d2e1f00"
}
```

### 200: duplikat

Zwracany także wtedy, gdy treść różni się od pierwotnej (log `warning`) i gdy powiadomienia
dla pierwotnego zdarzenia trafiły do dead-letter.

```json
{
  "status": "duplicate",
  "message": "Event already received and is being processed.",
  "event_id": "evt_9f8d7c6b5a4321",
  "correlation_id": "01933f70-…"
}
```

`correlation_id` w odpowiedzi to identyfikator **bieżącego** żądania. Identyfikator
pierwotnego przyjęcia jest w `webhook_events.correlation_id`.

### 401: nieudane uwierzytelnienie

```json
{
  "error": {
    "code": "invalid_signature",
    "message": "Webhook signature verification failed.",
    "correlation_id": "01933f71-…"
  }
}
```

Dotyczy: braku nagłówka, złego formatu, znacznika czasu poza oknem, niezgodnego HMAC.
Dokładna przyczyna (`missing_signature`, `signature_expired`, `invalid_signature`) trafia
**tylko** do logu.

### 401: brak lub nieznany dostawca

```json
{
  "error": {
    "code": "unknown_provider",
    "message": "Unknown payment provider.",
    "correlation_id": "01933f72-…"
  }
}
```

Dotyczy: braku nagłówka `X-Payment-Provider`, złego formatu wartości, dostawcy spoza
`config('webhooks.providers')`. Zwracane przed weryfikacją podpisu i parsowaniem treści.
Logowane jako `webhook.rejected` z `reason=unknown_provider` (FR-001, FR-006).

### 400 / 413 / 422 / 500

Format i kody jak w kontrakcie fundamentu (`malformed_payload`, `payload_too_large`,
`validation_failed` z `details`, `internal_error`).

Przykład 422 dla ujemnej kwoty:

```json
{
  "error": {
    "code": "validation_failed",
    "message": "The given data was invalid.",
    "correlation_id": "01933f73-…",
    "details": {
      "data.transaction.amount.value": ["The data.transaction.amount.value field must be at least 1."]
    }
  }
}
```

## Treść żądania (dostawca `generic`)

Format przykładowego payloadu (`payment_payload_example.json`). Reguły walidacji:
[data-model.md §4](../data-model.md#4-reguły-walidacji-formatu-generic-fr-004).

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

**Wymagane**:
- `event`, `event_id`, `timestamp`;
- `data.transaction.id`;
- `data.transaction.amount.value` (int > 0) i `data.transaction.amount.currency` (`[A-Z]{3}`);
- `data.transaction.status`;
- `data.metadata.order_id`.

**Opcjonalne**: wszystkie pozostałe pola. `livemode` domyślnie przyjmuje `true`.

Każdy typ `event` jest akceptowany i powiadamiany (FR-005).
