# Contract: `GET /health`

Pełny raport stanu zależności. Bez uwierzytelnienia; nie ujawnia konfiguracji ani danych
dostępowych (FR-011). Wbudowany `GET /up` pozostaje bez zmian (liveness procesu HTTP).

## Żądanie

```http
GET /health HTTP/1.1
Accept: application/json
X-Correlation-ID: <opcjonalny, ^[A-Za-z0-9._:-]{1,128}$>
```

## Odpowiedź — wszystko działa

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Correlation-ID: 01933f6e-8f3a-7c1d-9b2e-5a4c3d2e1f00
Cache-Control: no-store
```

```json
{
  "status": "up",
  "checked_at": "2026-11-16T10:15:00+00:00",
  "correlation_id": "01933f6e-8f3a-7c1d-9b2e-5a4c3d2e1f00",
  "components": [
    { "name": "app",      "status": "up", "reason": null, "latency_ms": 0 },
    { "name": "database", "status": "up", "reason": null, "latency_ms": 3 },
    { "name": "queue",    "status": "up", "reason": null, "latency_ms": 1 },
    { "name": "cache",    "status": "up", "reason": null, "latency_ms": 1 },
    { "name": "worker",   "status": "up", "reason": null, "latency_ms": 0 },
    { "name": "mail",     "status": "up", "reason": null, "latency_ms": 412 }
  ]
}
```

## Odpowiedź — co najmniej jeden komponent nie działa

```http
HTTP/1.1 503 Service Unavailable
```

```json
{
  "status": "down",
  "checked_at": "2026-11-16T10:15:00+00:00",
  "correlation_id": "01933f6e-8f3a-7c1d-9b2e-5a4c3d2e1f00",
  "components": [
    { "name": "app",      "status": "up",   "reason": null, "latency_ms": 0 },
    { "name": "database", "status": "up",   "reason": null, "latency_ms": 3 },
    { "name": "queue",    "status": "down", "reason": "Queue broker connection refused", "latency_ms": 5002 },
    { "name": "cache",    "status": "down", "reason": "Cache store connection refused", "latency_ms": 5001 },
    { "name": "worker",   "status": "down", "reason": "Heartbeat unavailable: cache store unreachable", "latency_ms": 0 },
    { "name": "mail",     "status": "down", "reason": "SMTP authentication failed", "latency_ms": 380 }
  ]
}
```

## Reguły

- Kolejność `components` jest stała: `app`, `database`, `queue`, `cache`, `worker`, `mail`.
- `status` każdego komponentu ∈ {`up`, `down`}; `reason` jest `null` wtedy i tylko wtedy,
  gdy `status = up`.
- Każdy komponent `down` generuje wpis w logu na poziomie `error` z tym samym `correlation_id`.
- Wynik `mail` może pochodzić z cache (≤ 60 s), aby nie przekraczać limitów Mailtrap.
- Każde sprawdzenie ma limit czasu ~5 s, więc odpowiedź przychodzi w ≤ ~10 s także wtedy,
  gdy zależność nie odpowiada.
- `reason` nigdy nie zawiera hosta, portu, loginu, hasła ani treści `.env`.
