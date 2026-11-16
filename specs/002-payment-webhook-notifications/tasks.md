---

description: "Lista zadań: obsługa webhooków płatności i powiadomienia działu sprzedaży"
---

# Tasks: Obsługa webhooków płatności i powiadomienia działu sprzedaży

**Input**: Dokumenty projektowe z `/specs/002-payment-webhook-notifications/`

**Prerequisites**: [plan.md](plan.md), [spec.md](spec.md), [research.md](research.md),
[data-model.md](data-model.md), [contracts/](contracts/), [quickstart.md](quickstart.md)

**Tests**: zadania testowe są **obowiązkowe**. Konstytucja (sekcja „Proces wytwórczy
i bramki jakości”) wymaga testów Pest dla każdego joba i listenera oraz testów feature
endpointu. Testy piszemy przed implementacją i najpierw muszą być czerwone.

**Organization**: zadania są pogrupowane według user stories ze spec, w kolejności priorytetów.

## Format: `[ID] [P?] [Story] Opis`

- **[P]**: można wykonać równolegle (inny plik, brak zależności od niedokończonych zadań)
- **[Story]**: user story ze spec (US1–US5)

## Konwencje obowiązujące we wszystkich zadaniach

- Pliki tworzymy przez `php artisan make:* --no-interaction`: `make:model -mf`, `make:event`,
  `make:listener --queued`, `make:job`, `make:mail`, `make:controller --invokable`,
  `make:middleware`, `make:class`, `make:enum`, `make:interface`, `make:exception`,
  `make:test --pest` (z `--unit` dla testów w `tests/Unit`).
- PHP: konstruktory z promocją właściwości, jawne typy zwracane, PHPDoc z array shapes,
  klamry przy każdej instrukcji sterującej, klucze enumów w TitleCase.
- Logi: stałe klucze komunikatów i kontekst jako tablica, zgodnie z tabelą w research R13.
  Bez pustych `catch` i bez połykania wyjątków (Zasada IV).
- Przed użyciem API Laravela, którego nie ma w research.md, sprawdź je przez Boost `search-docs`.
- Po każdej zmianie PHP: `vendor/bin/pint --dirty --format agent`, a następnie najwęższy
  zestaw testów: `php artisan test --compact <plik>`.
- W testach kolejka to `sync`. Listener w kolejce wykonuje się wtedy synchronicznie,
  **wewnątrz transakcji zapisu webhooka**. Awarie kanałów testuj na pojedynczych jobach
  (`withFakeQueueInteractions()`, `Queue::fake()`), a nie przez pełny przepływ HTTP.

---

## Phase 1: Setup (wspólna infrastruktura)

**Purpose**: warunki wstępne, konfiguracja i routing API

- [X] T001 Potwierdź, że fundament `001-app-foundation` jest ukończony. W kodzie MUSZĄ istnieć: `app/Http/Middleware/RejectMalformedPayload.php`, `app/Jobs/Concerns/UsesDefaultRetryPolicy.php`, listenery `JobExceptionOccurred`/`JobFailed` w `app/Providers/AppServiceProvider.php`, sekcja `retry_policy` w `config/queue.php` oraz trasa `GET /health` w `routes/web.php`. Jeśli czegokolwiek brakuje, **zatrzymaj się** i dokończ najpierw `specs/001-app-foundation`, bo 002 zależy od tych elementów (research R2)
- [X] T002 [P] Dodaj do `.env.example` zmienne z research R16 z komentarzami po polsku: `WEBHOOK_GENERIC_SECRET=whsec_dev_secret`, `WEBHOOK_GENERIC_PREVIOUS_SECRET=`, `WEBHOOK_SIGNATURE_TOLERANCE=300`, `NOTIFICATIONS_NOTIFY_TEST_EVENTS=` (puste = `APP_ENV !== production`), `NOTIFICATIONS_MAILTRAP_ENABLED=true`, `NOTIFICATIONS_SALES_TEAM_EMAILS=sales@example.test`, `NOTIFICATIONS_SALESTEAM_ENABLED=true`, `SALESTEAM_MOCK_MODE=success`, `SALESTEAM_MOCK_FAIL_FIRST=2`
- [X] T003 [P] Utwórz `config/webhooks.php` z sekcją `providers.generic` zgodnie z `contracts/notification-channels.md` §2.2: `driver` = `App\Services\Webhooks\Providers\GenericGatewayProvider::class`, `secret` = `env('WEBHOOK_GENERIC_SECRET')`, `previous_secret` = `env('WEBHOOK_GENERIC_PREVIOUS_SECRET')`, `tolerance` = `(int) env('WEBHOOK_SIGNATURE_TOLERANCE', 300)`, oraz z komentarzem: klucz musi pasować do `^[a-z0-9-]{1,50}$` i jest wartością nagłówka `X-Payment-Provider`
- [X] T004 [P] Utwórz `config/notifications.php` zgodnie z `contracts/notification-channels.md` §1.3 i §3: `timezone` = `Europe/Warsaw`; `notify_test_events` = `env('NOTIFICATIONS_NOTIFY_TEST_EVENTS')`, a gdy zmienna jest pusta, `env('APP_ENV') !== 'production'` (rzutowane na bool); `event_labels` = `charge.successful` → „Płatność zakończona sukcesem”, `charge.failed` → „Płatność nieudana”, `charge.refunded` → „Zwrot płatności”; `channels.mailtrap` (`enabled`, `job`, `recipients` z `explode(',', …)` po `array_filter`/`trim`); `channels.salesteam` (`enabled`, `job`, `mock.mode`, `mock.fail_first`). Klasy jobów podaj jako `::class` (pliki powstaną w US3)
- [X] T005 Zarejestruj routing API w `bootstrap/app.php`: dodaj `api: __DIR__.'/../routes/api.php'` do `withRouting(...)` (bez `install:api` i bez Sanctum, research R1). Utwórz `routes/api.php` z pustą grupą `Route::prefix('v1')->group(...)`, w której trasa pojawi się w T029
- [X] T006 [P] Utwórz trait testowy `tests/Support/SignsWebhooks.php` (namespace `Tests\Support`) z metodami:
  - `signedWebhookHeaders(string $rawBody, ?int $timestamp = null, ?string $secret = null, string $provider = 'generic'): array` zwraca `X-Payment-Provider`, `X-Webhook-Signature: t=…,v1=…` (HMAC-SHA256 hex z `"{t}.{body}"`) i `Content-Type: application/json`;
  - `postSignedWebhook(array|string $payload, …): TestResponse` wysyła przez `$this->call('POST', route('webhooks.payment'), …, content: $rawBody)`;
  - `examplePayload(array $overrides = []): array` zwraca treść `payment_payload_example.json` z kontraktu `contracts/webhook-http.md` i scala ją rekurencyjnie z `$overrides`.

  Podłącz trait w `tests/Pest.php`: `pest()->extend(TestCase::class)->use(SignsWebhooks::class)->in('Feature')`

---

## Phase 2: Foundational (wymagania blokujące)

**Purpose**: model ujednolicony i zdarzenie domenowe, z których korzystają US1 (publikacja)
i US3 (konsumpcja)

**⚠️ CRITICAL**: żadna user story nie może się zacząć przed ukończeniem tej fazy

- [X] T007 [P] Utwórz `final readonly class Money` w `app/Services/Payments/Data/Money.php` z polami `int $minorUnits` („> 0, liczba całkowita (np. `29990`)”) i `string $currency` („`^[A-Z]{3}$` (np. `PLN`)”). Konstruktor rzuca `InvalidArgumentException`, gdy reguły nie są spełnione. Wykładnik waluty zawsze 2 (data-model §3.2)
- [X] T008 [P] Utwórz `final readonly class PaymentMethodDetails` w `app/Services/Payments/Data/PaymentMethodDetails.php` z polami `?string $type`, `?string $issuer` (data-model §3.3)
- [X] T009 [P] Utwórz `final readonly class CustomerDetails` w `app/Services/Payments/Data/CustomerDetails.php` z polami `?string $id`, `?string $email`, `?string $firstName`, `?string $lastName`, `?string $company`, `?string $country` („ISO 3166-1 alfa-2”) (data-model §3.4)
- [X] T010 Utwórz `final readonly class PaymentEvent` w `app/Services/Payments/Data/PaymentEvent.php` z polami z data-model §3.1:
  - wymagane: `string $provider`, `string $eventId`, `string $type`, `CarbonImmutable $occurredAt` (UTC), `bool $livemode`, `string $transactionId`, `Money $amount`, `string $transactionStatus`, `string $orderId`;
  - opcjonalne: `?string $providerReference`, `?PaymentMethodDetails $paymentMethod`, `?CustomerDetails $customer`, `?string $storeId`, `?string $salesChannel`.

  Dodaj metodę `deliveryKey(): string` zwracającą `"{$provider}:{$eventId}"`. Zależy od T007–T009
- [X] T011 Utwórz zdarzenie `App\Events\PaymentEventReceived` w `app/Events/PaymentEventReceived.php` (`php artisan make:event`) z `public PaymentEvent $paymentEvent` w konstruktorze. Zdarzenie **nie** implementuje `ShouldDispatchAfterCommit` (research R7, uzasadnienie w PHPDoc klasy). Zależy od T010
- [X] T012 [P] Utwórz enum `App\Enums\WebhookReceipt` w `app/Enums/WebhookReceipt.php` z przypadkami `Accepted` i `Duplicate` (string-backed: `accepted`, `duplicate`)

**Checkpoint**: model ujednolicony i zdarzenie gotowe. US1 i US3 mogą ruszyć równolegle.

---

## Phase 3: User Story 1: Przyjęcie autentycznego i poprawnego webhooka (Priority: P1) 🎯 MVP

**Goal**: `POST /api/v1/webhooks/payment` rozpoznaje dostawcę z `X-Payment-Provider`,
weryfikuje podpis HMAC z oknem ±300 s i rotacją sekretów, parsuje i waliduje treść, zapisuje
zdarzenie i w tej samej transakcji publikuje `PaymentEventReceived`. Odrzucenia dostają
401/400/422/500 z logiem `webhook.rejected`.

**Independent Test**: scenariusze 1 i 3–9 z [quickstart.md](quickstart.md) oraz
`php artisan test --compact tests/Feature/Webhooks/ReceiveWebhookTest.php tests/Feature/Webhooks/WebhookAuthenticationTest.php tests/Unit/Webhooks`.
Poprawny webhook daje 200 `accepted`, wiersz w `webhook_events` i jedno `PaymentEventReceived`.
Każdy wariant błędny daje właściwy kod, brak wiersza i wpis w logu.

### Tests for User Story 1 ⚠️ (najpierw czerwone)

- [X] T013 [P] [US1] Test jednostkowy `tests/Unit/Webhooks/HmacSignatureVerifierTest.php` (`make:test --pest --unit`):
  - wektor testowy z `contracts/webhook-signature.md` (sekret `whsec_test_secret`, `t=1718029200`, ciało `{"event":"charge.successful","event_id":"evt_test_1"}`, oczekiwany `v1=b8e80ecc138a0aedc617c4ce00d848da10f829a787bb7f160c7111497c6d8ed5`) przechodzi przy `now = t`;
  - `|now − t| = 300` przechodzi, a `301` w przeszłość i w przyszłość daje `signature_expired`;
  - brak nagłówka, brak `t`, brak `v1`, `t` nienumeryczne lub 3 wartości `v1` dają `missing_signature`;
  - zły HMAC daje `invalid_signature`;
  - rotacja: podpis starym sekretem przy ustawionym `previous_secret` daje `verifiedWith = previous`, a przy pustym `previous_secret` daje `invalid_signature`;
  - dwie wartości `v1` (stara i nowa) dają sukces.
- [X] T014 [P] [US1] Test jednostkowy `tests/Unit/Webhooks/GenericGatewayProviderTest.php`:
  - `toPaymentEvent('generic', $examplePayload)` daje `PaymentEvent` z wartościami z `payment_payload_example.json`: `eventId=evt_9f8d7c6b5a4321`, `type=charge.successful`, `occurredAt=2024-06-10 14:20:00 UTC`, `livemode=true`, `amount=29990 PLN`, `orderId=ORD-2024-8839`, `paymentMethod=blik/mBank`, `customer.company=Kowalski Dev Ltd`;
  - brak `livemode` daje `true`;
  - brak sekcji opcjonalnych (`customer`, `payment_method`, `store_id`, `channel`) daje `null`;
  - `rules()` zawiera dokładnie reguły z data-model §4.
- [X] T015 [P] [US1] Test feature `tests/Feature/Webhooks/WebhookAuthenticationTest.php` (użyj `SignsWebhooks`, `RefreshDatabase`, `Event::fake([PaymentEventReceived::class])`, `Log::spy()`).

  **401 `unknown_provider`** dla:
  - braku `X-Payment-Provider`;
  - wartości `stripe` (niezarejestrowany dostawca);
  - wartości `Generic!` (zły format).

  **401 `invalid_signature`** z przyczyną w logu dla:
  - braku `X-Webhook-Signature` (`missing_signature`);
  - złego sekretu (`invalid_signature`);
  - `t` sprzed 400 s (`signature_expired`), z użyciem `$this->travelTo`.

  **Kolejność kontroli**: uszkodzony JSON ze złym podpisem daje 401, nie 400; ujemna kwota ze złym podpisem daje 401, nie 422.

  **Rotacja**: przy `config(['webhooks.providers.generic.previous_secret' => 'old'])` podpis sekretem `old` daje 200, a log `webhook.accepted` ma `verified_with=previous`.

  **Brak sekretu**: przy `config(['webhooks.providers.generic.secret' => null])` odpowiedź to 500 i log `webhook.provider_misconfigured`.

  **Asercje wspólne dla każdego odrzucenia**:
  - JSON w formacie `{"error":{"code","message","correlation_id"}}`;
  - log `error` z kluczem `webhook.rejected` i polami `provider`, `reason`, `headers`, `body`;
  - `assertDatabaseCount('webhook_events', 0)`;
  - `Event::assertNotDispatched`.
- [X] T016 [P] [US1] Test feature `tests/Feature/Webhooks/ReceiveWebhookTest.php`.

  **Przyjęcie poprawnego webhooka**:
  - 200 z treścią `{"status":"accepted","message":"Event accepted for processing.","event_id":"evt_9f8d7c6b5a4321","correlation_id":…}`, a `correlation_id` jest równy nagłówkowi `X-Correlation-ID` odpowiedzi;
  - wiersz `webhook_events` z `provider=generic`, `event_type`, `livemode`, `occurred_at`, `payload` (pełny, łącznie z nieznanym polem `extra_field`), `payload_sha256 = hash('sha256', $rawBody)` i `correlation_id`;
  - `Event::assertDispatched(PaymentEventReceived::class)` z poprawnym `PaymentEvent`;
  - log `webhook.accepted`;
  - zdarzenie `charge.failed` też daje 200 (FR-005).

  **400 `malformed_payload`** (podpis poprawny):
  - ucięty JSON;
  - puste ciało;
  - JSON skalarny `"abc"`.

  **422 `validation_failed`** z kluczem w `details` dla:
  - braku `event_id`;
  - `amount.value = -5`;
  - `amount.value = "29990"` (string, `integer:strict`);
  - `amount.value = 299.9`;
  - `currency = "pln"`;
  - braku `data.metadata.order_id`;
  - `livemode = "yes"`.

  **500 i rollback**: `Event::listen(PaymentEventReceived::class, fn () => throw new RuntimeException('queue down'))` daje 500 `internal_error` i `assertDatabaseCount('webhook_events', 0)` (FR-009). Kolejne poprawne doręczenie po usunięciu listenera daje 200 `accepted`.
- [X] T017 [P] [US1] Dopisz do testu fundamentu `tests/Feature/Http/MalformedPayloadTest.php` (lub utwórz go, jeśli 001 go nie zawiera) dwa przypadki: uszkodzony JSON wysłany na `/api/v1/webhooks/payment` **nie** jest odrzucany przez globalny middleware (decyduje middleware webhooka); ciało > 1 MiB na `/api/v1/webhooks/payment` nadal daje 413 `payload_too_large`

### Implementation for User Story 1

- [X] T018 [P] [US1] Utwórz migrację i model `WebhookEvent` (`php artisan make:model WebhookEvent -mf`): `database/migrations/xxxx_create_webhook_events_table.php`, `app/Models/WebhookEvent.php`, `database/factories/WebhookEventFactory.php`.

  Kolumny zgodnie z data-model §1.1:

  | Kolumna | Typ | Null |
  |---------|-----|------|
  | `id` | `bigint` PK | nie |
  | `provider` | `varchar(50)` | nie |
  | `event_id` | `varchar(255)` | nie |
  | `event_type` | `varchar(100)` | nie |
  | `livemode` | `boolean` | nie |
  | `occurred_at` | `timestamptz` | nie |
  | `payload` | `jsonb` | nie |
  | `payload_sha256` | `char(64)` | nie |
  | `correlation_id` | `varchar(128)` | nie |
  | `received_at` | `timestamptz` | nie |
  | `created_at` / `updated_at` | `timestamptz` | |

  Indeksy: `UNIQUE (provider, event_id)` oraz `INDEX (correlation_id)`. W migracji użyj `timestampTz`, `jsonb` i `char`, które działają także na sqlite w testach.

  Model:
  - `$fillable` dla wszystkich kolumn poza `id` i znacznikami;
  - `casts()`: `payload` → `array`, `livemode` → `boolean`, `occurred_at` i `received_at` → `immutable_datetime`;
  - PHPDoc z typami właściwości.

  Factory: stan domyślny zgodny z przykładowym payloadem oraz stan `test()` z `livemode=false`.
- [X] T019 [P] [US1] Utwórz wyjątki w `app/Services/Webhooks/Exceptions/`:
  - `UnknownPaymentProvider` → 401, `code=unknown_provider`, `message="Unknown payment provider."`;
  - `InvalidWebhookSignature` → 401, `code=invalid_signature`, `message="Webhook signature verification failed."`. Przechowuje `reason`: `missing_signature` | `signature_expired` | `invalid_signature`;
  - `PaymentProviderMisconfigured` → 500 `internal_error`. Przechowuje `provider` i `missing`.

  Każdy wyjątek ma `render(Request): JsonResponse` w formacie kontraktu fundamentu `{"error":{"code","message","correlation_id": context('correlation_id')}}`. Każdy ma też `report(): void`, które zapisuje `Log::error`:
  - `webhook.rejected` z polami `provider`, `reason`, `headers` (`request()->headers->all()`) i `body` (`request()->getContent()`), które maskuje i obcina procesor F-R9;
  - albo `webhook.provider_misconfigured` z polami `provider` i `missing`.

  `report()` nie zwraca `false`, więc domyślne raportowanie Laravela nie dubluje wpisu.
- [X] T020 [P] [US1] Utwórz VO `final readonly class SignatureVerification` w `app/Services/Webhooks/Signatures/SignatureVerification.php` z polem `string $verifiedWith` (`current` | `previous`, stałe klasy).

  Utwórz `App\Services\Webhooks\Signatures\HmacSignatureVerifier` w `app/Services/Webhooks/Signatures/HmacSignatureVerifier.php` z metodą `verify(?string $header, string $rawBody, string $secret, ?string $previousSecret, int $tolerance): SignatureVerification`. Algorytm według `contracts/webhook-signature.md`:
  1. parsowanie `t=…,v1=…[,v1=…]`;
  2. 1–2 wartości `v1`;
  3. kontrola `|now − t| ≤ tolerance` przez `now()->getTimestamp()`, żeby działało `travelTo`;
  4. `hash_equals` względem sekretu `current`, potem `previous`, jeśli niepusty.

  Rzuca `InvalidWebhookSignature` z odpowiednim `reason`. Spełnia T013.
- [X] T021 [US1] Utwórz interfejs `App\Services\Webhooks\Contracts\PaymentProvider` w `app/Services/Webhooks/Contracts/PaymentProvider.php` z sygnaturami z `contracts/notification-channels.md` §2.1:
  - `verify(Request $request): SignatureVerification`;
  - `rules(): array`;
  - `toPaymentEvent(string $provider, array $payload): PaymentEvent`.

  Dodaj PHPDoc z array shapes. Zależy od T010, T020
- [X] T022 [US1] Utwórz `App\Services\Webhooks\Providers\GenericGatewayProvider` w `app/Services/Webhooks/Providers/GenericGatewayProvider.php` implementujący `PaymentProvider`. Konstruktor przyjmuje `HmacSignatureVerifier` oraz `array $options` (`secret`, `previous_secret`, `tolerance`). `verify()` czyta nagłówek `X-Webhook-Signature` i surowe ciało `$request->getContent()`.

  `rules()` dosłownie według data-model §4:
  - `event`: `required|string|max:100`;
  - `event_id`: `required|string|max:255`;
  - `timestamp`: `required|integer:strict|min:1`;
  - `livemode`: `sometimes|boolean:strict`;
  - `data`: `required|array`;
  - `data.transaction.id`: `required|string|max:255`;
  - `data.transaction.provider_reference`: `nullable|string|max:255`;
  - `data.transaction.amount.value`: `required|integer:strict|min:1`;
  - `data.transaction.amount.currency`: `required|string|regex:/^[A-Z]{3}$/`;
  - `data.transaction.status`: `required|string|max:50`;
  - `data.transaction.payment_method`: `nullable|array`;
  - `data.transaction.payment_method.type` i `.issuer`: `nullable|string|max:100`;
  - `data.customer`: `nullable|array`;
  - `data.customer.id`: `nullable|string|max:255`;
  - `data.customer.email`: `nullable|email`;
  - `data.customer.billing_address.first_name`, `.last_name` i `.company`: `nullable|string|max:255`;
  - `data.customer.billing_address.country`: `nullable|string|size:2`;
  - `data.metadata.order_id`: `required|string|max:255`;
  - `data.metadata.store_id` i `.channel`: `nullable|string|max:255`.

  `toPaymentEvent()` mapuje pola według tabeli data-model §3.1. `timestamp` zamienia na `CarbonImmutable::createFromTimestamp(..., 'UTC')`, a brak `livemode` traktuje jako `true`. Spełnia T014
- [X] T023 [US1] Utwórz `App\Services\Webhooks\PaymentProviderRegistry` w `app/Services/Webhooks/PaymentProviderRegistry.php` z metodą `resolve(?string $providerId): PaymentProvider`:
  - wartość niepasująca do `^[a-z0-9-]{1,50}$` lub brak klucza w `config('webhooks.providers')` → `UnknownPaymentProvider`;
  - pusty `secret` → `PaymentProviderMisconfigured` z `missing=['secret']`;
  - w pozostałych przypadkach `app()->make($config['driver'], ['options' => $config])`.

  Klasa `driver` niebędąca `PaymentProvider` to `LogicException`.
- [X] T024 [US1] Zmodyfikuj middleware fundamentu `app/Http/Middleware/RejectMalformedPayload.php`: dodaj `protected array $exceptJsonSyntax = ['api/v1/webhooks/*'];`. Sprawdzenie składni JSON (400) pomijaj, gdy `$request->is(...$this->exceptJsonSyntax)`. Limit rozmiaru 413 zostaje bez zmian dla wszystkich ścieżek (research R2). Spełnia T017
- [X] T025 [US1] Utwórz middleware `app/Http/Middleware/VerifyWebhookSignature.php` (`make:middleware`), który:
  1. czyta `X-Payment-Provider`;
  2. wywołuje `PaymentProviderRegistry::resolve()`;
  3. wywołuje `$provider->verify($request)`;
  4. zapisuje w atrybutach żądania `payment_provider_id`, `payment_provider` i `signature_verified_with`;
  5. dodaje `Context::add('payment_provider', $id)`.

  Wyjątki z T019 przechodzą dalej (bez `catch`). Zależy od T019, T023
- [X] T026 [US1] Utwórz akcję `App\Services\Webhooks\ReceivePaymentWebhook` w `app/Services/Webhooks/ReceivePaymentWebhook.php` (`make:class`) z metodą `handle(PaymentEvent $event, array $payload, string $rawBody): WebhookReceipt`. Wewnątrz `DB::transaction()`:
  1. `WebhookEvent::create([...])` z `payload_sha256 = hash('sha256', $rawBody)`, `correlation_id = Context::get('correlation_id')`, `received_at = now()`;
  2. `PaymentEventReceived::dispatch($event)`;
  3. zwraca `WebhookReceipt::Accepted`.

  W PHPDoc klasy opisz, dlaczego dispatch następuje w transakcji (research R7, tabela awarii). Deduplikację doda T033. Zależy od T011, T012, T018
- [X] T027 [US1] Utwórz kontroler `app/Http/Controllers/Webhooks/ReceiveWebhookController.php` (`make:controller Webhooks/ReceiveWebhookController --invokable`), który:
  1. czyta `$request->getContent()`; jeśli ciało jest puste, `json_validate()` = false albo po dekodowaniu nie jest tablicą, rzuca ten sam wyjątek lub odpowiedź `400 malformed_payload`, którego używa `RejectMalformedPayload`, wraz z logiem `error` z surowym ciałem (kontrakt fundamentu);
  2. wykonuje `Validator::make($payload, $provider->rules())->validate()`, a `ValidationException` daje 422 w formacie fundamentu;
  3. buduje `$provider->toPaymentEvent($providerId, $validatedPayload)` na danych zwalidowanych i scala je z oryginałem tylko przy zapisie `payload`;
  4. wywołuje `ReceivePaymentWebhook::handle()`;
  5. loguje `webhook.accepted` (`provider`, `event_id`, `event_type`, `livemode`, `verified_with`);
  6. zwraca 200 `{"status":"accepted","message":"Event accepted for processing.","event_id":…,"correlation_id":…}` zgodnie z `contracts/webhook-http.md`.

  Zależy od T022, T025, T026
- [X] T028 [US1] Upewnij się, że `bootstrap/app.php` renderuje wyjątki z T019 przez ich metody `render()`, a ścieżki `api/*` dostają JSON. Dla `ValidationException` sprawdź, że format 422 ma `details` z kluczami w notacji kropkowej (`data.transaction.amount.value`). Jeśli fundament tego nie robi, dopisz to w `withExceptions()`
- [X] T029 [US1] Dodaj trasę w `routes/api.php` wewnątrz grupy `v1`: `Route::post('/webhooks/payment', ReceiveWebhookController::class)->middleware(VerifyWebhookSignature::class)->name('webhooks.payment');`. Zweryfikuj przez `php artisan route:list --path=api` (oczekiwane: `POST api/v1/webhooks/payment`). Uruchom T013–T017 i doprowadź je do zielonego stanu

**Checkpoint**: US1 działa samodzielnie: webhook jest przyjmowany, zapisywany i publikowany,
a błędne żądania są odrzucane i logowane.

---

## Phase 4: User Story 2: Eliminacja duplikatów (Priority: P1)

**Goal**: ponowne lub równoczesne doręczenie tego samego `(provider, event_id)` dostaje
200 `duplicate`, nie tworzy drugiego wiersza i nie publikuje drugiego zdarzenia.
Rozbieżna treść daje `warning`.

**Independent Test**: scenariusz 2 z [quickstart.md](quickstart.md),
`php artisan test --compact tests/Feature/Webhooks/WebhookDeduplicationTest.php` oraz
`docker compose exec app php artisan test --compact --group=integration --filter=ConcurrentWebhookDelivery`.

### Tests for User Story 2 ⚠️

- [X] T030 [P] [US2] Test feature `tests/Feature/Webhooks/WebhookDeduplicationTest.php`:
  - drugie identyczne doręczenie → 200 `{"status":"duplicate","message":"Event already received and is being processed.","event_id":…,"correlation_id":…}`, `assertDatabaseCount('webhook_events', 1)`, `Event::assertDispatchedTimes(PaymentEventReceived::class, 1)`, log `info` `webhook.duplicate`;
  - ten sam `event_id` z inną treścią → 200 `duplicate`, log `warning` `webhook.duplicate_payload_mismatch` z `stored_sha256` ≠ `received_sha256`;
  - pierwsze doręczenie odrzucone 401 (zły sekret) lub 422 (ujemna kwota), potem poprawne → 200 `accepted` (FR-013);
  - ten sam `event_id` od dwóch dostawców (drugi testowy, zarejestrowany przez `config(['webhooks.providers.acme' => [...]])` z klasą z `tests/Support/Webhooks/AcmeTestProvider.php`) → oba `accepted`, 2 wiersze;
  - `correlation_id` w odpowiedzi na duplikat jest identyfikatorem bieżącego żądania, a wiersz zachowuje pierwotny.
- [X] T031 [P] [US2] Utwórz testową klasę dostawcy `tests/Support/Webhooks/AcmeTestProvider.php` (implementuje `PaymentProvider`, używa `HmacSignatureVerifier` i reguł formatu `generic`, by uprościć testy). Użyją jej T030 i T064
- [ ] T032 [P] [US2] Test integracyjny `tests/Feature/Integration/ConcurrentWebhookDeliveryTest.php` z `->group('integration')`, na PostgreSQL i Redisie w kontenerze. `Concurrency::run()` (driver `process`) uruchamia 10 równoległych wywołań `ReceivePaymentWebhook::handle()` z tym samym `PaymentEvent`. Oczekiwane: dokładnie 1 wynik `Accepted`, 9 wyników `Duplicate` i 1 wiersz w `webhook_events`, w 100% z 5 powtórzeń w pętli (SC-005). Brak Redisa lub PostgreSQL ma kończyć test błędem, a nie pominięciem (F-R16)

### Implementation for User Story 2

- [X] T033 [US2] Zmodyfikuj `app/Services/Webhooks/ReceivePaymentWebhook.php`: zastąp `create()` przez `WebhookEvent::createOrFirst(['provider' => …, 'event_id' => …], [...])`.
  - `wasRecentlyCreated === true`: dispatch `PaymentEventReceived` i zwróć `Accepted`.
  - W przeciwnym razie, jeśli `$existing->payload_sha256 !== hash('sha256', $rawBody)`, zaloguj `Log::warning('webhook.duplicate_payload_mismatch', ['provider','event_id','stored_sha256','received_sha256'])`. Zawsze zaloguj `Log::info('webhook.duplicate', ['provider','event_id'])` i zwróć `Duplicate` bez dispatchu.

  Całość zostaje w `DB::transaction()`. `createOrFirst` używa savepointu, więc naruszenie unikalności nie psuje transakcji PostgreSQL (research R6)
- [X] T034 [US2] Zmodyfikuj `app/Http/Controllers/Webhooks/ReceiveWebhookController.php`: dla `WebhookReceipt::Duplicate` zwróć 200 `{"status":"duplicate","message":"Event already received and is being processed.","event_id":…,"correlation_id":…}` i **nie** loguj `webhook.accepted`. Uruchom T030–T032 i doprowadź je do zielonego stanu

**Checkpoint**: US1 + US2: każde zdarzenie jest przyjmowane dokładnie raz, także przy
równoczesnych doręczeniach.

---

## Phase 5: User Story 3: Powiadomienie działu sprzedaży na wszystkich kanałach (Priority: P1)

**Goal**: listener w kolejce rozsyła każde przyjęte zdarzenie osobnym jobem na każdy włączony
kanał (Mailtrap, SalesTeam). Treść jest po polsku, z czasem w Europe/Warsaw i kwotą
`299,90 PLN`. Zdarzenia testowe są obsługiwane według konfiguracji środowiska.

**Independent Test**: scenariusze 1, 10–12 z [quickstart.md](quickstart.md) oraz
`php artisan test --compact tests/Unit/Payments tests/Feature/Notifications`. Przy
działających kanałach mail trafia do Mailtrap, a mock SalesTeam loguje
`salesteam.mock.received`, obie z tą samą treścią i `correlation_id`.

### Tests for User Story 3 ⚠️

- [X] T035 [P] [US3] Test jednostkowy `tests/Unit/Payments/PaymentNotificationFormatterTest.php` (formatter potrzebuje kontenera i configu, więc dodaj `uses(Tests\TestCase::class)` w pliku). Dla przykładowego payloadu:
  - temat `Płatność: charge.successful · zamówienie ORD-2024-8839 · 299,90 PLN`;
  - 17 pól w kolejności i z wartościami z `contracts/notification-content.md` („Pola”);
  - `Czas zdarzenia` = `10.06.2024 16:20 CEST`;
  - `Zdarzenie` = `Płatność zakończona sukcesem (charge.successful)`.

  Ponadto:
  - `1234500 EUR` → `12 345,00 EUR` (porównuj z `Number::format(12345, 2, locale: 'pl')`, bo separator tysięcy to twarda spacja);
  - `livemode=false` → prefiks tematu `[TEST] ` i pole `Tryb: TESTOWE`;
  - brak firmy lub wystawcy → wartość `—`;
  - typ spoza `event_labels` (np. `charge.disputed`) → sam typ;
  - `deliveryKey` = `generic:evt_9f8d7c6b5a4321`.
- [X] T036 [P] [US3] Test feature `tests/Feature/Notifications/QueuePaymentNotificationsTest.php`:
  - `Event::fake()` + `Event::assertListening(PaymentEventReceived::class, QueuePaymentNotifications::class)`;
  - listener implementuje `ShouldQueue`;
  - przy `Queue::fake()` wywołanie `handle()` wypycha po jednym `SendMailtrapNotification` i `SendSalesTeamNotification` z tym samym `paymentEvent` oraz loguje `notifications.queued` z `channels`;
  - `config(['notifications.channels.salesteam.enabled' => false])` → tylko Mailtrap;
  - `livemode=false` + `notify_test_events=false` → `Queue::assertNothingPushed()` i log `info` `notifications.test_event_skipped`;
  - `livemode=false` + `notify_test_events=true` → oba joby;
  - wszystkie kanały wyłączone → log `warning` `notifications.no_channels_enabled`;
  - wpis kanału z klasą `job` niedziedziczącą po `SendChannelNotification` → log `error` i wyjątek;
  - `failed(new RuntimeException)` → log `error` z `event_id` i wyjątkiem;
  - listener ma `tries`/`backoff`/`timeout` z traitu `UsesDefaultRetryPolicy`.
- [X] T037 [P] [US3] Test feature `tests/Feature/Notifications/MailtrapChannelTest.php` (`Mail::fake()`):
  - `send($notification)` → `Mail::assertSent(PaymentEventMail::class)` do adresów z `notifications.channels.mailtrap.recipients` (sprawdź dwa adresy), z tematem z formattera;
  - `PaymentEventMail` **nie** implementuje `ShouldQueue`;
  - `->assertSeeInHtml('299,90 PLN')`, `->assertSeeInText('10.06.2024 16:20 CEST')` i wszystkie 17 etykiet w obu wersjach;
  - nagłówek `X-Correlation-ID` równy `context('correlation_id')`.
- [X] T038 [P] [US3] Test feature `tests/Feature/Notifications/SalesTeamChannelTest.php` (tryb `success`): `send()` nie rzuca wyjątku i loguje `info` `salesteam.mock.received` z `mode=success` i `notification` (`delivery_key`, `subject`, `is_test`, `fields`), zgodnie z `contracts/notification-content.md` („Kanał SalesTeam”)
- [X] T039 [P] [US3] Test feature `tests/Feature/Notifications/SendChannelNotificationTest.php` (część podstawowa), na testowym jobie konkretnym i kanale-atrapie z kontenera:
  - `tries()` = 4;
  - `backoff()` = `[60, 300, 900]`;
  - timeout = 30 i `30 < config('queue.connections.redis.retry_after')`;
  - sukces: `withFakeQueueInteractions()` → `handle()` → kanał dostał `PaymentNotification`, `assertNotFailed()`, log `notification.delivered` z `channel`, `provider`, `event_id`, `attempt`;
  - `failed(new RuntimeException('x'))` → log `error` `notification.dead_lettered` z `channel`, `event_id`, `notification`, `exception` i `correlation_id`.
- [X] T040 [P] [US3] Test feature end-to-end `tests/Feature/Notifications/PaymentNotificationFlowTest.php` (kolejka `sync`, `Mail::fake()`, `Log::spy()`): `postSignedWebhook(examplePayload())` → 200 `accepted`, `Mail::assertSentCount(1)` i jeden log `salesteam.mock.received`. Drugi identyczny webhook nie wysyła niczego ponownie (spina US1 + US2 + US3)

### Implementation for User Story 3

- [X] T041 [P] [US3] Utwórz `final readonly class PaymentNotification` w `app/Services/Payments/Data/PaymentNotification.php` z polami:
  - `string $deliveryKey`;
  - `string $subject`;
  - `bool $isTest`;
  - `list<array{label: string, value: string}> $fields`;
  - `PaymentEvent $event`.

  Dodaj `toArray(): array{delivery_key: string, subject: string, is_test: bool, fields: list<array{label: string, value: string}>}` do logów (data-model §3.5)
- [X] T042 [US3] Utwórz `App\Services\Payments\PaymentNotificationFormatter` w `app/Services/Payments/PaymentNotificationFormatter.php` z metodą `format(PaymentEvent $event): PaymentNotification`, zgodnie z `contracts/notification-content.md`:
  - kwota: `Number::format($minor / 100, precision: 2, locale: 'pl').' '.$currency`;
  - czas: `$event->occurredAt->setTimezone(config('notifications.timezone'))->format('d.m.Y H:i T')`;
  - brak wartości: `—`;
  - etykieta typu z `config('notifications.event_labels')` jako `Etykieta (typ)`;
  - prefiks `[TEST] ` i pole `Tryb: TESTOWE` albo `produkcyjny`;
  - 17 pól w kolejności z kontraktu.

  Spełnia T035. Zależy od T041
- [X] T043 [P] [US3] Utwórz interfejs `App\Services\Notifications\Channels\Contracts\NotificationChannel` w `app/Services/Notifications/Channels/Contracts/NotificationChannel.php` z metodami `name(): string` i `send(PaymentNotification $notification): void`. Dodaj PHPDoc obowiązków z `contracts/notification-channels.md` §1.1: wyjątki `TransientChannelException` i `PermanentChannelException` (powstaną w US4), limit czasu < 30 s, brak samodzielnych ponowień
- [X] T044 [US3] Utwórz Mailable `app/Mail/PaymentEventMail.php` (`php artisan make:mail PaymentEventMail`, **bez** `ShouldQueue`) z konstruktorem `PaymentNotification $notification`:
  - `envelope()` → `subject` z notyfikacji;
  - `headers()` → `X-Correlation-ID` z `context('correlation_id')`;
  - `content()` → `view: 'mail.payment-event'`, `text: 'mail.payment-event-text'`.

  Utwórz widoki:
  - `resources/views/mail/payment-event.blade.php`: HTML z tabelą etykieta–wartość, `lang="pl"`, wyraźnym bannerem „ZDARZENIE TESTOWE”, gdy `isTest`;
  - `resources/views/mail/payment-event-text.blade.php`: linie `Etykieta: wartość`.

  Spełnia T037 (treść)
- [X] T045 [US3] Utwórz `App\Services\Notifications\Channels\MailtrapChannel` w `app/Services/Notifications/Channels/MailtrapChannel.php` implementujący `NotificationChannel`:
  - `name()` = `mailtrap`;
  - `send()` = `Mail::to(config('notifications.channels.mailtrap.recipients'))->send(new PaymentEventMail($notification))`, synchronicznie, bo kolejką jest sam job.

  Mapowanie błędów doda T060. Zależy od T043, T044
- [X] T046 [P] [US3] Utwórz `App\Services\Notifications\Channels\SalesTeamChannel` w `app/Services/Notifications/Channels/SalesTeamChannel.php` implementujący `NotificationChannel`:
  - `name()` = `salesteam`;
  - `send()` loguje `Log::info('salesteam.mock.received', ['mode' => …, 'notification' => $notification->toArray()])`.

  Na tym etapie obsługuje tylko tryb `success`; tryby awarii doda T059. Zależy od T043
- [X] T047 [US3] Utwórz abstrakcyjny job `app/Jobs/Notifications/SendChannelNotification.php` (`make:job`, potem `abstract`), który `implements ShouldQueue`, `use Queueable`, ma konstruktor `public PaymentEvent $paymentEvent` i `abstract protected function channel(): NotificationChannel`. Metody:
  - `tries(): int` → 4;
  - `backoff(): array` → `[60, 300, 900]`;
  - `public $timeout = 30`. Najpierw potwierdź działanie właściwości przez `search-docs "job timeout"`. Jeśli Laravel 13 jej nie respektuje, dodaj `#[Timeout(30)]` w jobach konkretnych (research R10);
  - `displayName(): string` → `static::class.':'.$this->channel()->name()`;
  - `handle(PaymentNotificationFormatter $formatter)`: formatowanie, `channel()->send()`, log `notification.delivered`;
  - `failed(?Throwable $exception)`: log `error` `notification.dead_lettered` z `channel`, `provider`, `event_id`, `notification`, `exception` (klasa, komunikat, ślad).

  W PHPDoc klasy dopisz: job konkretny nie nadpisuje `handle()` ani `failed()` (kontrakt §1.2). Idempotencję i klasyfikację błędów doda T061. Spełnia T039. Zależy od T042, T043
- [X] T048 [P] [US3] Utwórz joby konkretne `final class SendMailtrapNotification extends SendChannelNotification` (`app/Jobs/Notifications/SendMailtrapNotification.php`, `channel()` = `app(MailtrapChannel::class)`) oraz `final class SendSalesTeamNotification extends SendChannelNotification` (`app/Jobs/Notifications/SendSalesTeamNotification.php`, `channel()` = `app(SalesTeamChannel::class)`). Zależy od T045, T046, T047
- [X] T049 [US3] Utwórz `App\Services\Notifications\NotificationChannelRegistry` w `app/Services/Notifications/NotificationChannelRegistry.php` z metodą `enabled(): array<string, class-string<SendChannelNotification>>`. Metoda czyta `config('notifications.channels')` i zwraca wpisy z `enabled=true`. Wpis z `job`, który nie jest podklasą `SendChannelNotification`, loguje `Log::error('notifications.invalid_channel_config', ['channel', 'job'])` i rzuca `LogicException`
- [X] T050 [US3] Utwórz listener `app/Listeners/QueuePaymentNotifications.php` (`php artisan make:listener QueuePaymentNotifications --event=PaymentEventReceived --queued`), który `implements ShouldQueue`, `use UsesDefaultRetryPolicy` (F-R12). `handle(PaymentEventReceived $event)` działa tak:
  1. przy `livemode=false` i `config('notifications.notify_test_events')=false` loguje `info` `notifications.test_event_skipped` i kończy pracę;
  2. pobiera `NotificationChannelRegistry::enabled()`; pusta lista → `warning` `notifications.no_channels_enabled`;
  3. dla każdego kanału wywołuje `$jobClass::dispatch($event->paymentEvent)`;
  4. loguje `info` `notifications.queued` z `provider`, `event_id`, `channels`.

  `failed(PaymentEventReceived $event, Throwable $exception)` loguje `error`. Listener jest wykrywany automatycznie, bez ręcznej rejestracji. Uruchom T035–T040 i doprowadź je do zielonego stanu

**Checkpoint**: US1 + US2 + US3 to pełna wartość biznesowa przy działających kanałach.

---

## Phase 6: User Story 4: Odporność kanałów na awarie (Priority: P2)

**Goal**:
- błąd przejściowy daje ponowienia po 1, 5 i 15 min z logiem każdej porażki;
- błąd trwały daje od razu dead-letter;
- po 4 próbach zadanie trafia do `failed_jobs`;
- ponowienie, także ręczne, nie wysyła drugiego powiadomienia po potwierdzonym doręczeniu;
- awaria jednego kanału nie blokuje innych;
- mock SalesTeam ma deterministyczne tryby awarii.

**Independent Test**: sekcje „Ponowienia i automatyczny powrót” oraz „Dead-letter i ręczne
ponowienie” w [quickstart.md](quickstart.md), a także
`php artisan test --compact tests/Feature/Notifications` i
`docker compose exec app php artisan test --compact --group=integration --filter=NotificationDeadLetter`.

### Tests for User Story 4 ⚠️

- [ ] T051 [P] [US4] Rozszerz `tests/Feature/Notifications/SendChannelNotificationTest.php` (z `RefreshDatabase`).

  **Błąd przejściowy** (kanał rzuca `TransientChannelException`):
  - wyjątek leci dalej;
  - log `error` `notification.attempt_failed` z `channel`, `provider`, `event_id`, `attempt`, `max_attempts=4`, `transient=true`, `notification` i `exception`;
  - w `notification_deliveries` `status=pending`, `attempts=1` i wypełnione `last_error`.

  **Nieznany `RuntimeException`**: traktowany jak przejściowy.

  **Błąd trwały** (`PermanentChannelException`):
  - `withFakeQueueInteractions()` → `assertFailedWith(PermanentChannelException::class)`;
  - log `transient=false`.

  **Sukces**: `status=delivered`, `delivered_at` ustawione.

  **Idempotencja**: wiersz `delivered` istnieje → kanał **nie** jest wywołany, log `notification.already_delivered`, `assertNotFailed()`.

  **`failed()`**: `status=failed`, `failed_at` ustawione.

  **Ręczne ponowienie**: drugi `handle()` po `failed` zaczyna od `pending`, a po sukcesie daje `delivered` z `attempts=2`.
- [ ] T052 [P] [US4] Rozszerz `tests/Feature/Notifications/SalesTeamChannelTest.php` (cache `array`):
  - `mode=transient` → każde wywołanie rzuca `TransientChannelException`;
  - `mode=permanent` → rzuca `PermanentChannelException`;
  - `mode=fail_first_n` z `fail_first=2` → wywołania 1 i 2 rzucają `TransientChannelException`, wywołanie 3 kończy się sukcesem, a licznik jest osobny dla każdego `deliveryKey` (drugie zdarzenie zaczyna od 1);
  - każde wywołanie loguje `salesteam.mock.received` z bieżącym `mode`;
  - nieznany tryb → `LogicException`.
- [ ] T053 [P] [US4] Rozszerz `tests/Feature/Notifications/MailtrapChannelTest.php` o mapowanie błędów. Atrapa Mailera rzuca `Symfony\Component\Mailer\Exception\TransportException`:
  - kod 535 i 530 → `PermanentChannelException`;
  - `Connection could not be established` / timeout, kod 421 lub 450, kod 550 z „too many emails per second” → `TransientChannelException`;
  - puste `MAIL_USERNAME` lub `MAIL_PASSWORD` przy `MAIL_MAILER=smtp` albo pusta lista `recipients` → `PermanentChannelException` bez próby wysyłki.

  Oryginalny wyjątek ma być dostępny jako `previous`.
- [ ] T054 [P] [US4] Test izolacji `tests/Feature/Notifications/ChannelIsolationTest.php`. Ustaw `SALESTEAM_MOCK_MODE=transient` przez `config()`, wywołaj listener z `Queue::fake()`, a potem wykonaj każdy wypchnięty job osobno przez `withFakeQueueInteractions()`. Mailtrap: `Mail::assertSentCount(1)`, delivery `delivered`. SalesTeam: wyjątek, delivery `pending`. Kolejność wykonania (SalesTeam pierwszy) nie wpływa na wynik Mailtrap (FR-024, SC-008)
- [ ] T055 [P] [US4] Test integracyjny `tests/Feature/Integration/NotificationDeadLetterTest.php` z `->group('integration')`, na Redisie i PostgreSQL, `QUEUE_CONNECTION=redis`.
  - `mode=permanent`: dispatch `SendSalesTeamNotification`, `Artisan::call('queue:work', ['connection' => 'redis', '--once' => true, '--stop-when-empty' => true])`. Oczekiwane: wpis w `failed_jobs`, którego `payload` zawiera `correlation_id` (Context) i nazwę `SendSalesTeamNotification`, `exception` z `PermanentChannelException` oraz `notification_deliveries.status=failed`.
  - `mode=transient`: po jednym `--once` zadanie jest w zbiorze opóźnionym kolejki Redis (`queues:default:delayed`), z wynikiem ≈ `now + 60` s (±5 s), a `attempts=1`.

### Implementation for User Story 4

- [ ] T056 [P] [US4] Utwórz wyjątki `TransientChannelException` i `PermanentChannelException` (`make:exception`) w `app/Services/Notifications/Channels/Exceptions/`. Oba przyjmują `string $message` i `?Throwable $previous`. Uzupełnij PHPDoc `@throws` w `app/Services/Notifications/Channels/Contracts/NotificationChannel.php`
- [ ] T057 [P] [US4] Utwórz enum `App\Enums\DeliveryStatus` w `app/Enums/DeliveryStatus.php`, string-backed: `Pending = 'pending'`, `Delivered = 'delivered'`, `Failed = 'failed'`
- [ ] T058 [US4] Utwórz migrację i model `NotificationDelivery` (`php artisan make:model NotificationDelivery -mf`): `database/migrations/xxxx_create_notification_deliveries_table.php`, `app/Models/NotificationDelivery.php`, `database/factories/NotificationDeliveryFactory.php`.

  Kolumny zgodnie z data-model §1.2:

  | Kolumna | Typ | Null | Domyślnie / uwagi |
  |---------|-----|------|-------------------|
  | `id` | `bigint` PK | nie | |
  | `provider` | `varchar(50)` | nie | |
  | `event_id` | `varchar(255)` | nie | |
  | `channel` | `varchar(50)` | nie | |
  | `status` | `varchar(20)` | nie | `pending` |
  | `attempts` | `smallint` | nie | 0 |
  | `last_error` | `text` | tak | „klasa i komunikat ostatniego wyjątku, obcięte do 2000 znaków” |
  | `delivered_at` | `timestamptz` | tak | |
  | `failed_at` | `timestamptz` | tak | |
  | `correlation_id` | `varchar(128)` | nie | |
  | `created_at` / `updated_at` | `timestamptz` | | |

  Indeksy: `UNIQUE (provider, event_id, channel)` oraz `INDEX (status)`. **Bez klucza obcego** do `webhook_events` (research R7).

  Model: `casts()` → `status` → `DeliveryStatus`, `delivered_at` i `failed_at` → `immutable_datetime`.

  Factory: stany `delivered()` i `failed()`. Zależy od T057
- [ ] T059 [US4] Rozszerz `app/Services/Notifications/Channels/SalesTeamChannel.php` o tryby z `config('notifications.channels.salesteam.mock')` (research R15):
  - `success`;
  - `transient`: zawsze `TransientChannelException`;
  - `permanent`: zawsze `PermanentChannelException`;
  - `fail_first_n`: `Cache::increment("salesteam-mock:{$deliveryKey}")` z TTL 24 h (ustaw przy pierwszym zapisie); wartość ≤ `fail_first` → `TransientChannelException`, większa → sukces;
  - nieznany tryb: `LogicException`.

  Log `salesteam.mock.received` przed decyzją. Spełnia T052. Zależy od T056
- [ ] T060 [US4] Rozszerz `app/Services/Notifications/Channels/MailtrapChannel.php` o mapowanie błędów z research R11:
  - przed wysyłką: brak `config('mail.mailers.smtp.username')`/`password` przy mailerze `smtp` albo pusta lista `recipients` → `PermanentChannelException`;
  - `TransportExceptionInterface` z kodem 530 lub 535 → `PermanentChannelException`;
  - każdy inny `Throwable` z transportu → `TransientChannelException`, z `previous`.

  Spełnia T053. Zależy od T056
- [ ] T061 [US4] Rozszerz `app/Jobs/Notifications/SendChannelNotification.php` o idempotencję i klasyfikację błędów (research R11, R12, `data-model.md` §2):
  1. `NotificationDelivery::createOrFirst(['provider','event_id','channel'], ['status' => Pending, 'correlation_id' => context('correlation_id')])`;
  2. `status === Delivered` → log `info` `notification.already_delivered` i `return`;
  3. `status === Failed` (ręczne `queue:retry`) → ustaw `Pending`;
  4. `attempts++` i zapis;
  5. `send()`;
  6. sukces → `status=Delivered`, `delivered_at=now()`, log `notification.delivered`.

  Obsługa błędów:
  - `catch (PermanentChannelException $e)`: zapis `last_error` (obcięte do 2000 znaków), log `error` `notification.attempt_failed` z `transient=false`, `$this->fail($e)` i `return`;
  - `catch (Throwable $e)`: zapis `last_error`, log `error` `notification.attempt_failed` z `transient=true`, `attempt=$this->attempts()`, `max_attempts=$this->tries()`, potem `throw $e`.

  `failed()` dodatkowo ustawia `status=Failed` i `failed_at=now()` (`updateOrCreate` po kluczu, bo `failed()` działa na nowej instancji joba). Uruchom T051–T055 i doprowadź je do zielonego stanu. Zależy od T056, T058

**Checkpoint**: wszystkie gwarancje odporności (ponowienia, dead-letter, idempotencja,
izolacja) są spełnione.

---

## Phase 7: User Story 5: Rozszerzalność o nowe kanały i nowych dostawców (Priority: P3)

**Goal**: udowodnić testami, że nowy kanał i nowy dostawca działają po samym dodaniu klasy
i wpisu w konfiguracji, bez zmian w kodzie produkcyjnym (SC-009).

**Independent Test**: `php artisan test --compact tests/Feature/Notifications/ChannelExtensibilityTest.php tests/Feature/Webhooks/ProviderExtensibilityTest.php`
oraz przegląd z T068.

### Tests for User Story 5 ⚠️

- [ ] T062 [P] [US5] Utwórz klasy testowe `tests/Support/Notifications/RecordingChannel.php` (implementuje `NotificationChannel`, `name()` = `recording`, zapisuje otrzymane `PaymentNotification` w statycznej tablicy) i `tests/Support/Notifications/SendRecordingNotification.php` (`extends SendChannelNotification`, `channel()` = `app(RecordingChannel::class)`)
- [ ] T063 [P] [US5] Test `tests/Feature/Notifications/ChannelExtensibilityTest.php`. Dodaj kanał tylko przez `config(['notifications.channels.recording' => ['enabled' => true, 'job' => SendRecordingNotification::class]])`, wtedy:
  - listener z `Queue::fake()` wypycha `SendRecordingNotification` obok Mailtrap i SalesTeam;
  - job dziedziczy `tries()=4`, `backoff()=[60,300,900]` i timeout 30;
  - wykonanie joba daje `RecordingChannel` z jednym powiadomieniem i wiersz `notification_deliveries` z `channel=recording`, `status=delivered`;
  - błąd przejściowy w `RecordingChannel` daje log `notification.attempt_failed` z `channel=recording` (US5, scenariusz 1).
- [ ] T064 [P] [US5] Test `tests/Feature/Webhooks/ProviderExtensibilityTest.php`. Dodaj dostawcę `acme` tylko przez `config(['webhooks.providers.acme' => ['driver' => AcmeTestProvider::class, 'secret' => 'acme_secret', 'tolerance' => 300]])` (klasa z T031), wtedy:
  - webhook z `X-Payment-Provider: acme` podpisany `acme_secret` → 200 `accepted`, wiersz z `provider=acme`, `PaymentEventReceived` z `provider=acme`;
  - zły podpis → 401 `invalid_signature`;
  - uszkodzony JSON → 400;
  - błędna treść → 422 (US5, scenariusze 2 i 3);
  - podpis `acme_secret` z `X-Payment-Provider: generic` → 401 (sekrety dostawców są rozdzielone).

### Implementation for User Story 5

- [ ] T065 [US5] Jeśli T063 lub T064 są czerwone, usuń przyczynę w kodzie produkcyjnym tak, żeby rozszerzenie działało przez konfigurację. Przykłady: identyfikator `generic`, `mailtrap` lub `salesteam` wpisany na sztywno poza `config/` i klasami tych kanałów lub dostawców; lista kanałów wpisana w listenerze. Każdą taką zmianę dopisz w PHPDoc odpowiedniej klasy

**Checkpoint**: wszystkie user stories są niezależnie funkcjonalne.

---

## Phase 8: Polish & Cross-Cutting Concerns

**Purpose**: bramki jakości konstytucji i walidacja end-to-end

- [ ] T066 [P] Przegląd zgodności z Zasadami II–IV:
  - `grep -rn "catch" app/` → każdy `catch` loguje i rzuca dalej albo woła `fail()` (brak pustych bloków);
  - każdy job i listener (`app/Jobs/Notifications/*`, `app/Listeners/QueuePaymentNotifications.php`) deklaruje `tries`, `backoff`, `timeout` i `failed()`;
  - `PaymentEventMail` nie implementuje `ShouldQueue`;
  - w logach nie ma kluczy kontekstu zawierających `signature`, poza nagłówkiem, który procesor F-R9 maskuje (np. `verified_with`, nie `signature_key`).

  Wyniki zapisz w opisie zmiany.
- [ ] T067 [P] Dodaj przypadek do `tests/Unit/Logging/RedactSensitiveDataTest.php`: kontekst `headers` z kluczem `x-webhook-signature` jest maskowany do `[REDACTED]`, a `x-payment-provider` i `verified_with` zostają jawne (SC-008 fundamentu, research R13)
- [ ] T068 Przegląd SC-009: `grep -rnE "'(generic|mailtrap|salesteam)'" app/ routes/ bootstrap/`. Dozwolone wystąpienia są tylko w `config/*.php` oraz w `name()` klas `MailtrapChannel` i `SalesTeamChannel`. Każde inne usuń (powiązane z T065)
- [ ] T069 Uruchom `vendor/bin/pint --dirty --format agent`, a potem `php artisan test --compact --exclude-group=integration` oraz `docker compose exec app php artisan test --compact --group=integration`. Wszystkie testy MUSZĄ być zielone, a ewentualne porażki zgłoś z wyjściem
- [ ] T070 Przeprowadź ręcznie scenariusze z [quickstart.md](quickstart.md): tabela 1–12, rotacja sekretu, ponowienia `fail_first_n` (≈ 6 min), dead-letter `permanent` i `transient` (≈ 21–23 min) oraz `queue:retry`. Potwierdź SC-004 (mail < 1 min), SC-006, SC-007 i SC-010 na żywym środowisku z Mailtrap. Odchylenia zapisz jako zadania poprawkowe

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: T001 blokuje wszystko, bo wymaga ukończonego 001. T002–T004 i T006 [P]
  można wykonać równolegle. T005 przed T029.
- **Foundational (Phase 2)**: zależy od Setup i blokuje wszystkie user stories.
- **US1 (Phase 3)**: zależy od Phase 2.
- **US2 (Phase 4)**: zależy od US1, bo modyfikuje `ReceivePaymentWebhook` i kontroler z T026/T027.
- **US3 (Phase 5)**: zależy od Phase 2. Implementację i testy jednostkowe/listenera
  (T035–T039, T041–T050) można prowadzić **równolegle z US1**. Test end-to-end T040 wymaga
  ukończenia US1 i US2.
- **US4 (Phase 6)**: zależy od US3, bo rozszerza job bazowy i kanały.
- **US5 (Phase 7)**: zależy od US1 (dostawca), US3 i US4 (kanał z gwarancjami).
  T064 korzysta z T031 (US2).
- **Polish (Phase 8)**: po wszystkich user stories.

### User Story Dependencies

```text
Setup ─▶ Foundational ─┬─▶ US1 ─▶ US2 ─┐
                       │               ├─▶ US5 ─▶ Polish
                       └─▶ US3 ─▶ US4 ─┘
                            (T040 czeka na US1+US2)
```

### Within Each User Story

- Testy (⚠️) piszemy pierwsze i najpierw muszą być czerwone.
- Kolejność: DTO i modele → serwisy i kanały → middleware, kontroler i joby → podpięcie
  (trasa, listener).
- Ostatnie zadanie implementacyjne każdej story doprowadza jej testy do zielonego stanu.

### Parallel Opportunities

- Phase 1: T002, T003, T004, T006.
- Phase 2: T007, T008, T009, T012; potem T010 → T011.
- US1: testy T013–T017 razem; implementacja T018, T019, T020 razem.
- US2: T030, T031, T032.
- US3: testy T035–T039 razem; T041, T043, T046 razem; T048 po T045–T047.
- US4: testy T051–T055 razem; T056, T057 razem.
- US5: T062, T063, T064.
- **Między stories**: US1 (zespół A) i US3 (zespół B) po Phase 2.

---

## Parallel Example: User Story 1

```bash
# Testy US1 jednocześnie (różne pliki):
Task: "Test jednostkowy HmacSignatureVerifier w tests/Unit/Webhooks/HmacSignatureVerifierTest.php"
Task: "Test jednostkowy GenericGatewayProvider w tests/Unit/Webhooks/GenericGatewayProviderTest.php"
Task: "Test feature uwierzytelnienia w tests/Feature/Webhooks/WebhookAuthenticationTest.php"
Task: "Test feature przyjęcia w tests/Feature/Webhooks/ReceiveWebhookTest.php"

# Fundamenty implementacji US1 jednocześnie:
Task: "Model i migracja WebhookEvent (app/Models/WebhookEvent.php)"
Task: "Wyjątki webhooków (app/Services/Webhooks/Exceptions/)"
Task: "HmacSignatureVerifier (app/Services/Webhooks/Signatures/)"
```

## Parallel Example: User Story 3

```bash
Task: "Test formattera w tests/Unit/Payments/PaymentNotificationFormatterTest.php"
Task: "Test listenera w tests/Feature/Notifications/QueuePaymentNotificationsTest.php"
Task: "Test kanału Mailtrap w tests/Feature/Notifications/MailtrapChannelTest.php"
Task: "Test kanału SalesTeam w tests/Feature/Notifications/SalesTeamChannelTest.php"
Task: "DTO PaymentNotification w app/Services/Payments/Data/PaymentNotification.php"
Task: "Interfejs NotificationChannel w app/Services/Notifications/Channels/Contracts/NotificationChannel.php"
```

## Parallel Example: User Story 4

```bash
Task: "Wyjątki kanałów w app/Services/Notifications/Channels/Exceptions/"
Task: "Enum DeliveryStatus w app/Enums/DeliveryStatus.php"
Task: "Testy trybów mocka w tests/Feature/Notifications/SalesTeamChannelTest.php"
Task: "Test integracyjny dead-letter w tests/Feature/Integration/NotificationDeadLetterTest.php"
```

---

## Implementation Strategy

### MVP First (User Story 1)

1. Phase 1 (z twardym warunkiem T001) i Phase 2.
2. Phase 3 (US1).
3. **STOP i walidacja**: scenariusze 1 i 3–9 z quickstart. Webhook jest bezpiecznie przyjmowany
   i zapisywany, choć bez powiadomień.

### Incremental Delivery

1. Setup + Foundational.
2. US1 daje bezpieczne przyjmowanie webhooków (MVP techniczne).
3. US2 dodaje deduplikację. Dopiero od tego momentu można bezpiecznie przyjmować ponowienia
   od dostawcy.
4. US3 dodaje powiadomienia, czyli **pierwszą wartość dla działu sprzedaży**. US1 + US2 + US3
   to minimalny zakres do demonstracji biznesowej.
5. US4 dodaje odporność, wymaganą przed środowiskiem z prawdziwymi awariami.
6. US5 dodaje dowód rozszerzalności.
7. Polish: bramki jakości i walidacja quickstart.

### Parallel Team Strategy

Po Phase 2: deweloper A realizuje US1 → US2, a deweloper B US3 → US4. Spotkanie w T040
(end-to-end), potem wspólnie US5 i Polish.

---

## Notes

- [P] = inne pliki, brak zależności od niedokończonych zadań.
- Etykieta [USn] wiąże zadanie z user story w spec.md.
- Commit po każdym zadaniu lub spójnej grupie. Pint przed każdym commitem.
- Nie usuwaj istniejących testów bez zgody (`CLAUDE.md`).
- Nie dodawaj zależności Composera. Jeśli zadanie wydaje się ich wymagać, zatrzymaj się
  i zapytaj.
