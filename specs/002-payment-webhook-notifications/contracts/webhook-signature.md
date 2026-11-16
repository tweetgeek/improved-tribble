# Contract: Podpis webhooka (dostawca `generic`)

Realizuje FR-002, FR-002a i FR-002b. Decyzje: [research.md](../research.md) R4.

## Nagłówek

```text
X-Webhook-Signature: t=<unix_seconds>,v1=<hex_hmac>[,v1=<hex_hmac>]
```

- `t` to czas podpisania w sekundach UTC (liczba całkowita).
- `v1` to HMAC-SHA256 w zapisie hex (64 znaki, małe litery). Dozwolone są **1–2** wartości
  `v1`, gdy dostawca w czasie rotacji podpisuje oboma sekretami.
- Kolejność par jest dowolna. Nieznane klucze są ignorowane.

## Algorytm

```text
signed_payload = t + "." + raw_body          # surowe bajty ciała, bez ponownego kodowania JSON
signature      = hex( HMAC-SHA256( key = secret, message = signed_payload ) )
```

## Weryfikacja

1. Brak nagłówka, brak `t`, brak `v1`, `t` nie jest liczbą całkowitą lub `v1` ma więcej niż
   2 wartości → **401**, log `reason=missing_signature`.
2. `|now − t| > tolerance`, gdzie domyślnie `tolerance = 300` s → **401**,
   log `reason=signature_expired`.
3. Dla każdego aktywnego sekretu (`secret`, potem `previous_secret`, jeśli niepusty) i każdej
   wartości `v1`: `hash_equals(expected, v1)`. Pierwsza zgodność kończy weryfikację sukcesem,
   a `verified_with` przyjmuje `current` lub `previous`.
4. Brak zgodności → **401**, log `reason=invalid_signature`.

Weryfikacja działa na surowym ciele, **przed** parsowaniem JSON.

## Konfiguracja

| Klucz (`config/webhooks.php`) | Zmienna | Uwagi |
|-------------------------------|---------|-------|
| `providers.generic.secret` | `WEBHOOK_GENERIC_SECRET` | wymagany; brak → 500 i `webhook.provider_misconfigured` |
| `providers.generic.previous_secret` | `WEBHOOK_GENERIC_PREVIOUS_SECRET` | opcjonalny; usunięcie natychmiast kończy akceptację |
| `providers.generic.tolerance` | `WEBHOOK_SIGNATURE_TOLERANCE` | sekundy, domyślnie 300 |

### Procedura rotacji

1. Ustaw `PREVIOUS_SECRET` na stary sekret, a `SECRET` na nowy.
2. Zmień sekret u dostawcy.
3. Po potwierdzeniu, że ruch przechodzi z `verified_with=current`, wyczyść `PREVIOUS_SECRET`.

## Wektor testowy

| Element | Wartość |
|---------|---------|
| sekret | `whsec_test_secret` |
| `t` | `1718029200` |
| ciało | `{"event":"charge.successful","event_id":"evt_test_1"}` |
| oczekiwany `v1` | `b8e80ecc138a0aedc617c4ce00d848da10f829a787bb7f160c7111497c6d8ed5` |
| `payload_sha256` ciała | `b452981031c0da1c0e25bacc68cebf12f858fd2c198ecb3c31a1f4606b31b543` |

Wektor przechodzi weryfikację tylko przy zamrożonym czasie (`now = t`), bo poza tym
znacznik czasu wypada z okna. Test jednostkowy weryfikatora MUSI go używać.

## Wygenerowanie podpisu (powłoka)

```bash
t=$(date +%s)
sig=$(printf '%s.%s' "$t" "$(cat body.json)" | openssl dgst -sha256 -hmac "$WEBHOOK_GENERIC_SECRET" -hex | sed 's/^.* //')
echo "X-Webhook-Signature: t=$t,v1=$sig"
```

`$(cat …)` usuwa końcowy znak nowej linii. Wysyłaj więc ciało dokładnie tymi samymi bajtami,
np. `--data-binary "$(cat body.json)"`. Gotowy skrypt jest w [quickstart.md](../quickstart.md).
