# Implementation Plan: Obsługa webhooków płatności i powiadomienia działu sprzedaży

**Branch**: `002-payment-webhook-notifications` | **Date**: 2026-11-16 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/002-payment-webhook-notifications/spec.md`

## Summary

Jeden wspólny endpoint `POST /api/v1/webhooks/payment` przyjmuje zdarzenia płatności od
wszystkich dostawców; dostawcę wskazuje nagłówek `X-Payment-Provider`. Kontrole idą
w ustalonej kolejności:

1. rozpoznanie dostawcy z nagłówka (brak lub nieznany → 401);
2. podpis HMAC-SHA256 ze znacznikiem czasu w oknie ±5 min i rotacją dwóch sekretów (401);
3. składnia JSON (400);
4. walidacja formatu dostawcy (422).

Przyjęte zdarzenie zapisujemy do `webhook_events`, z deduplikacją przez indeks unikalny
`(provider, event_id)` (`createOrFirst`). W tej samej transakcji publikujemy
`PaymentEventReceived`. Duplikat dostaje 200 z komunikatem, że zdarzenie jest już
przetwarzane.

Listener w kolejce (`QueuePaymentNotifications`) rozsyła zdarzenie na każdy włączony kanał
osobnym jobem: `SendMailtrapNotification` (mail przez Mailtrap Sandbox)
i `SendSalesTeamNotification` (mock z deterministycznymi trybami awarii). Joby dziedziczą
po `SendChannelNotification`, który daje im:
- 4 próby z backoffem 60 / 300 / 900 s,
- timeout 30 s,
- rozróżnienie błędów przejściowych i trwałych,
- idempotencję w `notification_deliveries`,
- dead-letter w `failed_jobs` z logami `error`.

Nowych dostawców i kanały dodaje się nową klasą i wpisem w `config/webhooks.php` lub
`config/notifications.php`. Treść powiadomień jest po polsku, z czasem w strefie
Europe/Warsaw. Szczegóły decyzji: [research.md](research.md).

## Technical Context

**Language/Version**: PHP 8.5 (host 8.5.x; obraz `php:8.5-cli` z `pdo_pgsql`, `redis`, `intl`).

**Primary Dependencies**: Laravel 13.42, Pest 5.3, Laravel Pint 1.32. **Bez nowych zależności**
Composera: bez Sanctum (R1) i bez Horizon.

**Storage**:
- PostgreSQL 18: nowe tabele `webhook_events` i `notification_deliveries`, dead-letter
  w `failed_jobs` (fundament);
- Redis 8: kolejka, cache, licznik mocka SalesTeam.

**Testing**: Pest. Testy szybkie działają na sqlite `:memory:` z kolejką `sync`, cache `array`
i mailem `array`. Grupa `integration` (PostgreSQL + Redis w kontenerze) sprawdza równoczesne
duplikaty i dead-letter (R17).

**Target Platform**: kontenery Linux przez Docker Compose (usługi `app`, `worker`, `pgsql`,
`redis`, `init` z fundamentu). Mailtrap Sandbox jest usługą zewnętrzną.

**Project Type**: web service (API bez frontendu).

**Performance Goals**:
- odpowiedź na webhook < 1 s dla p95 (SC-003);
- powiadomienie na każdym kanale < 1 min przy działających kanałach (SC-004);
- szczytowo do 1 webhooka/s (SC-011).

**Constraints**:
- backoff kanałów 60 / 300 / 900 s, 4 próby;
- timeout joba 30 s < `retry_after` 90 s;
- SMTP timeout 5 s;
- okno podpisu ±300 s;
- ciało ≤ 1 MiB;
- dead-letter najpóźniej ~23 min po pierwszej próbie (SC-007 ≤ 25 min);
- brak nowego katalogu bazowego w repozytorium.

**Scale/Scope**:
- 1 dostawca (`generic`), 2 kanały (`mailtrap`, `salesteam`);
- 1 worker, kolejka `default`;
- skala demonstracyjna.

**Zależność**: wymaga ukończenia `001-app-foundation`. W chwili planowania brakuje w kodzie
`RejectMalformedPayload`, `UsesDefaultRetryPolicy`, listenerów `JobExceptionOccurred`/`JobFailed`
i trasy `/health`. Do `RejectMalformedPayload` trzeba dodać wyjątek składni JSON dla
`api/v1/webhooks/*` (R2).

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Zasada / sekcja | Wymóg | Jak plan go spełnia | Wynik |
|-----------------|-------|---------------------|-------|
| I. Queue-First | Powiadomienia tylko przez kolejkę; endpoint robi wyłącznie weryfikację, walidację, zapis i dispatch; 2xx po zapisie i dispatchu; listenery z I/O jako `ShouldQueue`; Redis | Żądanie: podpis, JSON, walidacja, `createOrFirst` i dispatch eventu w jednej transakcji, potem 200 (R7). Listener `QueuePaymentNotifications implements ShouldQueue`. Mail wysyłany wyłącznie wewnątrz joba (R14). `sync` tylko w testach | ✅ |
| II. Odporność i backoff | Jawne `tries`, `timeout`, rosnący `backoff`; `timeout < retry_after`; idempotencja po stabilnym kluczu (zdarzenie + kanał); transient → wyjątek, permanent → `fail()` | `tries()=4`, `backoff()=[60,300,900]`, `$timeout=30 < 90` (R10). Idempotencja `(provider, event_id, channel)` w `notification_deliveries` (R12). `PermanentChannelException` → `fail()`, reszta → rzucona dalej (R11). Listener: domyślna polityka fundamentu (R8) | ✅ |
| III. Dead-letter | Pełny payload, klasa, próby, wyjątek, czas; trwały i przeglądalny; ręczne ponowienie bez łamania idempotencji; `failed()` w każdym jobie | `failed_jobs` w PostgreSQL (F-R5). `failed()` w `SendChannelNotification` i w listenerze. `queue:retry` zaczyna od sprawdzenia `delivered` (R12) | ✅ |
| IV. Żadnych ukrytych błędów | Log `error` z payloadem, kanałem, próbą, korelacją, wyjątkiem; odrzucone webhooki 4xx z logiem; logi strukturalne; maskowanie sekretów | Tabela komunikatów w R13. Każde odrzucenie (401/400/422/500) to `webhook.rejected` na poziomie `error`. `X-Webhook-Signature` jest maskowany (wzorzec `signature`). Brak pustych `catch`: job loguje i rzuca dalej albo woła `fail()`. Nieznany kanał w konfiguracji → `error` + wyjątek | ✅ |
| V. Izolowane kanały | Wspólny kontrakt; osobny job na kanał; nowy kanał bez zmian w logice webhooków; mock SalesTeam z błędami przejściowymi; Mailtrap jedynym transportem | `NotificationChannel` + `SendChannelNotification` + joby per kanał (R9). Rejestracja w `config/notifications.php`. Mock z trybami `success`/`transient`/`permanent`/`fail_first_n` (R15). `MAIL_MAILER=smtp` → Mailtrap Sandbox | ✅ |
| Stos technologiczny | Laravel 13 / PHP 8.5, Redis, Mailtrap, Pest, Pint, brak nowych zależności, uwierzytelnianie webhooków przed przetwarzaniem | Zgodne. Webhook uwierzytelniany przed parsowaniem JSON i walidacją. Jedyna kontrola przed podpisem to limit rozmiaru 1 MiB (413), który chroni samą weryfikację HMAC i niczego nie przetwarza (R2) | ✅ |
| Bramki jakości | Testy jobów: sukces, retry, dead-letter, `failed()` z payloadem. Testy endpointu: poprawny podpis → dispatch (`Queue::fake()`), błędny podpis lub payload → 4xx + log, duplikat → brak drugiego powiadomienia. Pint | Pokryte w R17 i w strukturze testów poniżej | ✅ |

**Wynik wstępny**: PASS, brak naruszeń.

**Ponowna ocena po Fazie 1**: PASS. Projekt danych (2 tabele, bez klucza obcego między nimi,
R7/R12) i kontrakty (`webhook-http`, `webhook-signature`, `notification-channels`,
`notification-content`) nie wprowadzają odstępstw. Trzy świadome decyzje warte zauważenia:

1. **Odejście od domyślnej polityki ponowień fundamentu** (`[10,30,90,270]`, 5 prób) na rzecz
   60 / 300 / 900 s i 4 prób. To wymóg użytkownika. Konstytucja podaje wartości tylko jako
   przykład, a niezmienniki (≥ 4 próby, rosnący odstęp, `timeout < retry_after`) są
   zachowane.
2. **Zmiana w kodzie fundamentu**: `RejectMalformedPayload` pomija składnię JSON dla
   `api/v1/webhooks/*`, żeby spełnić regułę 401 przed 400. Limit 413 zostaje globalny.
3. **Podwójny log `error` przy awarii kanału**: domenowy z joba i siatka bezpieczeństwa
   F-R11. To świadoma redundancja, zgodna z Zasadą IV (R13).

## Project Structure

### Documentation (this feature)

```text
specs/002-payment-webhook-notifications/
├── plan.md                          # ten plik
├── research.md                      # Faza 0 (R1–R17)
├── data-model.md                    # Faza 1
├── quickstart.md                    # Faza 1
├── contracts/
│   ├── webhook-http.md              # POST /api/v1/webhooks/payment, X-Payment-Provider, kody i treści odpowiedzi
│   ├── webhook-signature.md         # X-Webhook-Signature, HMAC, okno, rotacja, wektor testowy
│   ├── notification-channels.md     # kontrakty kanału i dostawcy, rejestracja w konfiguracji
│   └── notification-content.md      # polska treść powiadomienia, pola, formaty
├── checklists/
│   └── requirements.md
└── tasks.md                         # Faza 2 (/speckit-tasks — jeszcze nie utworzony)
```

### Source Code (repository root)

```text
.env.example                                   # ZMIANA – WEBHOOK_*, NOTIFICATIONS_*, SALESTEAM_MOCK_*
bootstrap/app.php                              # ZMIANA – withRouting(api: routes/api.php)
routes/api.php                                 # NOWY  – POST /v1/webhooks/payment (webhooks.payment)

config/
├── webhooks.php                               # NOWY  – providers.generic (driver, secret, previous_secret, tolerance)
└── notifications.php                          # NOWY  – timezone, notify_test_events, event_labels, channels.*

database/
├── migrations/
│   ├── xxxx_create_webhook_events_table.php           # NOWY – UNIQUE(provider, event_id)
│   └── xxxx_create_notification_deliveries_table.php  # NOWY – UNIQUE(provider, event_id, channel)
└── factories/
    ├── WebhookEventFactory.php                # NOWY
    └── NotificationDeliveryFactory.php        # NOWY

app/
├── Enums/
│   ├── DeliveryStatus.php                     # pending | delivered | failed
│   └── WebhookReceipt.php                     # Accepted | Duplicate
├── Events/
│   └── PaymentEventReceived.php
├── Listeners/
│   └── QueuePaymentNotifications.php          # ShouldQueue, livemode, rozesłanie na kanały
├── Http/
│   ├── Controllers/Webhooks/
│   │   └── ReceiveWebhookController.php       # invokable: JSON → walidacja → ReceivePaymentWebhook → 200
│   └── Middleware/
│       ├── VerifyWebhookSignature.php         # NOWY  – X-Payment-Provider (401) / konfiguracja (500) / podpis (401)
│       └── RejectMalformedPayload.php         # ZMIANA (z 001) – wyjątek składni JSON dla api/v1/webhooks/*
├── Jobs/Notifications/
│   ├── SendChannelNotification.php            # abstrakcyjny: tries/backoff/timeout, idempotencja, failed()
│   ├── SendMailtrapNotification.php
│   └── SendSalesTeamNotification.php
├── Mail/
│   └── PaymentEventMail.php                   # Mailable (bez ShouldQueue)
├── Models/
│   ├── WebhookEvent.php
│   └── NotificationDelivery.php
└── Services/
    ├── Payments/
    │   ├── Data/
    │   │   ├── PaymentEvent.php               # readonly DTO (model ujednolicony)
    │   │   ├── Money.php
    │   │   ├── CustomerDetails.php
    │   │   ├── PaymentMethodDetails.php
    │   │   └── PaymentNotification.php
    │   └── PaymentNotificationFormatter.php   # PL, Europe/Warsaw, 299,90 PLN
    ├── Webhooks/
    │   ├── Contracts/PaymentProvider.php
    │   ├── PaymentProviderRegistry.php
    │   ├── ReceivePaymentWebhook.php          # transakcja: createOrFirst + dispatch eventu
    │   ├── Providers/GenericGatewayProvider.php
    │   ├── Signatures/
    │   │   ├── HmacSignatureVerifier.php
    │   │   └── SignatureVerification.php      # VO: verifiedWith current|previous
    │   └── Exceptions/
    │       ├── UnknownPaymentProvider.php     # render 401 unknown_provider
    │       ├── InvalidWebhookSignature.php    # render 401 invalid_signature
    │       └── PaymentProviderMisconfigured.php
    └── Notifications/
        ├── NotificationChannelRegistry.php
        └── Channels/
            ├── Contracts/NotificationChannel.php
            ├── MailtrapChannel.php
            ├── SalesTeamChannel.php           # mock: success|transient|permanent|fail_first_n
            └── Exceptions/
                ├── TransientChannelException.php
                └── PermanentChannelException.php

resources/views/mail/
├── payment-event.blade.php                    # HTML
└── payment-event-text.blade.php               # tekst

tests/
├── Support/
│   └── SignsWebhooks.php                      # trait: signedWebhook(payload, timestamp?, secret?)
├── Unit/
│   ├── Webhooks/HmacSignatureVerifierTest.php           # wektor testowy, okno, rotacja, format nagłówka
│   ├── Webhooks/GenericGatewayProviderTest.php          # mapowanie na PaymentEvent
│   └── Payments/PaymentNotificationFormatterTest.php    # 299,90 PLN, 10.06.2024 16:20 CEST, [TEST], „—”
└── Feature/
    ├── Webhooks/
    │   ├── ReceiveWebhookTest.php             # 200 accepted, zapis, dispatch eventu, 400/422/500
    │   ├── WebhookAuthenticationTest.php      # 401 (brak/nieznany X-Payment-Provider, brak/zły/przeterminowany podpis), 401 przed 400/422, rotacja
    │   ├── WebhookDeduplicationTest.php       # duplikat 200, rozbieżna treść (warning), różni dostawcy, odrzucone nie rejestrują klucza
    │   └── ProviderExtensibilityTest.php      # testowy dostawca tylko przez config()
    ├── Notifications/
    │   ├── QueuePaymentNotificationsTest.php  # rozesłanie, wyłączony kanał, livemode, brak kanałów, failed()
    │   ├── SendChannelNotificationTest.php    # sukces, transient (rethrow+log), permanent (fail), backoff/tries/timeout, failed() log, idempotencja
    │   ├── MailtrapChannelTest.php            # Mail::fake, treść PL, mapowanie błędów SMTP
    │   ├── SalesTeamChannelTest.php           # 4 tryby mocka, fail_first_n
    │   └── ChannelExtensibilityTest.php       # testowy kanał tylko przez config()
    └── Integration/                           # ->group('integration')
        ├── ConcurrentWebhookDeliveryTest.php  # 10 procesów → 1 wiersz, 1 dispatch (SC-005)
        └── NotificationDeadLetterTest.php     # failed_jobs z correlation_id po wyczerpaniu prób
```

**Structure Decision**: jeden projekt Laravel, zgodny z konwencją fundamentu. Logika
domenowa leży w `app/Services/{Webhooks,Notifications,Payments}`, tak jak
`app/Services/Health` w 001. Standardowe klasy frameworka (Events, Listeners, Jobs, Mail,
Models, Enums, Http) są w swoich domyślnych katalogach. Nowe pliki tworzymy przez
`php artisan make:*`:
- `make:model -mf`, `make:event`, `make:listener --queued`, `make:job`, `make:mail`;
- `make:controller --invokable`, `make:middleware`;
- `make:class`, `make:enum`, `make:interface`, `make:exception`;
- `make:test --pest` (z `--unit` dla testów jednostkowych).

Brak nowych katalogów bazowych. `routes/api.php` jest standardowym plikiem tras Laravela.

## Complexity Tracking

Brak naruszeń konstytucji, więc sekcja nie wymaga wpisów.
