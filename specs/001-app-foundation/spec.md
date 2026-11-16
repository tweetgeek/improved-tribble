# Feature Specification: Fundament aplikacji payment-gateway

**Feature Branch**: `001-app-foundation`

**Created**: 2026-11-16

**Status**: Draft

**Input**: User description: "Przygotuj specyfikację dla fundamentu aplikacji. W tej wersji specyfikacji nie dodajemy jeszcze jobów, obsługi webhooków. Kontekst biznesowy: mikro-system dla platformy e-commerce, który odbiera webhooki o zdarzeniach płatności od dostawcy (np. Stripe/PayU), przetwarza je i wysyła asynchroniczne powiadomienie do zespołu sprzedaży. Wymagania architektury: race condition (dwa identyczne webhooki z tym samym event_id w tej samej milisekundzie), out-of-order delivery (charge.successful przed utrwaleniem intencji płatności), awarie zewnętrznego API i backoff (5xx/timeout, exponential backoff + dead letter), poison pills / malformed payloads z czytelnym logowaniem. Kryteria akceptacji: aplikacja uruchamia się bez danych; docker up uruchamia działającą aplikację z workerem i kolejkami; Mailtrap odpowiada poprawnie."

## Zakres tej specyfikacji

Ta specyfikacja opisuje **wyłącznie fundament**: środowisko uruchomieniowe, infrastrukturę
asynchroniczną, kanał pocztowy i mechanizmy przekrojowe (logowanie, magazyn porażek, blokady,
domyślna polityka ponowień), na których kolejne funkcjonalności zbudują obsługę webhooków.

**Poza zakresem** (kolejne specyfikacje):

- przyjmowanie i weryfikacja webhooków od dostawców płatności,
- biznesowe zadania asynchroniczne (przetwarzanie statusu, wysyłka powiadomień do sprzedaży),
- kanał SalesTeam (mock),
- model danych płatności (intencje, transakcje, zdarzenia).

Cztery wymagania architektoniczne z opisu (race condition, out-of-order delivery, awarie API
z backoffem, poison pills) **nie są tu rozwiązywane biznesowo**. Fundament MUSI natomiast
dostarczyć mechanizmy, bez których ich późniejsze rozwiązanie nie będzie możliwe — patrz
sekcja „Gotowość architektoniczna” w wymaganiach.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Uruchomienie całego środowiska jedną komendą (Priority: P1)

Deweloper klonuje repozytorium, uzupełnia dane dostępowe do sandboxa pocztowego i jedną komendą
uruchamia kompletne środowisko: aplikację, bazę danych, broker kolejki i cache oraz co najmniej
jeden worker przetwarzający kolejkę. Nie musi ręcznie przygotowywać danych, migrować schematu ani
uruchamiać dodatkowych procesów.

**Why this priority**: bez działającego środowiska żadna kolejna funkcjonalność (webhooki,
powiadomienia) nie może być rozwijana ani testowana. To bezpośrednie kryterium akceptacji.

**Independent Test**: na czystej maszynie z zainstalowanym środowiskiem kontenerowym wykonać
komendę uruchomienia i sprawdzić, że wszystkie komponenty działają, a strona startowa aplikacji
odpowiada.

**Acceptance Scenarios**:

1. **Given** świeży klon repozytorium i pusta baza danych, **When** deweloper uruchamia
   środowisko jedną komendą, **Then** aplikacja startuje, schemat bazy jest automatycznie
   przygotowany, a aplikacja odpowiada na żądania bez błędów mimo braku jakichkolwiek danych.
2. **Given** uruchomione środowisko, **When** deweloper sprawdza listę procesów środowiska,
   **Then** widzi działający proces aplikacji, bazę danych, broker kolejki/cache oraz co najmniej
   jeden worker kolejki.
3. **Given** uruchomione środowisko, **When** proces workera zostanie nieoczekiwanie zakończony,
   **Then** środowisko automatycznie go wznawia bez interwencji dewelopera.
4. **Given** uruchomione środowisko, **When** deweloper zatrzymuje je i uruchamia ponownie,
   **Then** środowisko startuje poprawnie, a wcześniej zapisane dane (w tym magazyn porażek)
   są zachowane.

---

### User Story 2 - Weryfikacja stanu systemu (Priority: P1)

Deweloper lub operator chce w kilka sekund potwierdzić, że wszystkie zależności działają:
aplikacja, baza danych, broker kolejki, cache, worker oraz sandbox pocztowy (Mailtrap).
Każdy niedziałający komponent jest jawnie wskazany wraz z przyczyną.

**Why this priority**: kryteria „worker i kolejki działają” oraz „Mailtrap odpowiada poprawnie”
muszą być weryfikowalne w powtarzalny sposób, a konstytucja zabrania ukrywania błędów.

**Independent Test**: w uruchomionym środowisku wywołać sprawdzenie stanu i otrzymać raport
z wynikiem dla każdego komponentu; następnie wyłączyć jeden komponent i sprawdzić, że raport
wskazuje go jako niedziałający.

**Acceptance Scenarios**:

1. **Given** wszystkie komponenty działają, **When** operator wywołuje sprawdzenie stanu,
   **Then** otrzymuje raport, w którym każdy komponent (aplikacja, baza danych, kolejka, cache,
   worker, sandbox pocztowy) ma status „działa”.
2. **Given** broker kolejki jest niedostępny, **When** operator wywołuje sprawdzenie stanu,
   **Then** raport oznacza kolejkę jako niedziałającą z czytelną przyczyną, a zdarzenie jest
   zapisane w logu na poziomie błędu.
3. **Given** dane dostępowe do sandboxa pocztowego są błędne lub puste, **When** operator
   wywołuje sprawdzenie stanu, **Then** raport oznacza kanał pocztowy jako niedziałający
   z przyczyną (np. odrzucone uwierzytelnienie), a aplikacja nadal działa.
4. **Given** poprawne dane dostępowe, **When** operator weryfikuje kanał pocztowy,
   **Then** system nawiązuje połączenie z sandboxem pocztowym i pomyślnie się uwierzytelnia,
   nie wysyłając żadnej wiadomości, a raport oznacza kanał pocztowy jako „działa”.

---

### User Story 3 - Przekrojowe mechanizmy odporności i obserwowalności (Priority: P2)

Deweloper kolejnej funkcjonalności (obsługi webhooków) dostaje gotowe, spójne mechanizmy:
ustrukturyzowane logowanie z identyfikatorem korelacji i maskowaniem sekretów, trwały magazyn
porażek (dead-letter) z możliwością ręcznego ponowienia, domyślną politykę ponowień z rosnącym
odstępem, współdzieloną między procesami blokadę oraz jednolitą obsługę błędnych żądań HTTP.
Nie musi ich projektować od nowa ani dobierać ad hoc.

**Why this priority**: te mechanizmy są warunkiem rozwiązania wymagań architektonicznych
(race condition, backoff + dead letter, poison pills) w następnych specyfikacjach, ale same
w sobie nie są widoczne dla zespołu sprzedaży.

**Independent Test**: każdy mechanizm da się zweryfikować w izolacji — np. wysłać do aplikacji
uszkodzone żądanie i sprawdzić odpowiedź oraz wpis w logu; zająć blokadę w jednym procesie
i potwierdzić, że drugi proces nie może jej zająć; sprawdzić, że magazyn porażek istnieje
i jest pusty po starcie.

**Acceptance Scenarios**:

1. **Given** uruchomiona aplikacja, **When** klient wysyła żądanie z uszkodzonym lub
   niekompletnym ciałem (np. nieprawidłowy JSON), **Then** aplikacja odpowiada kodem z grupy 4xx
   z czytelnym komunikatem, nie zwraca błędu serwera, a w logu pojawia się wpis z treścią
   żądania (z zamaskowanymi sekretami) i identyfikatorem korelacji.
2. **Given** dowolne żądanie HTTP, **When** aplikacja je obsługuje, **Then** odpowiedź zawiera
   identyfikator korelacji, a wszystkie wpisy w logu związane z tym żądaniem zawierają ten sam
   identyfikator.
3. **Given** żądanie zawiera identyfikator korelacji nadany przez klienta, **When** aplikacja
   je obsługuje, **Then** używa tego identyfikatora zamiast generować nowy.
4. **Given** dwa procesy (np. aplikacja i worker) próbują jednocześnie zająć tę samą blokadę
   dla tego samego klucza, **When** obie próby nastąpią w tej samej chwili, **Then** dokładnie
   jedna z nich ją uzyskuje.
5. **Given** nieobsłużony wyjątek w aplikacji, **When** wystąpi, **Then** jest zalogowany
   z pełnym kontekstem (klasa, komunikat, ślad stosu, identyfikator korelacji, treść żądania)
   i nie jest ukrywany ani zamieniany na odpowiedź sukcesu.

---

### Edge Cases

- **Brak danych przy starcie**: pusta baza danych, pusty magazyn porażek, pusta kolejka —
  aplikacja i worker startują bez błędów i bez ostrzeżeń maskujących problem.
- **Kolejność startu**: baza danych lub broker kolejki jeszcze nie przyjmują połączeń, gdy
  aplikacja i worker startują — procesy czekają na gotowość zależności lub są wznawiane, zamiast
  trwale zakończyć działanie.
- **Brak danych dostępowych do sandboxa pocztowego**: środowisko startuje, ale sprawdzenie stanu
  jednoznacznie raportuje kanał pocztowy jako niedziałający.
- **Utrata brokera kolejki w trakcie pracy**: worker loguje błąd połączenia i wznawia pracę po
  powrocie brokera; błąd nie jest połykany.
- **Ponowne uruchomienie środowiska**: przygotowanie schematu bazy jest idempotentne — ponowne
  uruchomienie nie niszczy danych ani nie kończy się błędem.
- **Bardzo duże lub binarne ciało żądania**: aplikacja odrzuca je kodem 4xx i loguje treść
  w ograniczonej, czytelnej formie (obcięta, z adnotacją o obcięciu), bez zapychania logu.
- **Sekrety w treści żądania lub nagłówkach**: wartości pól wrażliwych są maskowane w logach,
  pozostała treść jest logowana w całości.

## Requirements *(mandatory)*

### Functional Requirements

**Środowisko uruchomieniowe**

- **FR-001**: Środowisko MUSI dać się uruchomić jedną komendą i obejmować: proces aplikacji
  obsługujący żądania HTTP, bazę danych, broker kolejki i cache oraz co najmniej jeden proces
  workera kolejki.
- **FR-002**: Aplikacja MUSI uruchamiać się poprawnie przy pustej bazie danych, bez wymogu
  wcześniejszego zasilania danymi.
- **FR-003**: Schemat bazy danych MUSI być przygotowywany automatycznie przy starcie środowiska,
  w sposób idempotentny.
- **FR-004**: Worker MUSI działać jako osobny, długo żyjący proces obsługujący kolejki całej
  aplikacji i MUSI być automatycznie wznawiany po nieoczekiwanym zakończeniu.
- **FR-005**: Aplikacja i worker MUSZĄ tolerować chwilową niedostępność bazy danych i brokera
  kolejki przy starcie (oczekiwanie na gotowość lub automatyczne wznowienie).
- **FR-006**: Dane bazy danych oraz magazynu porażek MUSZĄ przetrwać zatrzymanie i ponowne
  uruchomienie środowiska.
- **FR-007**: Konfiguracja środowiska MUSI być dostarczona jako wzorzec z wartościami domyślnymi
  pozwalającymi na start; jedyną wartością wymaganą od dewelopera są dane dostępowe do sandboxa
  pocztowego.

**Weryfikacja stanu**

- **FR-008**: System MUSI udostępniać sprawdzenie stanu raportujące osobno status: aplikacji,
  bazy danych, kolejki, cache, workera i sandboxa pocztowego.
- **FR-009**: Sprawdzenie stanu MUSI dla każdego niedziałającego komponentu podać czytelną
  przyczynę i zalogować ją na poziomie błędu.
- **FR-010**: Status workera MUSI odzwierciedlać rzeczywiste przetwarzanie kolejki
  (np. świeżość sygnału życia), a nie tylko istnienie procesu.
- **FR-011**: Sprawdzenie stanu dostępne przez HTTP MUSI zwracać kod sukcesu tylko wtedy, gdy
  wszystkie komponenty działają, i ujawniać wyłącznie statusy komponentów, bez danych
  dostępowych ani szczegółów konfiguracji.
- **FR-012**: Kanał pocztowy MUSI wysyłać wyłącznie do sandboxa pocztowego (Mailtrap); wysyłka
  na prawdziwe skrzynki jest zabroniona.
- **FR-013**: Weryfikacja sandboxa pocztowego MUSI polegać na nawiązaniu połączenia
  i pomyślnym uwierzytelnieniu, bez wysyłania wiadomości; udane uwierzytelnienie oznacza, że
  sandbox „odpowiada poprawnie”, a odrzucenie lub brak połączenia — że kanał nie działa.

**Gotowość architektoniczna (mechanizmy przekrojowe)**

- **FR-014** *(poison pills)*: Każde żądanie HTTP z uszkodzonym, niekompletnym lub
  nieprzetwarzalnym ciałem MUSI kończyć się odpowiedzią 4xx z czytelnym komunikatem, nigdy błędem
  serwera, oraz wpisem w logu zawierającym treść żądania.
- **FR-015**: Każde żądanie HTTP MUSI otrzymać identyfikator korelacji (przyjęty od klienta lub
  wygenerowany), zwracany w odpowiedzi i dołączany do wszystkich wpisów w logu z nim związanych;
  mechanizm MUSI umożliwiać przekazanie identyfikatora do przyszłych zadań asynchronicznych.
- **FR-016**: Logi MUSZĄ być ustrukturyzowane (kontekst jako pola, nie sklejony tekst), tak aby
  dało się je filtrować po identyfikatorze korelacji.
- **FR-017**: Sekrety i dane wrażliwe (klucze, podpisy, hasła, tokeny, numery kart) MUSZĄ być
  maskowane w logach według jednej, centralnej listy pól; pozostała treść MUSI być logowana
  w całości, a treści przekraczające ustalony limit — obcinane z adnotacją.
- **FR-018**: Nieobsłużone wyjątki MUSZĄ być logowane z pełnym kontekstem i nie mogą być
  zamieniane na odpowiedź sukcesu.
- **FR-019** *(backoff + dead letter)*: System MUSI posiadać trwały, przeglądalny magazyn porażek
  zadań asynchronicznych, pusty po pierwszym starcie, z możliwością ręcznego ponowienia wpisu.
- **FR-020** *(backoff)*: System MUSI definiować domyślną politykę ponowień z rosnącym odstępem
  (co najmniej 4 próby, każdy kolejny odstęp dłuższy od poprzedniego) oraz limit czasu
  wykonania krótszy niż czas, po którym kolejka uznaje zadanie za porzucone — do użycia przez
  przyszłe zadania.
- **FR-021** *(race condition)*: System MUSI udostępniać blokadę współdzieloną między wszystkimi
  procesami aplikacji, gwarantującą, że przy równoczesnych próbach dla tego samego klucza
  dokładnie jedna się powiedzie.
- **FR-022** *(out-of-order delivery)*: Kolejka MUSI wspierać odłożenie zadania na określony czas
  (opóźnione ponowne przetworzenie), aby przyszła obsługa zdarzeń przychodzących przed swoją
  intencją płatności mogła z niego skorzystać.

### Key Entities

- **Wpis magazynu porażek (dead-letter)**: zadanie asynchroniczne, które trwale się nie powiodło;
  zawiera identyfikator, nazwę kolejki, pełną treść zadania, opis ostatniego błędu, liczbę prób,
  identyfikator korelacji i czas porażki.
- **Raport stanu**: wynik sprawdzenia stanu; lista komponentów z polami: nazwa, status
  (działa / nie działa), przyczyna, czas sprawdzenia.
- **Identyfikator korelacji**: unikalna wartość łącząca żądanie HTTP z wszystkimi wpisami
  w logu i — w przyszłości — z zadaniami asynchronicznymi i wpisami magazynu porażek.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Deweloper przechodzi od świeżego klonu repozytorium do w pełni działającego
  środowiska w mniej niż 10 minut, wykonując jedną komendę uruchomienia i uzupełniając wyłącznie
  dane dostępowe do sandboxa pocztowego.
- **SC-002**: W ciągu 2 minut od uruchomienia sprawdzenie stanu raportuje 100% komponentów jako
  działające.
- **SC-003**: Po nieoczekiwanym zakończeniu procesu workera przetwarzanie kolejki wznawia się
  automatycznie w ciągu 30 sekund.
- **SC-004**: Na pustym środowisku log startowy aplikacji i workera nie zawiera żadnego wpisu
  na poziomie błędu.
- **SC-005**: 100% żądań z uszkodzonym ciałem kończy się odpowiedzią 4xx (0% błędów serwera),
  a każde z nich ma w logu wpis z treścią żądania i identyfikatorem korelacji.
- **SC-006**: Przy 100 równoczesnych próbach zajęcia tej samej blokady dokładnie 1 kończy się
  sukcesem, w 100% powtórzeń testu.
- **SC-007**: Operator ustala, który komponent nie działa i dlaczego, w mniej niż 1 minutę,
  korzystając wyłącznie ze sprawdzenia stanu i logów.
- **SC-008**: W żadnym wpisie logu nie pojawia się jawna wartość pola z centralnej listy pól
  wrażliwych.

## Assumptions

- Odbiorcami tej funkcjonalności są deweloperzy i operatorzy systemu; zespół sprzedaży nie
  korzysta jeszcze z niczego bezpośrednio.
- Środowisko kontenerowe (wymienione w kryteriach akceptacji jako „docker up”) jest
  zainstalowane na maszynie dewelopera.
- Mailtrap jest usługą zewnętrzną (sandbox SMTP); deweloper posiada konto i dane dostępowe
  do skrzynki testowej. Fundament nie uruchamia lokalnego zamiennika Mailtrap.
- Stos technologiczny (framework, broker kolejki i cache, magazyn porażek) jest ustalony
  w konstytucji projektu; specyfikacja nie przesądza o szczegółach implementacji.
- Sprawdzenie stanu przez HTTP nie wymaga uwierzytelnienia, ponieważ nie ujawnia danych
  wrażliwych (FR-011); szczegółowa diagnostyka jest dostępna z linii poleceń.
- Wartości progowe (limit długości logowanej treści, konkretne odstępy ponowień, okres świeżości
  sygnału życia workera) zostaną ustalone w planie implementacji w granicach określonych tu
  i w konstytucji.
- Weryfikacja Mailtrap nie potwierdza doręczenia wiadomości (decyzja z 2026-11-16, opcja A);
  doręczenie end-to-end przez kolejkę zostanie sprawdzone w specyfikacji powiadomień.
  Sprawdzenie bez wysyłki nie jest powiadomieniem, więc nie narusza Zasady I konstytucji.
- Środowisko jest przeznaczone do developmentu i demonstracji; wdrożenie produkcyjne jest poza
  zakresem tej specyfikacji.
