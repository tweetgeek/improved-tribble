# Contract: Identyfikator korelacji i jednolity format błędów HTTP

Dotyczy **wszystkich** endpointów — obecnych i przyszłych (w tym webhooków).

## Identyfikator korelacji

| Kierunek | Nagłówek | Reguła |
|----------|----------|--------|
| Żądanie | `X-Correlation-ID` (opcjonalny) | przyjmowany, jeśli pasuje do `^[A-Za-z0-9._:-]{1,128}$`; inaczej ignorowany i generowany nowy (UUIDv7) |
| Odpowiedź | `X-Correlation-ID` (zawsze) | ta sama wartość co w logach i w treści błędu |

## Format błędu

Każda odpowiedź 4xx/5xx ma `Content-Type: application/json` i treść:

```json
{
  "error": {
    "code": "malformed_payload",
    "message": "Request body is not valid JSON.",
    "correlation_id": "01933f6e-8f3a-7c1d-9b2e-5a4c3d2e1f00"
  }
}
```

Dla błędów walidacji dochodzi pole `details`:

```json
{
  "error": {
    "code": "validation_failed",
    "message": "The given data was invalid.",
    "correlation_id": "01933f6e-…",
    "details": { "event_id": ["The event id field is required."] }
  }
}
```

## Kody błędów

| HTTP | `code` | Kiedy | Logowanie |
|------|--------|-------|-----------|
| 400 | `malformed_payload` | `Content-Type` JSON, niepuste ciało, składnia JSON niepoprawna | `error`, z surowym ciałem |
| 404 | `not_found` | brak trasy / zasobu | bez logu — to nie jest błąd przetwarzania |
| 405 | `method_not_allowed` | zła metoda HTTP | bez logu — to nie jest błąd przetwarzania |
| 413 | `payload_too_large` | ciało > 1 MiB | `error`, z obciętym ciałem |
| 422 | `validation_failed` | niekompletne lub nieprawidłowe dane (Form Request) | `error`, z ciałem i błędami walidacji |
| 500 | `internal_error` | nieobsłużony wyjątek | `error`, z wyjątkiem, stack trace i ciałem |
| 503 | `service_unavailable` | tryb konserwacji / zależność niedostępna | `error` |

## Reguły

- Odpowiedź 5xx **nigdy** nie jest zwracana dla błędu po stronie klienta (SC-005).
- Treść `message` dla 500 nie ujawnia szczegółów wyjątku, gdy `APP_DEBUG=false`.
- Wpis w logu dla każdego błędu zawiera: `correlation_id`, metodę, ścieżkę, nagłówki i ciało
  żądania (zamaskowane według `config('logging.redact')`, obcięte do 10 000 znaków na pole).
- Żaden błąd nie może być zamieniony na odpowiedź 2xx.
