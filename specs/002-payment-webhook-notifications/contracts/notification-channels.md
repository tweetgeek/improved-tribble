# Contract: Punkty rozszerzeń: kanały powiadomień i dostawcy płatności

Kontrakt dla deweloperów dodających kanał lub dostawcę (US5, FR-016, FR-023). Opisuje
sygnatury, gwarancje i rejestrację. Implementacje są w fazie zadań.
Decyzje: [research.md](../research.md) R3, R9–R12, R15, R16.

---

## 1. Kanał powiadomień

### 1.1 Interfejs

```php
namespace App\Services\Notifications\Channels\Contracts;

use App\Services\Payments\Data\PaymentNotification;

interface NotificationChannel
{
    /** Klucz kanału, identyczny z kluczem w config('notifications.channels'). */
    public function name(): string;

    /**
     * Doręcza powiadomienie. Powrót bez wyjątku = doręczenie potwierdzone.
     *
     * @throws \App\Services\Notifications\Channels\Exceptions\TransientChannelException  ponowić
     * @throws \App\Services\Notifications\Channels\Exceptions\PermanentChannelException  od razu dead-letter
     */
    public function send(PaymentNotification $notification): void;
}
```

**Obowiązki kanału**:
- Tłumaczy błędy swojej integracji na jeden z dwóch wyjątków (tabela Mailtrap: research R11).
  Wyjątek innego typu job traktuje jak przejściowy.
- Każde wywołanie sieciowe ma własny limit czasu krótszy niż 30 s, czyli timeout joba.
- Nie formatuje danych samodzielnie. Korzysta z `PaymentNotification` (contracts/notification-content.md).
- Nie ponawia sam, nie łapie i nie połyka wyjątków (Zasada IV).

### 1.2 Job kanału

```php
namespace App\Jobs\Notifications;

abstract class SendChannelNotification implements ShouldQueue
{
    use Queueable;

    public int $timeout = 30;

    public function __construct(public PaymentEvent $paymentEvent) {}

    abstract protected function channel(): NotificationChannel;

    public function tries(): int { return 4; }

    /** @return list<int> */
    public function backoff(): array { return [60, 300, 900]; }

    // handle(): idempotencja → send() → klasyfikacja błędu → log (research R11, R12)
    // failed(?Throwable): status failed + log notification.dead_lettered
}

final class SendMailtrapNotification extends SendChannelNotification
{
    protected function channel(): NotificationChannel { return app(MailtrapChannel::class); }
}
```

**Gwarancje dziedziczone przez każdy job kanału**:

| Gwarancja | Wymaganie |
|-----------|-----------|
| 4 próby, ponowienia po 60 / 300 / 900 s | FR-026 |
| timeout próby 30 s (< `retry_after` 90 s) | FR-031, Zasada II |
| log `notification.attempt_failed` przy każdej porażce | FR-027 |
| błąd trwały kończy job od razu (`fail()`) | FR-028 |
| `failed_jobs` + log `notification.dead_lettered` | FR-029, Zasada III |
| brak drugiej wysyłki po `delivered` | FR-030 |
| osobny job na kanał, więc awaria jednego nie blokuje innych | FR-024, Zasada V |
| `correlation_id` w każdym logu i wpisie dead-letter (Context) | FR-025 |

Job konkretny **nie może** nadpisywać `handle()` ani `failed()`. Może natomiast skrócić
`$timeout`.

### 1.3 Rejestracja (`config/notifications.php`)

```php
'channels' => [
    'mailtrap' => [
        'enabled' => env('NOTIFICATIONS_MAILTRAP_ENABLED', true),
        'job' => App\Jobs\Notifications\SendMailtrapNotification::class,
        'recipients' => explode(',', env('NOTIFICATIONS_SALES_TEAM_EMAILS', 'sales@example.test')),
    ],
    'salesteam' => [
        'enabled' => env('NOTIFICATIONS_SALESTEAM_ENABLED', true),
        'job' => App\Jobs\Notifications\SendSalesTeamNotification::class,
        'mock' => [
            'mode' => env('SALESTEAM_MOCK_MODE', 'success'),      // success|transient|permanent|fail_first_n
            'fail_first' => (int) env('SALESTEAM_MOCK_FAIL_FIRST', 2),
        ],
    ],
],
```

`NotificationChannelRegistry::enabled()` zwraca wpisy z `enabled=true`. Wpis z klasą `job`,
która nie dziedziczy po `SendChannelNotification`, to błąd konfiguracji: listener loguje
`error` i rzuca wyjątek (bez cichego pomijania).

### 1.4 Dodanie kanału: lista kroków

1. Klasa `App\Services\Notifications\Channels\XyzChannel implements NotificationChannel`.
2. Klasa `App\Jobs\Notifications\SendXyzNotification extends SendChannelNotification`.
3. Wpis `channels.xyz` w `config/notifications.php` (i zmienne w `.env.example`).
4. Testy kanału: mapowanie błędów na `Transient`/`Permanent` oraz treść.

Bez zmian w kontrolerze, listenerze, rejestrze, jobie bazowym i pozostałych kanałach.

---

## 2. Dostawca płatności

### 2.1 Interfejs

```php
namespace App\Services\Webhooks\Contracts;

interface PaymentProvider
{
    /** @throws \App\Services\Webhooks\Exceptions\InvalidWebhookSignature  → 401 */
    public function verify(Request $request): SignatureVerification;   // verifiedWith: current|previous

    /** @return array<string, list<mixed>|string>  reguły walidacji formatu dostawcy */
    public function rules(): array;

    /** Wywoływane wyłącznie na danych po walidacji. */
    public function toPaymentEvent(string $provider, array $payload): PaymentEvent;
}
```

Dostawca dostaje swoje opcje z konfiguracji przez konstruktor. Rejestr tworzy go przez
kontener z parametrem `options`.

### 2.2 Rejestracja (`config/webhooks.php`)

```php
'providers' => [
    'generic' => [
        'driver' => App\Services\Webhooks\Providers\GenericGatewayProvider::class,
        'secret' => env('WEBHOOK_GENERIC_SECRET'),
        'previous_secret' => env('WEBHOOK_GENERIC_PREVIOUS_SECRET'),
        'tolerance' => (int) env('WEBHOOK_SIGNATURE_TOLERANCE', 300),
    ],
],
```

Klucz MUSI pasować do `^[a-z0-9-]{1,50}$` i jest wartością nagłówka `X-Payment-Provider`,
którą dostawca wysyła na wspólny adres `POST /api/v1/webhooks/payment`.

### 2.3 Dodanie dostawcy: lista kroków

1. Klasa `App\Services\Webhooks\Providers\XyzProvider implements PaymentProvider`. Może korzystać
   z `HmacSignatureVerifier`, jeśli schemat podpisu jest zgodny.
2. Wpis `providers.xyz` w `config/webhooks.php` z sekretami w `.env.example`.
3. Testy: wektor podpisu, reguły walidacji, mapowanie na `PaymentEvent`.

Bez zmian w trasie (adres jest wspólny), middleware, kontrolerze, deduplikacji, listenerze i kanałach.

---

## 3. Pozostała konfiguracja (`config/notifications.php`)

| Klucz | Zmienna | Domyślnie | Znaczenie |
|-------|---------|-----------|-----------|
| `timezone` | — | `Europe/Warsaw` | strefa czasu w treści (FR-020a) |
| `notify_test_events` | `NOTIFICATIONS_NOTIFY_TEST_EVENTS` | `APP_ENV !== 'production'` | FR-019a |
| `event_labels` | — | `charge.successful` → „Płatność zakończona sukcesem”, `charge.failed` → „Płatność nieudana”, `charge.refunded` → „Zwrot płatności” | etykieta obok surowego typu; brak w mapie → tylko typ |
