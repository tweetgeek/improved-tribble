# Research: Obsługa webhooków płatności i powiadomienia działu sprzedaży

Faza 0 planu. Każda decyzja ma format: **Decyzja**, **Uzasadnienie**, **Alternatywy**.
Decyzje fundamentu (`specs/001-app-foundation/research.md`, dalej „F-R*n*”) są przyjęte
bez zmian, chyba że niżej zapisano inaczej. API Laravela 13 sprawdzono w dokumentacji
(`search-docs`, laravel/framework 13.x) 2026-11-16.

Technical Context nie zawierał pozycji NEEDS CLARIFICATION: stos ustala konstytucja,
a decyzje produktowe zapadły w `/speckit-specify` i `/speckit-clarify`. Poniższe badania
dotyczą wzorców i punktów styku z fundamentem.

---

## R1. Adres przyjmowania i routing

**Decyzja**: jeden adres `POST /api/v1/webhooks/payment` dla wszystkich dostawców (decyzja
użytkownika z 2026-11-16). Dostawcę wskazuje nagłówek `X-Payment-Provider`, którego wartość
musi pasować do `^[a-z0-9-]{1,50}$`. Brak nagłówka, zły format lub nieznana wartość dają
401 `unknown_provider`. Trasa trafia do nowego pliku `routes/api.php`, zarejestrowanego
ręcznie w `bootstrap/app.php` przez `withRouting(api: __DIR__.'/../routes/api.php')`.
**Nie** używamy `php artisan install:api`. Nazwa trasy: `webhooks.payment`.

**Uzasadnienie**:
- Grupa `api` jest bezstanowa (tylko `SubstituteBindings`), bez sesji i CSRF. Właśnie tego
  potrzebuje wywołanie serwer–serwer.
- Prefiks `api/*` sprawia, że wyjątki są renderowane jako JSON, bo warunek
  `shouldRenderJsonWhen` już jest w `bootstrap/app.php`.
- Segment `v1` realizuje domyślną wersjonowaną konwencję API z `CLAUDE.md`.
- Jeden adres i nagłówek (FR-001) sprawiają, że nowy dostawca nie wymaga nowej trasy (FR-016).
- 401 zamiast 404 dla nieznanego dostawcy: adres istnieje, a bez dostawcy nie da się
  uwierzytelnić nadawcy. To zachowuje też regułę „401 przed 400/422”.

**Alternatywy**:
- *`install:api`*: odrzucone, instaluje Sanctum, czyli nową zależność wymagającą zgody.
- *Trasa w `routes/web.php` z wyjątkiem CSRF*: odrzucone, bo niosłaby niepotrzebną sesję
  i ciasteczka.
- *Adres na dostawcę `/webhooks/{provider}`*: odrzucone decyzją użytkownika (jeden adres).
- *Rozpoznanie po nagłówku podpisu* (`Stripe-Signature`, `OpenPayu-Signature`): odrzucone
  decyzją użytkownika na rzecz jawnego `X-Payment-Provider`. Ograniczenie: bramki, które nie
  pozwalają dodać własnego nagłówka, będą w przyszłości wymagały pośrednika albo zmiany decyzji.

## R2. Kolejność kontroli a globalny `RejectMalformedPayload` z fundamentu

**Problem**: F-R10 sprawdza składnię JSON w globalnym middleware, czyli jeszcze przed
routingiem. Webhook od nieuprawnionego nadawcy z uszkodzonym JSON dostałby więc 400,
a spec (Edge Cases, „Kolejność kontroli”) wymaga 401.

**Decyzja**:
1. `RejectMalformedPayload` zachowuje globalny limit rozmiaru (413). Dla ścieżek
   `api/v1/webhooks/*` **pomija sprawdzenie składni JSON**. Lista wyjątków jest właściwością
   middleware'u, wzorem `$except` w CSRF. Wzorzec z gwiazdką obejmuje także przyszłe
   endpointy webhooków.
2. Ścieżka webhooka ma ustaloną kolejność:
   1. middleware trasy `VerifyWebhookSignature`: rozpoznanie dostawcy z `X-Payment-Provider`
      (401 `unknown_provider`), a następnie weryfikacja podpisu na surowym ciele
      (401 `invalid_signature`);
   2. kontroler: składnia JSON (400, ten sam kod `malformed_payload` i to samo logowanie
      co F-R10);
   3. walidacja struktury (422 `validation_failed`).

**Uzasadnienie**: spełnia regułę 401 przed 400/422 bez naruszania F-R10 dla pozostałych
tras. Limit 1 MiB przed uwierzytelnieniem jest pożądany, bo nie obciążamy HMAC dużymi
ciałami, a odpowiedź 413 nie ujawnia reguł walidacji.

**Zależność od 001**: w chwili planowania `RejectMalformedPayload`, `UsesDefaultRetryPolicy`
i listenery `JobExceptionOccurred`/`JobFailed` (F-R10–F-R12) nie istnieją jeszcze w kodzie.
Fundament jest wdrożony tylko częściowo i niezacommitowany. Plan 002 wymaga ukończenia 001.
Wyjątek ścieżek z punktu 1 trzeba dodać w tym samym miejscu, w którym powstanie middleware.

**Alternatywy**:
- *Zmiana spec na 400 przed 401*: odrzucone, bo decyzja w spec jest jawna.
- *Przeniesienie weryfikacji podpisu do globalnego middleware'u*: odrzucone, bo przed
  routingiem nie znamy dostawcy.

## R3. Kontrakt dostawcy płatności i rejestr

**Decyzja**: interfejs `App\Services\Webhooks\Contracts\PaymentProvider`:
- `verify(Request): SignatureVerification` rzuca `InvalidWebhookSignature`;
- `rules(): array` zwraca reguły walidacji swojego formatu;
- `toPaymentEvent(string $provider, array $payload): PaymentEvent` mapuje treść (już po
  walidacji) na model ujednolicony.

`App\Services\Webhooks\PaymentProviderRegistry` czyta `config('webhooks.providers')` (klucz to
identyfikator z nagłówka `X-Payment-Provider`, wartość to klasa i opcje) i buduje dostawcę
przez kontener. Nieznany identyfikator rzuca `UnknownPaymentProvider` (401
`unknown_provider`). Pierwszy dostawca to identyfikator `generic`, klasa
`GenericGatewayProvider`, format z `payment_payload_example.json`.

Weryfikację HMAC wydzielamy do `HmacSignatureVerifier`, który może wykorzystać każdy
dostawca z podobnym schematem (np. przyszły Stripe ma ten sam format `t=…,v1=…`).

**Uzasadnienie**: FR-015 i FR-016. Nowy dostawca to nowa klasa i jeden wpis w konfiguracji.
Deduplikacja, zapis i powiadomienia znają tylko `PaymentEvent`.

**Alternatywy**:
- *Tagowane usługi w kontenerze*: odrzucone, bo rejestracja w kodzie providera jest mniej
  przejrzysta niż konfiguracja.
- *Odkrywanie klas po katalogu*: odrzucone jako niejawne i trudne do wyłączenia.

## R4. Podpis HMAC, okno czasowe i rotacja sekretów (FR-002a, FR-002b)

**Decyzja**:
- Nagłówek `X-Webhook-Signature: t=<unix>,v1=<hex>`. Dopuszczamy do 2 wartości `v1`, żeby
  dostawca mógł podpisywać oboma sekretami w czasie rotacji.
- Podpisywany ciąg to `"{t}.{surowe ciało}"`, algorytm HMAC-SHA256, wynik w zapisie hex.
- Porównanie przez `hash_equals` (czas stały) z każdym aktywnym sekretem: najpierw bieżącym
  (`secret`), potem poprzednim (`previous_secret`, opcjonalnym).
- Tolerancja `|now − t| ≤ 300 s`, konfigurowalna. Poza oknem, przy braku nagłówka lub złym
  formacie odpowiadamy 401 `invalid_signature`; przyczyna trafia do logu, nie do odpowiedzi.
- Brak skonfigurowanego sekretu bieżącego to błąd konfiguracji serwera. Odpowiadamy 500
  i logujemy `error`, dzięki czemu dostawca ponowi doręczenie po naprawie.
- Log przyjęcia zawiera `verified_with: current|previous`. Nie używamy nazwy klucza
  `signature_key`, bo procesor F-R9 zamaskowałby ją wzorcem `signature`.

**Uzasadnienie**: model Stripe jest powszechnie znany i spełnia wymóg konstytucji (podpis HMAC).
Znacznik czasu objęty podpisem blokuje powtórzenie przechwyconego żądania. Dwa sekrety
pozwalają na rotację bez przestoju.

**Alternatywy**:
- *Base64 zamiast hex*: bez różnicy merytorycznej; hex jest łatwiejszy do debugowania z `openssl`.
- *Znacznik czasu z treści (`timestamp`)*: odrzucone. Zawsze sprawdzamy znacznik z nagłówka
  objęty podpisem, a pole z treści określa czas zdarzenia, nie czas doręczenia.

## R5. Walidacja i model ujednolicony

**Decyzja**:
- Walidacja przez `Validator::make($payload, $provider->rules())`. `ValidationException`
  przechodzi przez renderer i logowanie z fundamentu (422 `validation_failed` z `details`).
- Kwota: `integer:strict|min:1`, co odrzuca stringi, ułamki, zero i wartości ujemne.
- Waluta: `regex:/^[A-Z]{3}$/`.
- `livemode`: opcjonalne; jeśli występuje, `boolean:strict`. Brak oznacza `true`.
- `PaymentEvent` to `final readonly` DTO z obiektami `Money`, `CustomerDetails`
  i `PaymentMethodDetails`. Dokładne pola są w `data-model.md`.

**Uzasadnienie**: tryb `strict` jest udokumentowany w Laravel 13 dla `integer` i `numeric`.
DTO bez modeli Eloquent serializuje się do kolejki bez zapytań i bez zależności od wiersza
w bazie (patrz R7).

**Alternatywy**:
- *Form Request*: odrzucone, bo reguły zależą od dostawcy rozpoznanego w middleware, a JSON
  parsujemy po weryfikacji podpisu. Form Request wymusiłby walidację przed tą kolejnością.
- *Pełna lista ISO 4217*: odrzucone jako niepotrzebne utrzymanie listy; wystarczy regex.

## R6. Deduplikacja i równoczesne doręczenia (FR-010–FR-014)

**Decyzja**:
- Tabela `webhook_events` z indeksem unikalnym `(provider, event_id)`.
- Zapis przez `WebhookEvent::createOrFirst()`. `wasRecentlyCreated` rozróżnia nowe zdarzenie
  od duplikatu. W transakcji Laravel otacza próbę savepointem, więc naruszenie unikalności
  nie psuje transakcji w PostgreSQL.
- `payload_sha256` (skrót surowego ciała) pozwala wykryć duplikat o innej treści.
  Odpowiadamy wtedy jak na duplikat i logujemy `warning`.

**Uzasadnienie**: ograniczenie unikalności w bazie jest atomowe między procesami, trwałe
i zapisuje się w tej samej transakcji co zdarzenie. `Cache::lock` z F-R13 jest tu niepotrzebny:
dawałby drugi, mniej trwały mechanizm do tej samej gwarancji.

**Alternatywy**:
- *`Cache::lock` + odczyt*: odrzucone, bo blokada wygasa, a deduplikacja ma działać
  bezterminowo.
- *`insertOrIgnore`*: także poprawne, ale pomija model i znaczniki czasu; `createOrFirst`
  jest czytelniejsze.

## R7. Atomowość zapisu i publikacji (FR-008, FR-009)

**Decyzja**: wewnątrz `DB::transaction()`:
1. `createOrFirst` zdarzenia;
2. jeśli zdarzenie jest nowe, `PaymentEventReceived::dispatch($paymentEvent)`. Listener jest
   w kolejce, więc w tym momencie zadanie trafia do Redisa.

Odpowiedź 200 wysyłamy po zatwierdzeniu transakcji. Event **nie** implementuje
`ShouldDispatchAfterCommit`, a listener **nie** implementuje `ShouldQueueAfterCommit`.
Połączenie kolejki zostaje z `after_commit=false` (fundament).

| Awaria | Skutek | Zgodność |
|--------|--------|----------|
| Zapis do bazy się nie udaje | wyjątek → 500, nic nie trafia do kolejki | FR-009 ✅ |
| Wypchnięcie do Redisa się nie udaje | wyjątek → rollback → 500, klucz nie jest zarejestrowany, ponowienie przyjęte jako nowe | FR-009, FR-013 ✅ |
| Commit się nie udaje po wypchnięciu | 500; zadanie już jest w kolejce i doręczy powiadomienie; ponowienie dostawcy zapisze zdarzenie i wypchnie zadanie ponownie, a idempotencja doręczeń (R12) zablokuje duplikat | FR-030 ✅ |

Listener i joby **nie czytają** `webhook_events`, bo dostają pełne `PaymentEvent`.
Dlatego wykonanie przed commitem nie jest problemem.

**Uzasadnienie**: dokumentacja Laravel 13 („Queued Event Listeners and Database
Transactions”) potwierdza, że listener wypchnięty w transakcji może wykonać się przed
commitem. Projekt jest na to odporny z założenia, zamiast tracić gwarancję
z FR-009 przez `after_commit`.

**Alternatywy**:
- *`ShouldQueueAfterCommit`*: odrzucone. Awaria wypchnięcia po commicie zostawiłaby
  zarejestrowany klucz bez zleconych powiadomień, a każde ponowienie dostawcy byłoby
  uznane za duplikat.
- *Outbox (tabela i przekaźnik)*: najpełniejsza gwarancja, ale wymaga dodatkowego procesu.
  Przy skali do 1 webhooka/s to nadmiar (YAGNI).

## R8. Listener powiadomień

**Decyzja**: `App\Listeners\QueuePaymentNotifications implements ShouldQueue`. Używa traitu
`UsesDefaultRetryPolicy` (F-R12) i ma metodę `failed()` z logiem `error`. Listener jest
wykrywany automatycznie po typie argumentu `handle(PaymentEventReceived $event)`. Działanie:
1. Jeśli `livemode=false` i `config('notifications.notify_test_events')=false`, loguje
   `info` „pominięto zdarzenie testowe” i kończy pracę (FR-019a).
2. Pobiera włączone kanały z `NotificationChannelRegistry`. Gdy lista jest pusta, loguje
   `warning`.
3. Dla każdego kanału dispatchuje job kanału z `PaymentEvent`.

Ponowienie listenera po częściowym rozesłaniu jest bezpieczne dzięki R12.

**Uzasadnienie**: wymóg użytkownika (listener uruchamia joby kanałów) oraz Zasada I. Żądanie
HTTP wypycha tylko jedno zadanie, niezależnie od liczby kanałów.

**Alternatywy**: *listener synchroniczny w żądaniu*. Byłby poprawny (wypchnięcia w transakcji),
ale czas odpowiedzi rósłby z każdym kanałem.

## R9. Kontrakt kanału i job na kanał (FR-018, FR-023, Zasada V)

**Decyzja**:
- Interfejs `App\Services\Notifications\Channels\Contracts\NotificationChannel`:
  `name(): string` oraz `send(PaymentNotification $notification): void`. Metoda `send` rzuca
  `TransientChannelException` albo `PermanentChannelException`.
- Implementacje: `MailtrapChannel` i `SalesTeamChannel` (mock).
- Abstrakcyjny job `App\Jobs\Notifications\SendChannelNotification` zawiera politykę ponowień
  (R10), idempotencję (R12), klasyfikację błędów (R11), logowanie i `failed()`. Abstrakcyjna
  metoda `channel(): NotificationChannel`.
- Joby konkretne: `SendMailtrapNotification` i `SendSalesTeamNotification`. Każdy z nich
  wskazuje tylko swój kanał.
- Rejestr w `config('notifications.channels')`: klucz kanału → `enabled`, klasa `job`
  i ustawienia kanału.

Dodanie kanału oznacza nową klasę kanału, nowy job dziedziczący po bazowym i jeden wpis
w konfiguracji. Istniejący kod się nie zmienia.

**Uzasadnienie**: użytkownik wprost wymaga „Job do wysyłki na kanał Mailtrap” i „Job do
wysyłki na kanał SalesTeam”. Konstytucja (V) wymaga wspólnego kontraktu i osobnego joba na
kanał. Klasa bazowa trzyma wszystkie gwarancje w jednym miejscu, więc nowy kanał dziedziczy
je automatycznie (US5, scenariusz 1).

**Alternatywy**:
- *Jeden generyczny job z parametrem kanału*: mniej plików, ale nie spełnia literalnie
  wymagania o jobie na kanał i utrudnia strojenie per kanał.
- *Laravel Notifications (`Notification` + `via()`)*: odrzucone. Dodanie kanału wymagałoby
  edycji `via()`, a jeden job obsługiwałby wszystkie kanały, co łamie izolację.

## R10. Polityka ponowień jobów kanałów (FR-026, FR-031)

**Decyzja**: w `SendChannelNotification`:
- `tries(): int` zwraca 4;
- `backoff(): array` zwraca `[60, 300, 900]`;
- `public $timeout = 30`.

Używamy metod zamiast atrybutów `#[Tries]`/`#[Backoff]` z Laravela 13, bo atrybuty PHP
na klasie abstrakcyjnej nie muszą być widoczne w klasach potomnych. Metody są dziedziczone
i udokumentowane. Właściwość `$timeout` sprawdzimy w implementacji
(`search-docs "job timeout"`); jeśli nie zadziała, każdy job konkretny dostanie
`#[Timeout(30)]`. Wartości job-level mają pierwszeństwo przed flagami workera
`--tries=5 --backoff=10,30,90,270` (dokumentacja Laravel 13, „Max Attempts”).

Limity czasu: SMTP 5 s (`MAIL_TIMEOUT`, fundament), timeout joba 30 s < `retry_after` 90 s.
Timeout zużywa próbę i zadanie wraca do kolejki, bo nie ustawiamy `FailOnTimeout`. Najgorszy
przypadek do dead-letter: 4 próby × 30 s + 60 + 300 + 900 s ≈ 23 min < 25 min (SC-007).

**Uzasadnienie**: wymóg użytkownika (1, 5, 15 min). Spełnia Zasadę II i FR-020 fundamentu:
co najmniej 4 próby i ściśle rosnący odstęp. Odchodzimy od domyślnej polityki fundamentu
(`[10, 30, 90, 270]`) świadomie, bo konstytucja podaje ją tylko jako przykład.

**Alternatywy**: *`retryUntil()` z oknem 25 min*. Odrzucone, bo user wprost określił liczbę
prób i ich odstępy.

## R11. Klasyfikacja błędów kanałów (FR-028)

**Decyzja**:
- Kanał tłumaczy błędy własnej integracji na `TransientChannelException` albo
  `PermanentChannelException`.
- Job: `PermanentChannelException` → `$this->fail($e)`, czyli od razu `failed_jobs`
  bez kolejnych prób. Każdy inny `Throwable` (także nieznany) jest logowany i rzucany dalej,
  więc działa ponowienie. Domyślnie zakładamy, że nieznany błąd jest przejściowy.
- Mapowanie dla Mailtrap (wyjątki transportu Symfony Mailer):

| Sytuacja | Klasa |
|----------|-------|
| brak `MAIL_USERNAME`/`MAIL_PASSWORD` lub brak adresatów w konfiguracji | permanent |
| odpowiedź SMTP 530/535 (uwierzytelnienie) | permanent |
| brak połączenia, timeout, 4xx SMTP, 550 „too many emails per second” (limit sandboxa), inne | transient |

**Uzasadnienie**: Zasada II rozdziela błędy przejściowe i trwałe. Domyślne „transient”
dla nieznanych błędów nie gubi powiadomień: w najgorszym razie kosztuje 3 dodatkowe próby
przed dead-letter. Metoda `fail($exception)` jest udokumentowana w Laravel 13.

**Alternatywy**: *middleware `FailOnException`*. Działałoby tak samo, ale jawne `fail()`
w klasie bazowej jest czytelniejsze przy logowaniu z kontekstem kanału.

## R12. Idempotencja doręczeń (FR-030)

**Decyzja**:
- Tabela `notification_deliveries` z indeksem unikalnym `(provider, event_id, channel)`.
  Celowo bez klucza obcego do `webhook_events` (R7). Statusy: `pending`, `delivered`,
  `failed`.
- Job:
  1. `createOrFirst` z `pending`;
  2. jeśli status to `delivered`, loguje `info` i kończy pracę;
  3. zapisuje `attempts` i wywołuje `send()`;
  4. po sukcesie ustawia `delivered` i `delivered_at`;
  5. przy błędzie zapisuje `last_error`;
  6. `failed()` ustawia `failed`.
- Ręczne ponowienie z `failed_jobs` zaczyna od kroku 1, więc nie wyśle powtórnie
  potwierdzonego doręczenia.

**Uzasadnienie**: spełnia zasadę „co najwyżej raz po potwierdzeniu doręczenia” (spec, Edge
Cases). Wąskie okno między udanym `send()` a zapisem `delivered` może skończyć się ponowną
wysyłką. To akceptowalne at-least-once dla tego okna: kanały nie mają transakcyjnego API.

**Alternatywy**:
- *`ShouldBeUnique` na jobie*: odrzucone, bo blokada unikalności jest zwalniana po
  wykonaniu, a nie trwała.
- *`WithoutOverlapping`*: niepotrzebne. Równoległe wykonanie tego samego doręczenia możliwe
  jest tylko przy ręcznym retry w trakcie automatycznego, co przy tej skali pomijamy.

## R13. Logowanie (FR-006, FR-027, Zasada IV)

**Decyzja**: komunikaty logów są stałymi kluczami. Kontekst ma postać tablicy. `correlation_id`
dochodzi automatycznie z `Context` (F-R6), także w workerze, bo Context jest dehydratowany
do payloadu zadania.

| Komunikat | Poziom | Kluczowe pola kontekstu |
|-----------|--------|-------------------------|
| `webhook.rejected` | error | `provider`, `reason` (`unknown_provider`, `invalid_signature`, `signature_expired`, `missing_signature`, `malformed_payload`, `validation_failed`), `headers`, `body` |
| `webhook.provider_misconfigured` | error | `provider`, `missing` |
| `webhook.accepted` | info | `provider`, `event_id`, `event_type`, `livemode`, `verified_with` |
| `webhook.duplicate` | info | `provider`, `event_id` |
| `webhook.duplicate_payload_mismatch` | warning | `provider`, `event_id`, `stored_sha256`, `received_sha256` |
| `notifications.test_event_skipped` | info | `provider`, `event_id` |
| `notifications.no_channels_enabled` | warning | `provider`, `event_id` |
| `notifications.queued` | info | `provider`, `event_id`, `channels` |
| `notifications.queueing_failed` | error | `provider`, `event_id`, `exception` (metoda `failed()` listenera) |
| `notifications.invalid_channel_config` | error | `channel`, `job` (wpis kanału z klasą spoza `SendChannelNotification`) |
| `notification.delivered` | info | `channel`, `provider`, `event_id`, `attempt` |
| `notification.already_delivered` | info | `channel`, `provider`, `event_id` |
| `notification.attempt_failed` | error | `channel`, `provider`, `event_id`, `attempt`, `max_attempts`, `transient`, `notification`, `exception` |
| `notification.dead_lettered` | error | jak wyżej oraz `failed_at` |
| `salesteam.mock.received` | info | `mode`, `notification` |

Globalne listenery `JobExceptionOccurred`/`JobFailed` z F-R11 zostają jako siatka
bezpieczeństwa. Przy awarii kanału powstaną więc dwa wpisy `error`: domenowy z kanałem oraz
generyczny z surowym payloadem. To świadoma redundancja.

Dane klienta (e-mail, imię) nie są maskowane: spec (Assumptions) i konstytucja maskują
sekrety, nie dane kontaktowe. Nagłówek `X-Webhook-Signature` jest maskowany przez wzorzec
`signature` z F-R9.

## R14. Treść i format powiadomień (FR-020, FR-020a)

**Decyzja**:
- `PaymentNotificationFormatter` buduje z `PaymentEvent` obiekt `PaymentNotification`,
  który jest wspólną treścią dla wszystkich kanałów: temat, listę par etykieta–wartość
  po polsku i flagę testową.
- Kwota: `value / 100`, formatowana przez `Illuminate\Support\Number::format(…, precision: 2,
  locale: 'pl')` z kodem waluty po kwocie, np. `299,90 PLN`. Rozszerzenie `intl` jest na
  hoście i w obrazie (`Dockerfile`).
- Czas: `CarbonImmutable::createFromTimestamp($ts)->setTimezone('Europe/Warsaw')`
  w formacie `d.m.Y H:i T`. Dla `1718029200` daje to `10.06.2024 16:20 CEST`.
- Strefa czasowa pochodzi z `config('notifications.timezone')`; `app.timezone` zostaje UTC.
- Mail: Mailable `PaymentEventMail` (widok Blade HTML i tekstowy), wysyłany **synchronicznie
  wewnątrz joba** (`Mail::to(...)->send(...)`). Mailable nie implementuje `ShouldQueue`, bo
  kolejką jest sam job.
- Temat: `[TEST] ` (tylko przy `livemode=false`), potem
  `Płatność: {typ} · zamówienie {order_id} · {kwota}`.
- Typ zdarzenia pokazujemy dosłownie, np. `charge.successful`, oraz z polską etykietą, jeśli
  jest w mapie konfiguracyjnej (`notifications.event_labels`, np. `charge.successful` →
  „Płatność zakończona sukcesem”).

**Założenie**: kwoty mają 2 miejsca po przecinku. Waluty o innym wykładniku (JPY, KWD)
są poza zakresem pierwszego dostawcy (PLN, EUR, USD).

**Alternatywy**: *`Number::currency(…, 'pl')`*. Odrzucone, bo daje `299,90 zł`, a spec
wymaga kodu waluty (`299,90 PLN`).

## R15. Mock SalesTeam (FR-022)

**Decyzja**: `SalesTeamChannel` odczytuje `config('notifications.channels.salesteam.mock')`:
- `mode`: `success` | `transient` | `permanent` | `fail_first_n`;
- `fail_first`: liczba N, domyślnie 2.

Każde wywołanie loguje `salesteam.mock.received` z pełną treścią. W trybie `fail_first_n`
licznik prób danego doręczenia jest w cache (`salesteam-mock:{provider}:{event_id}`, TTL 24 h,
`Cache::increment`). Próby 1…N rzucają `TransientChannelException`, kolejne kończą się sukcesem.

**Uzasadnienie**: zachowanie jest deterministyczne (decyzja z `/speckit-clarify`). Licznik
w Redisie przeżywa restart workera między próbami, które dzieli nawet 5 minut.

**Alternatywy**: *numer próby przekazywany do `send()`*. Odrzucone, bo kontrakt kanału
nie powinien znać mechaniki kolejki.

## R16. Konfiguracja

**Decyzja**: dwa nowe pliki konfiguracyjne. Pełny schemat jest w
`contracts/notification-channels.md`.
- `config/webhooks.php`: `providers.generic` zawiera `driver`, `secret`, `previous_secret`
  i `tolerance`.
- `config/notifications.php`: `timezone`, `notify_test_events`, `event_labels`
  i `channels.{mailtrap,salesteam}`.

Nowe zmienne w `.env.example`:

| Zmienna | Domyślnie |
|---------|-----------|
| `WEBHOOK_GENERIC_SECRET` | wartość deweloperska |
| `WEBHOOK_GENERIC_PREVIOUS_SECRET` | puste |
| `WEBHOOK_SIGNATURE_TOLERANCE` | `300` |
| `NOTIFICATIONS_NOTIFY_TEST_EVENTS` | `true`, gdy `APP_ENV ≠ production` |
| `NOTIFICATIONS_MAILTRAP_ENABLED` | `true` |
| `NOTIFICATIONS_SALES_TEAM_EMAILS` | `sales@example.test` |
| `NOTIFICATIONS_SALESTEAM_ENABLED` | `true` |
| `SALESTEAM_MOCK_MODE` | `success` |
| `SALESTEAM_MOCK_FAIL_FIRST` | `2` |

**Uwaga**: worker trzyma konfigurację w pamięci, więc po zmianie `.env` trzeba wykonać
`php artisan queue:restart`. Usługa wznawia się dzięki `restart: unless-stopped`.

## R17. Strategia testów

**Decyzja**: zgodnie z F-R16 i bramkami jakości konstytucji. Szybkie testy działają na sqlite
`:memory:` z kolejką `sync`. Grupa `integration` korzysta z prawdziwego PostgreSQL i Redisa.

- **Feature (endpoint)**: kody 200/401/400/422/500 i ich treść (401 także dla brakującego lub nieznanego `X-Payment-Provider`), zapis zdarzenia,
  `Event::fake([PaymentEventReceived::class])`, odrzucenia i duplikaty w logu (`Log::spy()`),
  rotacja sekretów, okno czasowe (`$this->travelTo`), kolejność 401 przed 400/422.
- **Feature (listener)**: rozesłanie na włączone kanały (`Queue::fake()`), pominięcie
  zdarzeń testowych, brak kanałów.
- **Unit/Feature (joby)**: `withFakeQueueInteractions()`:
  - sukces → `assertNotFailed`;
  - błąd przejściowy → wyjątek rzucony dalej i log `notification.attempt_failed`;
  - błąd trwały → `assertFailedWith(PermanentChannelException::class)`;
  - `tries()`/`backoff()`/`$timeout` mają wartości 4 / [60, 300, 900] / 30, a timeout
    jest mniejszy niż `retry_after`;
  - `failed()` loguje `notification.dead_lettered` z treścią;
  - idempotencja: drugie wykonanie nie wysyła ponownie.
- **Kanały**: `Mail::fake()` + `assertSent(PaymentEventMail::class)`; renderowanie treści
  maila po polsku z kwotą `299,90 PLN` i czasem `10.06.2024 16:20 CEST`; tryby mocka SalesTeam.
- **Rozszerzalność (US5)**: testowy kanał i testowy dostawca rejestrowane tylko przez
  `config()` w teście. Asercja: przechodzą pełną ścieżkę.
- **Integration**:
  - 10 równoczesnych doręczeń tego samego zdarzenia przez `Concurrency::run()` daje
    dokładnie 1 wiersz i 1 dispatch (SC-005);
  - job z wyczerpanymi próbami trafia do `failed_jobs` z `correlation_id`.
- Pomocnik testowy `tests/Support/SignsWebhooks.php` (trait) z metodą
  `signedWebhook(array $payload, ?int $timestamp = null, ?string $secret = null)`.

**Uzasadnienie**: konstytucja wymaga testów sukcesu, retry, dead-letter i `failed()` dla
każdego joba oraz testów feature endpointu z `Queue::fake()`. Dokładne opóźnienia backoffu
(1, 5, 15 min) potwierdzają asercje wartości `backoff()` oraz scenariusz ręczny w
`quickstart.md`, a nie czekanie w teście.
