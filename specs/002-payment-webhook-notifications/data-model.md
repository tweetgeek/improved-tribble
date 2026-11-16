# Data Model: Obsługa webhooków płatności i powiadomienia działu sprzedaży

Encje trwałe (tabele PostgreSQL), obiekty wartości przekazywane w kolejce (DTO) i stany.
Decyzje źródłowe: [research.md](research.md) R5–R7, R12, R14.

---

## 1. Tabele

### 1.1 `webhook_events` — odebrane zdarzenie

Model `App\Models\WebhookEvent` (z factory). Jeden wiersz na przyjęte zdarzenie.
Zdarzenia odrzucone (401/400/422/5xx) **nie** tworzą wiersza (FR-013).

| Kolumna | Typ | Null | Opis |
|---------|-----|------|------|
| `id` | `bigint` PK | nie | identyfikator wewnętrzny |
| `provider` | `varchar(50)` | nie | identyfikator dostawcy z nagłówka `X-Payment-Provider`, np. `generic` |
| `event_id` | `varchar(255)` | nie | identyfikator zdarzenia nadany przez dostawcę |
| `event_type` | `varchar(100)` | nie | np. `charge.successful` |
| `livemode` | `boolean` | nie | `false` = zdarzenie testowe |
| `occurred_at` | `timestamptz` | nie | czas zdarzenia u dostawcy (pole `timestamp`) |
| `payload` | `jsonb` | nie | zdekodowana treść w całości, łącznie z nieznanymi polami |
| `payload_sha256` | `char(64)` | nie | SHA-256 surowego ciała; do wykrywania rozbieżnych duplikatów (FR-014) |
| `correlation_id` | `varchar(128)` | nie | z `Context` w chwili przyjęcia |
| `received_at` | `timestamptz` | nie | czas przyjęcia |
| `created_at` / `updated_at` | `timestamptz` | nie | znaczniki Eloquent |

**Indeksy i ograniczenia**:
- `UNIQUE (provider, event_id)` to klucz deduplikacji (FR-010, FR-012);
- `INDEX (correlation_id)` pozwala operatorowi szukać po identyfikatorze korelacji (SC-010).

**Reguły**:
- Wiersz jest niezmienny po zapisie: duplikat go nie aktualizuje.
- Retencja jest bezterminowa w tej wersji (spec, Assumptions).
- Rzutowania w modelu: `payload` → `array`, `livemode` → `boolean`,
  `occurred_at`/`received_at` → `immutable_datetime`.

### 1.2 `notification_deliveries` — doręczenie powiadomienia

Model `App\Models\NotificationDelivery` (z factory). Jeden wiersz na parę
(zdarzenie, kanał). Służy do idempotencji (FR-030) i diagnostyki (SC-010).

| Kolumna | Typ | Null | Opis |
|---------|-----|------|------|
| `id` | `bigint` PK | nie | |
| `provider` | `varchar(50)` | nie | |
| `event_id` | `varchar(255)` | nie | |
| `channel` | `varchar(50)` | nie | klucz kanału z konfiguracji, np. `mailtrap`, `salesteam` |
| `status` | `varchar(20)` | nie | enum `DeliveryStatus`, domyślnie `pending` |
| `attempts` | `smallint` | nie | liczba rozpoczętych prób, domyślnie 0 |
| `last_error` | `text` | tak | klasa i komunikat ostatniego wyjątku, obcięte do 2000 znaków |
| `delivered_at` | `timestamptz` | tak | ustawiane przy `delivered` |
| `failed_at` | `timestamptz` | tak | ustawiane przy `failed` |
| `correlation_id` | `varchar(128)` | nie | |
| `created_at` / `updated_at` | `timestamptz` | nie | |

**Indeksy i ograniczenia**:
- `UNIQUE (provider, event_id, channel)`;
- `INDEX (status)`.

Celowo **bez klucza obcego** do `webhook_events`: job może wykonać się przed zatwierdzeniem
transakcji, która zapisuje zdarzenie (research R7).

### 1.3 `failed_jobs` — dead-letter (bez zmian, fundament F-R5)

Wpis zawiera zserializowany job z `PaymentEvent` (provider, event_id, kanał),
zdehydratowany `Context` z `correlation_id`, wyjątek i czas porażki. Kanał wynika z klasy
joba (`displayName`). Liczba prób jest w payloadzie wpisu (`attempts`) oraz w
`notification_deliveries.attempts`.

---

## 2. Stany doręczenia (`App\Enums\DeliveryStatus`)

```text
             createOrFirst
  (brak) ─────────────────▶ pending ──send() OK──▶ delivered   (końcowy)
                              │  ▲
              błąd przejściowy│  │ ponowienie (1 / 5 / 15 min)
                              ▼  │
                            pending (attempts++, last_error)
                              │
     błąd trwały / 4. próba ──┘──────────────────▶ failed
                                                     │
                       ręczny queue:retry  ──────────┘──▶ pending ──▶ …
```

| Przejście | Warunek | Efekt |
|-----------|---------|-------|
| — → `pending` | pierwsze wykonanie joba | `attempts=0` |
| `pending` → `pending` | błąd przejściowy, są jeszcze próby | `attempts++`, `last_error`, wyjątek rzucony dalej |
| `pending` → `delivered` | `send()` bez wyjątku | `delivered_at`, `last_error` bez zmian (historia) |
| `pending` → `failed` | `PermanentChannelException` lub wyczerpane 4 próby | `failed_at`; wpis w `failed_jobs` |
| `failed` → `pending` | ręczne `queue:retry` | job zaczyna od sprawdzenia statusu |
| `delivered` → (bez zmian) | ponowne wykonanie joba | log `notification.already_delivered`, brak wysyłki |

---

## 3. Obiekty wartości (DTO, przestrzeń `App\Services\Payments\Data`)

Wszystkie są `final readonly` i serializowalne do kolejki, bez modeli Eloquent.

### 3.1 `PaymentEvent` — model ujednolicony (FR-015)

| Pole | Typ | Źródło w formacie `generic` | Wymagane |
|------|-----|-----------------------------|----------|
| `provider` | `string` | nagłówek `X-Payment-Provider` | tak |
| `eventId` | `string` | `event_id` | tak |
| `type` | `string` | `event` | tak |
| `occurredAt` | `CarbonImmutable` (UTC) | `timestamp` | tak |
| `livemode` | `bool` | `livemode` (brak → `true`) | tak |
| `transactionId` | `string` | `data.transaction.id` | tak |
| `providerReference` | `?string` | `data.transaction.provider_reference` | nie |
| `amount` | `Money` | `data.transaction.amount` | tak |
| `transactionStatus` | `string` | `data.transaction.status` | tak |
| `paymentMethod` | `?PaymentMethodDetails` | `data.transaction.payment_method` | nie |
| `customer` | `?CustomerDetails` | `data.customer` | nie |
| `orderId` | `string` | `data.metadata.order_id` | tak |
| `storeId` | `?string` | `data.metadata.store_id` | nie |
| `salesChannel` | `?string` | `data.metadata.channel` | nie |

### 3.2 `Money`

| Pole | Typ | Reguła |
|------|-----|--------|
| `minorUnits` | `int` | > 0, liczba całkowita (np. `29990`) |
| `currency` | `string` | `^[A-Z]{3}$` (np. `PLN`) |

Wykładnik waluty to zawsze 2 (research R14).

### 3.3 `PaymentMethodDetails`

`type: ?string` (np. `blik`), `issuer: ?string` (np. `mBank`).

### 3.4 `CustomerDetails`

`id: ?string`, `email: ?string`, `firstName: ?string`, `lastName: ?string`,
`company: ?string`, `country: ?string` (ISO 3166-1 alfa-2).

### 3.5 `PaymentNotification` — treść wspólna dla kanałów

Buduje ją `PaymentNotificationFormatter` z `PaymentEvent` (research R14). Kanały **nie**
formatują danych samodzielnie.

| Pole | Typ | Przykład (payload przykładowy) |
|------|-----|--------------------------------|
| `deliveryKey` | `string` | `generic:evt_9f8d7c6b5a4321` |
| `subject` | `string` | `Płatność: charge.successful · zamówienie ORD-2024-8839 · 299,90 PLN` |
| `isTest` | `bool` | `false` |
| `fields` | `list<array{label: string, value: string}>` | patrz `contracts/notification-content.md` |
| `event` | `PaymentEvent` | do kanałów potrzebujących danych surowych (np. przyszłe API) |

---

## 4. Reguły walidacji formatu `generic` (FR-004)

Reguły zwraca `GenericGatewayProvider::rules()`. Pełny kontrakt pól:
[contracts/webhook-http.md](contracts/webhook-http.md#treść-żądania-dostawca-generic).

| Pole | Reguły |
|------|--------|
| `event` | `required`, `string`, `max:100` |
| `event_id` | `required`, `string`, `max:255` |
| `timestamp` | `required`, `integer:strict`, `min:1` |
| `livemode` | `sometimes`, `boolean:strict` |
| `data` | `required`, `array` |
| `data.transaction.id` | `required`, `string`, `max:255` |
| `data.transaction.provider_reference` | `nullable`, `string`, `max:255` |
| `data.transaction.amount.value` | `required`, `integer:strict`, `min:1` |
| `data.transaction.amount.currency` | `required`, `string`, `regex:/^[A-Z]{3}$/` |
| `data.transaction.status` | `required`, `string`, `max:50` |
| `data.transaction.payment_method` | `nullable`, `array` |
| `data.transaction.payment_method.type` / `.issuer` | `nullable`, `string`, `max:100` |
| `data.customer` | `nullable`, `array` |
| `data.customer.id` | `nullable`, `string`, `max:255` |
| `data.customer.email` | `nullable`, `email` |
| `data.customer.billing_address.first_name` / `.last_name` / `.company` | `nullable`, `string`, `max:255` |
| `data.customer.billing_address.country` | `nullable`, `string`, `size:2` |
| `data.metadata.order_id` | `required`, `string`, `max:255` |
| `data.metadata.store_id` / `.channel` | `nullable`, `string`, `max:255` |

Pola nieznane są ignorowane przy walidacji i zachowane w `webhook_events.payload`.

---

## 5. Zdarzenie domenowe

`App\Events\PaymentEventReceived` przenosi `public PaymentEvent $paymentEvent`. Jest
dispatchowane wewnątrz transakcji zapisu (research R7), wyłącznie dla nowych zdarzeń.
Duplikaty go nie emitują. Ma jednego listenera: `App\Listeners\QueuePaymentNotifications`
(w kolejce).
