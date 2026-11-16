<!--
Sync Impact Report
==================
Version change: (szablon, brak wersji) → 1.0.0
Bump rationale: pierwsza ratyfikacja konstytucji — wszystkie placeholdery szablonu zastąpione treścią.

Modified principles (placeholder → nowa nazwa):
- [PRINCIPLE_1_NAME] → I. Asynchroniczność przez kolejkę (Queue-First)
- [PRINCIPLE_2_NAME] → II. Odporność na awarie i rosnący backoff
- [PRINCIPLE_3_NAME] → III. Dead-letter dla wyczerpanych prób
- [PRINCIPLE_4_NAME] → IV. Żadnych ukrytych błędów — logowanie z payloadem (NIE PODLEGA NEGOCJACJI)
- [PRINCIPLE_5_NAME] → V. Izolowane kanały powiadomień działu sprzedaży

Added sections:
- Stos technologiczny i ograniczenia (z [SECTION_2_NAME])
- Proces wytwórczy i bramki jakości (z [SECTION_3_NAME])
- Governance (wypełnione)

Removed sections: brak

Templates requiring updates: brak zmian — szablony czytają konstytucję w czasie wykonania
(Scope Guard komendy /speckit-constitution).

Follow-up TODOs:
- .env.example ma obecnie QUEUE_CONNECTION=database, CACHE_STORE=database, MAIL_MAILER=log —
  niezgodne z Zasadą I i sekcją „Stos technologiczny”. Do poprawy w ramach pierwszej funkcjonalności.
-->

# Payment Gateway Constitution

System obsługi bramek płatniczych: przyjmuje webhooki od operatorów płatności, przetwarza status
transakcji i rozsyła go na kanały powiadomień działu sprzedaży (Mailtrap, SalesTeam).

## Core Principles

### I. Asynchroniczność przez kolejkę (Queue-First)

- Każde powiadomienie wysyłane przez aplikację MUSI przechodzić przez kolejkę. Wysyłka synchroniczna
  w cyklu żądania HTTP jest zabroniona.
- Endpoint webhooka MUSI ograniczyć się do: weryfikacji podpisu/autentyczności, walidacji, trwałego
  zapisu surowego zdarzenia i dispatchu joba. Odpowiedź 2xx MUSI zostać zwrócona dopiero po
  udanym zapisie i dispatchu, bez czekania na przetworzenie.
- Listenery eventów domenowych, które wykonują I/O (mail, HTTP, kanały zewnętrzne), MUSZĄ
  implementować `ShouldQueue`.
- Kolejką i cache MUSI być Redis. Driver `sync` jest dozwolony wyłącznie w testach.
- Workery MUSZĄ działać jako osobne, długo żyjące procesy obsługujące całą aplikację; żaden
  fragment systemu nie może zakładać, że job wykona się w tym samym procesie co jego dispatch.

**Uzasadnienie:** operatorzy płatności oczekują szybkiego potwierdzenia webhooka i ponawiają go przy
timeoutach. Asynchroniczność oddziela przyjęcie zdarzenia od kosztownej i zawodnej wysyłki.

### II. Odporność na awarie i rosnący backoff

- Każdy job MUSI jawnie deklarować `$tries` (lub `retryUntil()`), `$timeout` oraz rosnący
  `backoff()` (np. `[10, 30, 90, 270]` sekund). Stały lub zerowy backoff jest zabroniony.
- Joby MUSZĄ być idempotentne: ponowne wykonanie tego samego joba lub ponowne doręczenie tego
  samego webhooka nie może zmienić stanu ani wysłać duplikatu powiadomienia. Deduplikacja MUSI
  opierać się na stabilnym kluczu (np. identyfikator zdarzenia bramki + kanał).
- `$timeout` joba MUSI być krótszy niż `retry_after` połączenia kolejki.
- Błędy przejściowe (timeout, 5xx, brak połączenia) MUSZĄ kończyć się rzuceniem wyjątku, aby
  zadziałał retry. Błędy trwałe (nieprawidłowy payload, nieznana bramka) MUSZĄ kończyć job przez
  `fail()`, bez marnowania kolejnych prób.

**Uzasadnienie:** kanały zewnętrzne bywają chwilowo niedostępne. Rosnący backoff daje im czas na
powrót i nie zalewa ich ponowieniami, a idempotencja chroni dział sprzedaży przed duplikatami.

### III. Dead-letter dla wyczerpanych prób

- Job, który wyczerpał limit prób lub został oznaczony jako trwale nieudany, MUSI trafić na
  dead-letter z pełnym payloadem, klasą joba, liczbą prób, ostatnim wyjątkiem i czasem porażki.
- Dead-letter MUSI być trwały (przeżywa restart workerów i Redisa) i przeglądalny.
- Każdy wpis na dead-letter MUSI dać się ponowić ręcznie (np. `php artisan queue:retry`) bez
  utraty danych i bez łamania idempotencji.
- Każdy job MUSI implementować metodę `failed(Throwable $exception)`, która loguje porażkę zgodnie
  z Zasadą IV.

**Uzasadnienie:** utracone powiadomienie o płatności to utracona informacja biznesowa. Dead-letter
gwarantuje, że żadna porażka nie znika i każdą da się przeanalizować oraz naprawić.

### IV. Żadnych ukrytych błędów — logowanie z payloadem (NIE PODLEGA NEGOCJACJI)

- Każdy błąd przetwarzania webhooka, eventu i joba MUSI zostać zalogowany na poziomie co najmniej
  `error` wraz z: payloadem, nazwą bramki/kanału, klasą joba lub listenera, numerem próby,
  identyfikatorem korelacji oraz klasą, komunikatem i stack trace wyjątku.
- Puste bloki `catch`, połykanie wyjątków, `report()` bez ponownego rzucenia w miejscu, gdzie
  błąd ma przerwać przetwarzanie, oraz zwracanie sukcesu mimo błędu są zabronione.
- Odrzucony webhook (zły podpis, błąd walidacji) MUSI zostać zalogowany z payloadem i zwrócić
  odpowiedni kod 4xx; nie wolno odpowiadać 2xx na zdarzenie, którego system nie przyjął.
- Logi MUSZĄ być ustrukturyzowane (kontekst jako tablica, nie sklejony string), aby dało się je
  filtrować po identyfikatorze korelacji.
- Identyfikator korelacji MUSI być nadawany przy przyjęciu webhooka i propagowany do wszystkich
  eventów, jobów i wpisów dead-letter z nim związanych.
- Sekrety i dane wrażliwe (klucze API, podpisy, pełne numery kart, dane uwierzytelniające) MUSZĄ
  być maskowane w logach; reszta payloadu MUSI być logowana w całości.

**Uzasadnienie:** w systemie płatniczym ukryty błąd oznacza niewidoczną rozbieżność między stanem
transakcji a wiedzą działu sprzedaży. Pełny kontekst w logu skraca analizę z godzin do minut.

### V. Izolowane kanały powiadomień działu sprzedaży

- Każdy kanał (Mailtrap, SalesTeam i przyszłe) MUSI implementować wspólny kontrakt (interfejs)
  i MUSI być obsługiwany przez osobny job. Awaria jednego kanału nie może blokować ani opóźniać
  doręczenia na pozostałe.
- Dodanie nowego kanału NIE MOŻE wymagać zmian w logice przetwarzania webhooków ani statusów —
  wyłącznie nowej implementacji kontraktu i rejestracji.
- Kanał SalesTeam jest mockiem na potrzeby projektu. MUSI zachowywać się jak prawdziwa integracja:
  może zwracać sukces lub błąd (w tym błędy przejściowe), dzięki czemu retry, backoff i dead-letter
  da się zademonstrować i przetestować.
- Mailtrap jest jedynym dozwolonym transportem maili poza testami; wysyłka na prawdziwe skrzynki
  jest zabroniona.

**Uzasadnienie:** dział sprzedaży korzysta z wielu kanałów o różnej niezawodności. Izolacja
sprawia, że wolny lub niedziałający kanał nie odcina pozostałych od informacji o płatnościach.

## Stos technologiczny i ograniczenia

- **Framework:** Laravel 13 na PHP 8.5; API frameworka MUSZĄ odpowiadać zainstalowanym wersjom.
- **Kolejka i cache:** Redis (`QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`).
- **Dead-letter:** trwały magazyn nieudanych jobów (domyślnie mechanizm `failed_jobs` Laravela);
  wybór konkretnego magazynu MUSI zostać uzasadniony w planie funkcjonalności.
- **Mail:** Mailtrap (SMTP sandbox) jako kanał powiadomień.
- **SalesTeam:** mock kanału w kodzie aplikacji, za tym samym kontraktem co kanały prawdziwe.
- **Testy:** Pest; **formatowanie:** Laravel Pint.
- Nowe zależności (np. Horizon) MUSZĄ zostać zatwierdzone przed dodaniem.
- Webhooki MUSZĄ być uwierzytelniane (podpis HMAC lub równoważny mechanizm bramki) przed
  jakimkolwiek przetwarzaniem.

## Proces wytwórczy i bramki jakości

- Każdy plan (`/speckit-plan`) MUSI zawierać sekcję Constitution Check potwierdzającą zgodność
  z Zasadami I–V; każde odstępstwo MUSI zostać uzasadnione w tabeli Complexity Tracking.
- Każdy job i listener MUSI mieć testy Pest pokrywające: ścieżkę sukcesu, retry po błędzie
  przejściowym, przejście na dead-letter po wyczerpaniu prób oraz wywołanie `failed()` z logiem
  zawierającym payload.
- Endpoint webhooka MUSI mieć testy feature: poprawny podpis → dispatch joba (`Queue::fake()`),
  błędny podpis lub payload → 4xx i wpis w logu, duplikat zdarzenia → brak drugiego powiadomienia.
- Przed zakończeniem zmiany MUSZĄ przejść: `vendor/bin/pint --dirty --format agent` oraz
  najwęższy zestaw testów pokrywających zmianę (`php artisan test --compact`).
- Code review MUSI odrzucić kod, który połyka wyjątki, wysyła powiadomienia synchronicznie lub
  definiuje job bez `tries`/`backoff`/`failed()`.

## Governance

- Konstytucja ma pierwszeństwo przed innymi praktykami i konwencjami projektu. W razie konfliktu
  z `CLAUDE.md` lub szablonami Spec Kit obowiązuje konstytucja, a konflikt MUSI zostać zgłoszony
  i rozstrzygnięty poprawką.
- Poprawki wprowadza się komendą `/speckit-constitution`. Każda poprawka MUSI zawierać uzasadnienie,
  Sync Impact Report oraz — przy zmianach łamiących zgodność — plan migracji istniejącego kodu.
- Wersjonowanie semantyczne: MAJOR — usunięcie lub redefinicja zasady; MINOR — nowa zasada lub
  sekcja, istotne rozszerzenie wytycznych; PATCH — doprecyzowania, poprawki redakcyjne.
- Zgodność z konstytucją MUSI być weryfikowana w każdym planie (Constitution Check), w analizie
  (`/speckit-analyze`) i w code review.
- Bieżące wytyczne wykonawcze (konwencje Laravel, Pest, Pint, Boost) znajdują się w `CLAUDE.md`.

**Version**: 1.0.0 | **Ratified**: 2026-11-16 | **Last Amended**: 2026-11-16
