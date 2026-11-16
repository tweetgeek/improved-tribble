# Contract: Treść powiadomienia dla działu sprzedaży

Realizuje FR-020 i FR-020a. Treść buduje `PaymentNotificationFormatter` raz, a wszystkie kanały
z niej korzystają ([data-model.md §3.5](../data-model.md#35-paymentnotification--treść-wspólna-dla-kanałów)).

## Reguły formatowania

| Element | Reguła | Przykład |
|---------|--------|----------|
| język | polski: temat, etykiety, komunikaty | — |
| kwota | `minorUnits / 100`, 2 miejsca, przecinek dziesiętny, separator tysięcy wg `pl`, kod waluty po spacji | `29990 PLN` → `299,90 PLN`; `1234500 EUR` → `12 345,00 EUR` |
| czas | `occurredAt` w `Europe/Warsaw`, format `d.m.Y H:i T` | `1718029200` → `10.06.2024 16:20 CEST` |
| brak wartości opcjonalnej | `—` (pauza), pole zostaje na liście | `Firma: —` |
| typ zdarzenia | surowy typ; jeśli jest etykieta w `event_labels`, to `Etykieta (typ)` | `Płatność zakończona sukcesem (charge.successful)` |
| zdarzenie testowe | prefiks tematu `[TEST] ` oraz pierwsze pole `Tryb: TESTOWE` | — |
| zdarzenie produkcyjne | brak prefiksu, `Tryb: produkcyjny` | — |

## Temat

```text
[TEST] Płatność: {typ} · zamówienie {order_id} · {kwota}
```

Dla przykładowego payloadu:

```text
Płatność: charge.successful · zamówienie ORD-2024-8839 · 299,90 PLN
```

## Pola (w tej kolejności)

| # | Etykieta | Źródło (`PaymentEvent`) | Wartość dla przykładowego payloadu |
|---|----------|-------------------------|------------------------------------|
| 1 | Tryb | `livemode` | produkcyjny |
| 2 | Zdarzenie | `type` (+ etykieta) | Płatność zakończona sukcesem (charge.successful) |
| 3 | Numer zamówienia | `orderId` | ORD-2024-8839 |
| 4 | Kwota | `amount` | 299,90 PLN |
| 5 | Status transakcji | `transactionStatus` | COMPLETED |
| 6 | Klient | `customer.firstName` + `customer.lastName` | Jan Kowalski |
| 7 | Firma | `customer.company` | Kowalski Dev Ltd |
| 8 | E-mail klienta | `customer.email` | jan.kowalski@example.com |
| 9 | Kraj | `customer.country` | PL |
| 10 | Metoda płatności | `paymentMethod.type` + ` (` + `issuer` + `)` | blik (mBank) |
| 11 | Identyfikator transakcji | `transactionId` | txn_8a7b6c5d4e3f |
| 12 | Referencja dostawcy | `providerReference` | PAYU-9876543210 |
| 13 | Dostawca | `provider` | generic |
| 14 | Sklep | `storeId` | store_main_pl |
| 15 | Kanał sprzedaży | `salesChannel` | mobile_app |
| 16 | Czas zdarzenia | `occurredAt` | 10.06.2024 16:20 CEST |
| 17 | Identyfikator zdarzenia | `eventId` | evt_9f8d7c6b5a4321 |

## Kanał Mailtrap

- **Od**: `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME`.
- **Do**: `config('notifications.channels.mailtrap.recipients')` (jeden lub więcej adresów).
- **Temat**: jak wyżej.
- **Treść**: wersja HTML (tabela etykieta–wartość) i wersja tekstowa (`Etykieta: wartość`
  w liniach). Obie zawierają wszystkie 17 pól.
- **Nagłówek**: `X-Correlation-ID` z identyfikatorem korelacji (diagnostyka, SC-010).

## Kanał SalesTeam (mock)

Log `salesteam.mock.received` (poziom `info`) z kontekstem:

```json
{
  "mode": "success",
  "notification": {
    "delivery_key": "generic:evt_9f8d7c6b5a4321",
    "subject": "Płatność: charge.successful · zamówienie ORD-2024-8839 · 299,90 PLN",
    "is_test": false,
    "fields": [{ "label": "Tryb", "value": "produkcyjny" }, "…"]
  }
}
```
