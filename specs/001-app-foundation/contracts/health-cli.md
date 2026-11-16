# Contract: `php artisan app:health`

Szczegółowa diagnostyka dla operatora. Wywoływana w kontenerze:
`docker compose exec app php artisan app:health`.

## Sygnatura

```text
app:health
    {--only=* : Sprawdź tylko wskazane komponenty: app, database, queue, cache, worker, mail}
    {--json   : Wypisz raport w formacie JSON (ten sam schemat co GET /health)}
```

## Zachowanie

- Zawsze sprawdza na świeżo; nie korzysta z cache wyniku `mail`.
- W przeciwieństwie do HTTP może pokazywać szczegóły techniczne w kolumnie `reason`
  (np. klasę wyjątku), ale **nigdy** haseł ani tokenów.
- Generuje własny `correlation_id` i wypisuje go w nagłówku wyniku.

## Wyjście (tryb domyślny)

```text
Correlation ID: 01933f6e-9a10-7b55-8c11-2f3e4d5c6b7a

+----------+--------+--------------------------------------------+---------+
| Component| Status | Reason                                     | Latency |
+----------+--------+--------------------------------------------+---------+
| app      | up     |                                            | 0 ms    |
| database | up     |                                            | 2 ms    |
| queue    | up     |                                            | 1 ms    |
| cache    | up     |                                            | 1 ms    |
| worker   | up     | last heartbeat 4 s ago                     | 0 ms    |
| mail     | down   | TransportException: 535 5.7.0 Invalid credentials | 390 ms |
+----------+--------+--------------------------------------------+---------+
```

## Kody wyjścia

| Kod | Znaczenie |
|-----|-----------|
| 0 | wszystkie sprawdzone komponenty `up` |
| 1 | co najmniej jeden komponent `down` |
| 2 | nieprawidłowa wartość `--only` |

## Użycie jako healthcheck kontenera

```text
worker: php artisan app:health --only=worker   # kod 0 ⇔ świeży sygnał życia
```
