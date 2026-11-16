# Feature Specification: Obsługa webhooków płatności i powiadomienia działu sprzedaży

**Feature Branch**: `002-payment-webhook-notifications`

**Created**: 2026-11-16

**Status**: Draft

**Input**: User description: "Przygotuj specyfikację dla obsługi webhooków z bramki płatniczej, przetworzenia danych, weryfikacji poprawności oraz autoryzacji webhooka, wyeliminowaniu duplikatów do przesłania powiadomienia na kolejkę. Kontekst biznesowy: mikro-system dla platformy e-commerce, który odbiera webhooki o zdarzeniach płatności od dostawcy (np. Stripe/PayU), przetwarza je i wysyła asynchroniczne powiadomienie do zespołu sprzedaży (przykładowy payload: payment_payload_example.json). Wymagania: endpoint odbierający webhooki; listener uruchamiający joby dla kanałów; job kanału Mailtrap; job kanału SalesTeam (mock); nowe kanały i nowe bramki dodawane bez modyfikacji obecnego kodu; każdy job z backoffem 1, 5, 15 minut, po czym dead-letter. Kryteria akceptacji: poprawny token/klucz przechodzi, niepoprawny dostaje brak autoryzacji; nieprawidłowy lub uszkodzony payload odrzucany z odpowiednim statusem; duplikat (po event_id) dostaje 200 z komunikatem, że event jest już przetwarzany; dispatch eventu o odebraniu payloadu; powiadomienia na wszystkie dostępne kanały; problem z kanałem logowany i ponawiany."

## Zakres tej specyfikacji

Specyfikacja obejmuje pełną ścieżkę od przyjęcia webhooka od dostawcy płatności do doręczenia
powiadomienia działowi sprzedaży:

- przyjęcie webhooka, uwierzytelnienie nadawcy i walidację treści,
- deduplikację zdarzeń po identyfikatorze zdarzenia,
- przekazanie przyjętego zdarzenia do systemu powiadomień,
- wysyłkę powiadomień na kanały Mailtrap i SalesTeam (mock) z ponowieniami i dead-letter,
- punkty rozszerzeń dla kolejnych dostawców płatności i kolejnych kanałów.

Specyfikacja korzysta z mechanizmów dostarczonych przez fundament (`001-app-foundation`):
identyfikatora korelacji, ustrukturyzowanego logowania z maskowaniem sekretów, magazynu porażek
(dead-letter), blokady współdzielonej między procesami i jednolitej obsługi błędnych żądań.

**Poza zakresem**:

- model intencji płatności i obsługa zdarzeń dostarczonych w złej kolejności (system nie
  przechowuje intencji płatności; każde zdarzenie jest powiadamiane niezależnie),
- panel do przeglądania zdarzeń i powiadomień,
- polityka retencji zapisanych zdarzeń,
- prawdziwa integracja z systemem SalesTeam (w tej wersji kanał jest mockiem).

## Clarifications

### Session 2026-11-16

- Q: Czy podczas wymiany sekretu podpisu system ma przez jakiś czas przyjmować webhooki podpisane starym i nowym sekretem? → A: Tak — do 2 aktywnych sekretów na dostawcę (bieżący i opcjonalnie poprzedni); podpis jest akceptowany, gdy pasuje do któregokolwiek.
- Q: Jak ma być sterowane zachowanie mocka SalesTeam? → A: Globalny tryb w konfiguracji: sukces, błąd przejściowy, błąd trwały oraz „psuj N pierwszych prób” (N pierwszych prób danego powiadomienia kończy się błędem przejściowym, kolejne sukcesem).
- Q: W jakim języku i strefie czasowej mają być treści powiadomień? → A: Po polsku, czas w strefie Europe/Warsaw z oznaczeniem strefy, kwoty w formacie polskim (299,90 PLN).
- Q: Jakie szczytowe obciążenie webhookami ma obsłużyć system? → A: Skala demonstracyjna — do 1 webhooka na sekundę; bez testu obciążeniowego i bez dławienia kanałów.
- Q: Co system ma robić ze zdarzeniami testowymi (`livemode: false`)? → A: Zależnie od konfiguracji środowiska — domyślnie w środowisku deweloperskim są powiadamiane z oznaczeniem „testowe”, a na produkcji są przyjmowane (200), zapisywane i deduplikowane, ale bez powiadomienia (log info).
- Q: Jak wygląda adres przyjmowania webhooków i jak system rozpoznaje dostawcę? → A: Jeden wspólny adres `POST /api/v1/webhooks/payment`; dostawcę wskazuje nagłówek `X-Payment-Provider` (np. `generic`). Brak nagłówka lub nieznany dostawca → 401, bo bez dostawcy nie da się uwierzytelnić nadawcy.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Przyjęcie autentycznego i poprawnego webhooka (Priority: P1)

Dostawca płatności wysyła webhook o zdarzeniu płatności. System sprawdza, że nadawca jest
autentyczny i że treść ma poprawny format, zapisuje zdarzenie, przekazuje je do systemu
powiadomień i od razu potwierdza przyjęcie. Webhooki od nieuprawnionych nadawców oraz
z uszkodzoną lub niekompletną treścią są odrzucane z jednoznacznym statusem i zalogowane.

**Why this priority**: to jedyne wejście do systemu. Bez bezpiecznego przyjęcia zdarzeń nic
dalej nie działa, a przepuszczenie sfałszowanego webhooka oznaczałoby fałszywą informację
o płatności dla działu sprzedaży.

**Independent Test**: wysłać do systemu (a) poprawny, uwierzytelniony webhook zgodny
z przykładowym payloadem, (b) ten sam webhook z błędnym uwierzytelnieniem, (c) z uszkodzonym
JSON, (d) z brakującym polem wymaganym. Sprawdzić kody odpowiedzi, zapis zdarzenia, przekazanie
do systemu powiadomień (tylko w przypadku a) oraz wpisy w logu.

**Acceptance Scenarios**:

1. **Given** poprawnie uwierzytelniony webhook o prawidłowej treści, **When** dostawca go
   wysyła, **Then** system odpowiada kodem 200 z komunikatem o przyjęciu zdarzenia, identyfikatorem
   zdarzenia i identyfikatorem korelacji, zdarzenie jest trwale zapisane, a do systemu powiadomień
   trafia informacja o przyjętym zdarzeniu płatności.
2. **Given** webhook bez danych uwierzytelniających lub z niepoprawnymi danymi uwierzytelniającymi,
   **When** dostawca go wysyła, **Then** system odpowiada kodem 401, nie zapisuje zdarzenia, nie
   uruchamia powiadomień, a w logu pojawia się wpis z przyczyną odrzucenia i treścią żądania
   (z zamaskowanymi sekretami).
3. **Given** webhook z poprawnym podpisem, ale znacznikiem czasu podpisu starszym niż 5 minut,
   **When** dostawca (lub osoba trzecia powtarzająca przechwycone żądanie) go wysyła, **Then**
   system odpowiada kodem 401 jako na możliwe powtórzenie i loguje odrzucenie.
4. **Given** uwierzytelniony webhook, którego treść nie jest poprawnym JSON, **When** dostawca go
   wysyła, **Then** system odpowiada kodem 400 z czytelnym komunikatem, nie zapisuje zdarzenia,
   a odrzucenie jest zalogowane z treścią żądania.
5. **Given** uwierzytelniony webhook z poprawnym JSON, ale z brakującym lub błędnym polem
   wymaganym (np. brak `event_id`, ujemna kwota, nieprawidłowy kod waluty), **When** dostawca go
   wysyła, **Then** system odpowiada kodem 422 z listą błędnych pól, nie zapisuje zdarzenia,
   a odrzucenie jest zalogowane z treścią żądania.
6. **Given** webhook bez nagłówka `X-Payment-Provider` lub ze wskazaniem dostawcy, którego system
   nie obsługuje, **When** zostaje wysłany, **Then** system odpowiada kodem 401, nie zapisuje
   zdarzenia i loguje próbę z przyczyną „nieznany dostawca”.
7. **Given** poprawny webhook, **When** system nie zdoła trwale zapisać zdarzenia lub przekazać
   go dalej, **Then** odpowiada kodem z grupy 5xx (dostawca ponowi doręczenie), błąd jest
   zalogowany z pełnym kontekstem, a ponowne doręczenie tego zdarzenia zostanie przyjęte
   normalnie, a nie potraktowane jako duplikat.

---

### User Story 2 - Eliminacja duplikatów (Priority: P1)

Dostawcy płatności ponawiają webhooki (timeouty, własne mechanizmy retry), więc to samo
zdarzenie może dotrzeć wielokrotnie, także w tej samej chwili. System rozpoznaje duplikat po
identyfikatorze zdarzenia, potwierdza go dostawcy kodem 200 z informacją, że zdarzenie jest już
przetwarzane, i nie wysyła drugiego powiadomienia.

**Why this priority**: duplikat powiadomienia o płatności wprowadza dział sprzedaży w błąd
(np. podwójne zamówienie). Odpowiedź 200 na duplikat jest wymagana, aby dostawca przestał ponawiać.

**Independent Test**: wysłać ten sam poprawny webhook dwukrotnie po kolei oraz kilkukrotnie
równolegle; sprawdzić, że dokładnie jedno doręczenie zostało przyjęte jako nowe, pozostałe
otrzymały 200 z komunikatem o duplikacie, a na każdym kanale powstało dokładnie jedno powiadomienie.

**Acceptance Scenarios**:

1. **Given** zdarzenie o danym `event_id` zostało już przyjęte, **When** dostawca ponownie wysyła
   webhook z tym samym `event_id`, **Then** system odpowiada kodem 200 z komunikatem, że zdarzenie
   zostało już odebrane i jest przetwarzane, nie przekazuje go ponownie do systemu powiadomień
   i loguje wykrycie duplikatu.
2. **Given** dwa identyczne webhooki docierają w tej samej chwili, **When** system je obsługuje,
   **Then** dokładnie jeden zostaje przyjęty jako nowe zdarzenie, a drugi otrzymuje odpowiedź
   o duplikacie.
3. **Given** wcześniejsze doręczenie zdarzenia zostało odrzucone (401, 400, 422 lub 5xx),
   **When** dostawca wysyła je ponownie w poprawnej postaci, **Then** system przyjmuje je jako
   nowe zdarzenie.
4. **Given** zdarzenie o danym `event_id` zostało już przyjęte, **When** dociera webhook z tym
   samym `event_id`, ale inną treścią, **Then** system traktuje go jako duplikat (odpowiedź 200,
   brak ponownego przetworzenia) i loguje ostrzeżenie o rozbieżności treści.
5. **Given** dwóch różnych dostawców wysyła zdarzenia o tym samym `event_id`, **When** system je
   obsługuje, **Then** oba są przyjmowane jako odrębne zdarzenia.

---

### User Story 3 - Powiadomienie działu sprzedaży na wszystkich kanałach (Priority: P1)

Po przyjęciu zdarzenia płatności dział sprzedaży dostaje powiadomienie na każdym dostępnym
kanale: e-mail (przez sandbox Mailtrap) oraz SalesTeam. Powiadomienie zawiera informacje
potrzebne do obsługi zamówienia: numer zamówienia, kwotę, klienta i metodę płatności.

**Why this priority**: to jest wartość biznesowa całego systemu — dział sprzedaży szybko
dowiaduje się o opłaconych zamówieniach.

**Independent Test**: wysłać poprawny webhook przy działających kanałach; sprawdzić, że
w skrzynce Mailtrap pojawił się e-mail, a mock SalesTeam zarejestrował powiadomienie, oba
z poprawną treścią i tym samym identyfikatorem korelacji.

**Acceptance Scenarios**:

1. **Given** przyjęte zdarzenie płatności i włączone kanały Mailtrap i SalesTeam, **When** system
   przetwarza powiadomienia, **Then** dla każdego kanału uruchamiane jest osobne zadanie
   asynchroniczne, a każdy kanał otrzymuje dokładnie jedno powiadomienie.
2. **Given** przykładowy payload (zamówienie `ORD-2024-8839`, kwota 29990 w jednostkach
   podrzędnych PLN, klient Jan Kowalski / Kowalski Dev Ltd, BLIK przez mBank), **When**
   powiadomienie zostaje wysłane, **Then** zawiera co najmniej: typ zdarzenia, numer zamówienia,
   kwotę w czytelnej postaci (299,90 PLN), dane klienta (imię i nazwisko, firma, e-mail, kraj),
   metodę płatności i wystawcę, identyfikator transakcji i referencję dostawcy, sklep i kanał
   sprzedaży, czas zdarzenia (10.06.2024 16:20 czasu polskiego dla znacznika `1718029200`) oraz
   identyfikator zdarzenia — całość po polsku.
3. **Given** kanał wyłączony w konfiguracji, **When** system przetwarza powiadomienia, **Then**
   na ten kanał nie jest wysyłane powiadomienie, a pozostałe kanały działają normalnie.
4. **Given** zdarzenie testowe (`livemode: false`) i środowisko skonfigurowane do powiadamiania
   o zdarzeniach testowych (domyślnie deweloperskie), **When** powiadomienie zostaje wysłane,
   **Then** jest wyraźnie oznaczone jako testowe.
5. **Given** zdarzenie testowe (`livemode: false`) i środowisko skonfigurowane bez powiadamiania
   o zdarzeniach testowych (domyślnie produkcja), **When** dostawca je wysyła, **Then** system
   odpowiada 200, zapisuje je i deduplikuje, ale nie wysyła powiadomień, a pominięcie jest
   zalogowane na poziomie informacyjnym.
6. **Given** poprawne zdarzenie innego typu niż `charge.successful` (np. `charge.failed`),
   **When** zostaje przyjęte, **Then** dział sprzedaży otrzymuje powiadomienie na wszystkich
   dostępnych kanałach, a typ zdarzenia jest w nim wyraźnie widoczny.
7. **Given** odpowiedź na webhook, **When** dostawca ją otrzymuje, **Then** nie czekał na wysyłkę
   powiadomień — odpowiedź jest niezależna od stanu i szybkości kanałów.

---

### User Story 4 - Odporność kanałów na awarie (Priority: P2)

Kanał zewnętrzny bywa chwilowo niedostępny. System ponawia wysyłkę z rosnącym odstępem
(po 1, 5 i 15 minutach), każdą nieudaną próbę loguje, a po wyczerpaniu prób umieszcza zadanie
w magazynie porażek, skąd operator może je ręcznie ponowić. Awaria jednego kanału nie wpływa
na pozostałe.

**Why this priority**: chroni przed utratą powiadomień przy typowych, przejściowych awariach,
ale system dostarcza wartość (US1–US3) także bez niej przy działających kanałach.

**Independent Test**: ustawić mock SalesTeam w tryb błędu przejściowego i wysłać poprawny
webhook; sprawdzić, że Mailtrap otrzymał powiadomienie natychmiast, SalesTeam był ponawiany po
1, 5 i 15 minutach (z wpisem w logu przy każdej porażce), a po ostatniej próbie zadanie trafiło
do magazynu porażek. Następnie przełączyć mock w tryb sukcesu, ponowić wpis ręcznie i sprawdzić
doręczenie. Osobno: ustawić tryb „psuj 2 pierwsze próby” i sprawdzić, że powiadomienie zostało
doręczone automatycznie w trzeciej próbie (po 1 i 5 minutach), dokładnie raz.

**Acceptance Scenarios**:

1. **Given** kanał zwraca błąd przejściowy (niedostępność, timeout, błąd serwera, przekroczony
   limit żądań), **When** zadanie wysyłki się nie powiedzie, **Then** porażka jest zalogowana na
   poziomie błędu z nazwą kanału, numerem próby, identyfikatorem zdarzenia, identyfikatorem
   korelacji, treścią powiadomienia i opisem błędu, a kolejna próba jest zaplanowana.
2. **Given** kanał niedostępny, **When** kolejne próby się nie udają, **Then** ponowienia
   następują po 1, 5 i 15 minutach od poprzedniej porażki (łącznie 4 próby).
3. **Given** kanał wraca do działania przed wyczerpaniem prób, **When** następuje kolejna próba,
   **Then** powiadomienie zostaje doręczone dokładnie raz, bez ingerencji operatora.
4. **Given** wszystkie 4 próby się nie powiodły, **When** ostatnia próba kończy się porażką,
   **Then** zadanie trafia do magazynu porażek z pełną treścią, nazwą kanału, liczbą prób,
   ostatnim błędem, identyfikatorem korelacji i czasem porażki, a fakt ten jest zalogowany.
5. **Given** kanał zwraca błąd trwały (np. odrzucenie powiadomienia jako nieprawidłowego),
   **When** zadanie wysyłki się nie powiedzie, **Then** zadanie trafia od razu do magazynu
   porażek, bez kolejnych prób, z zalogowaną przyczyną.
6. **Given** jeden kanał jest niedostępny, **When** system wysyła powiadomienia, **Then**
   pozostałe kanały doręczają powiadomienie bez opóźnienia.
7. **Given** powiadomienie zostało już doręczone na kanał, **When** zadanie dla tego kanału
   zostanie wykonane ponownie (ponowienie lub ręczny retry z magazynu porażek), **Then** kanał nie
   otrzymuje drugiego powiadomienia.

---

### User Story 5 - Rozszerzalność o nowe kanały i nowych dostawców (Priority: P3)

Deweloper dodaje kolejny kanał powiadomień (np. komunikator) lub obsługę kolejnego dostawcy
płatności (np. Stripe) przez dodanie nowego komponentu i jego rejestrację w konfiguracji,
bez modyfikacji istniejącego kodu przyjmowania webhooków, deduplikacji, powiadomień ani
pozostałych kanałów i dostawców.

**Why this priority**: wymóg architektoniczny o długoterminowej wartości; nie wpływa na
działanie systemu dla obecnych kanałów i dostawcy.

**Independent Test**: w środowisku testowym zarejestrować dodatkowy, testowy kanał
i dodatkowego, testowego dostawcę; sprawdzić, że webhook nowego dostawcy jest przyjmowany,
a powiadomienie trafia także na nowy kanał — przy zerowej liczbie zmian w istniejących
komponentach.

**Acceptance Scenarios**:

1. **Given** nowy kanał zarejestrowany w konfiguracji, **When** zostaje przyjęte zdarzenie
   płatności, **Then** nowy kanał otrzymuje powiadomienie z tymi samymi gwarancjami (osobne
   zadanie, ponowienia, magazyn porażek, logowanie) co kanały istniejące.
2. **Given** nowy dostawca zarejestrowany w konfiguracji wraz z własnym sposobem uwierzytelnienia
   i formatem treści, **When** wysyła poprawny webhook na wspólny adres ze wskazaniem siebie
   w nagłówku `X-Payment-Provider`, **Then** zdarzenie przechodzi
   tę samą ścieżkę (uwierzytelnienie, walidacja, deduplikacja, powiadomienia) co zdarzenia
   istniejącego dostawcy.
3. **Given** nowy dostawca, **When** wysyła webhook z błędnym uwierzytelnieniem lub treścią,
   **Then** otrzymuje te same kody odpowiedzi (401, 400, 422) co istniejący dostawca.

---

### Edge Cases

- **Równoczesne duplikaty**: dwa lub więcej identycznych webhooków w tej samej milisekundzie —
  dokładnie jeden przyjęty, reszta dostaje odpowiedź o duplikacie (US2, scenariusz 2).
- **Duplikat zdarzenia, którego powiadomienie trafiło do magazynu porażek**: odpowiedź 200
  o duplikacie, bez ponownego przetwarzania; odzyskanie odbywa się przez ręczne ponowienie wpisu
  z magazynu porażek.
- **Awaria zapisu lub przekazania po uwierzytelnieniu i walidacji**: odpowiedź 5xx, brak
  rejestracji identyfikatora zdarzenia, ponowne doręczenie przyjmowane jako nowe (US1, scenariusz 7).
- **Powtórzenie przechwyconego żądania**: poprawny podpis, ale znacznik czasu podpisu poza oknem
  5 minut — odpowiedź 401 (US1, scenariusz 3). Znacznik czasu z przyszłości poza oknem także 401.
- **Brak znacznika czasu podpisu lub nagłówek podpisu w złym formacie**: odpowiedź 401.
- **Brak lub nieznana wartość `X-Payment-Provider`**: odpowiedź 401, niezależnie od treści
  i podpisu (US1, scenariusz 6).
- **Uwierzytelnienie poprawne, treść pusta**: odpowiedź 400.
- **Kolejność kontroli**: uwierzytelnienie jest sprawdzane przed walidacją treści — nieuprawniony
  nadawca z uszkodzoną treścią dostaje 401, nie 400/422, i nie poznaje reguł walidacji.
- **Brakujące pola opcjonalne** (np. firma klienta, wystawca metody płatności): zdarzenie jest
  przyjmowane, powiadomienie pokazuje brak wartości zamiast błędu.
- **Nieznane dodatkowe pola w treści**: są ignorowane przy walidacji i zachowywane w zapisanej
  surowej treści zdarzenia.
- **Kwota zerowa, ujemna lub niecałkowita**: odrzucenie z kodem 422.
- **Brak włączonych kanałów**: zdarzenie jest przyjmowane i zapisane, a brak kanałów jest
  zalogowany jako ostrzeżenie.
- **Kanał ulega awarii w trakcie wysyłki** (np. timeout po wysłaniu): ponowienie nie może
  skutkować drugim powiadomieniem tam, gdzie kanał potwierdził doręczenie; system przyjmuje
  zasadę „co najwyżej raz po potwierdzeniu doręczenia”.
- **Błąd konfiguracji kanału** (np. brak danych dostępowych do Mailtrap): traktowany jako błąd
  trwały kanału, zalogowany; pozostałe kanały działają.

## Requirements *(mandatory)*

### Functional Requirements

**Przyjmowanie webhooka**

- **FR-001**: System MUSI udostępniać jeden wspólny adres przyjmowania webhooków
  `POST /api/v1/webhooks/payment` dla wszystkich dostawców. Dostawcę MUSI wskazywać nagłówek
  `X-Payment-Provider` (identyfikator dostawcy, np. `generic`). Brak nagłówka lub wskazanie
  dostawcy nieobsługiwanego MUSI kończyć się kodem 401 i wpisem w logu, przed jakąkolwiek
  dalszą kontrolą.
- **FR-002**: System MUSI uwierzytelnić nadawcę webhooka przed jakimkolwiek innym przetwarzaniem,
  mechanizmem właściwym dla danego dostawcy, z sekretem przechowywanym w konfiguracji. Brak lub
  niepoprawne dane uwierzytelniające MUSZĄ kończyć się kodem 401, bez zapisu zdarzenia i bez
  uruchamiania powiadomień.
- **FR-002a**: Dla pierwszego dostawcy uwierzytelnienie MUSI polegać na podpisie HMAC-SHA256
  wyliczonym wspólnym sekretem z surowej treści żądania i znacznika czasu podpisu, przekazanym
  w nagłówku żądania. Podpis MUSI być porównywany w czasie stałym. Żądanie, którego znacznik czasu
  podpisu różni się od bieżącego czasu systemu o więcej niż 5 minut (w przeszłość lub przyszłość),
  MUSI być odrzucone kodem 401 jako możliwe powtórzenie, nawet jeśli podpis jest poprawny.
- **FR-002b**: Konfiguracja dostawcy MUSI przyjmować sekret bieżący i opcjonalnie sekret
  poprzedni. Podpis MUSI być akceptowany, gdy pasuje do któregokolwiek aktywnego sekretu, tak aby
  rotację sekretu dało się przeprowadzić bez okresu odpowiedzi 401. Usunięcie sekretu poprzedniego
  z konfiguracji MUSI natychmiast kończyć jego akceptację. Log przyjęcia MUSI wskazywać, którym
  sekretem (bieżącym czy poprzednim) zweryfikowano podpis, bez ujawniania jego wartości.
- **FR-003**: Treść, która nie jest poprawnym JSON lub jest pusta, MUSI być odrzucona kodem 400
  z czytelnym komunikatem.
- **FR-004**: System MUSI zwalidować strukturę treści według formatu danego dostawcy. Dla
  formatu z przykładowego payloadu wymagane są: identyfikator zdarzenia, typ zdarzenia, znacznik
  czasu, identyfikator transakcji, kwota (dodatnia liczba całkowita w jednostkach podrzędnych),
  waluta (trzyliterowy kod ISO 4217), status transakcji oraz numer zamówienia. Pozostałe pola
  (dane klienta, metoda płatności, sklep, kanał sprzedaży) są opcjonalne, ale jeśli występują,
  MUSZĄ mieć poprawny typ. Niespełnienie reguł MUSI kończyć się kodem 422 z listą błędnych pól.
- **FR-005**: Każde poprawne zdarzenie płatności, niezależnie od typu (np. `charge.successful`,
  `charge.failed`, zwrot), MUSI zostać przyjęte i powiadomione działowi sprzedaży. Typ zdarzenia
  jest wymaganym, niepustym tekstem; system nie utrzymuje listy dozwolonych typów, a typ MUSI być
  wyraźnie widoczny w powiadomieniu.
- **FR-006**: Każde odrzucenie webhooka (401, 400, 422) MUSI być zalogowane z przyczyną,
  nazwą dostawcy, identyfikatorem korelacji i treścią żądania z zamaskowanymi sekretami.
- **FR-007**: Przyjęte zdarzenie MUSI zostać trwale zapisane (wraz z surową treścią, dostawcą,
  identyfikatorem zdarzenia, typem, czasem przyjęcia i identyfikatorem korelacji) przed wysłaniem
  odpowiedzi.
- **FR-008**: Po zapisie system MUSI opublikować wewnętrzne zdarzenie „przyjęto zdarzenie
  płatności” z danymi w ujednoliconym modelu i identyfikatorem korelacji, po czym odpowiedzieć
  kodem 200 z komunikatem o przyjęciu, identyfikatorem zdarzenia i identyfikatorem korelacji.
  Odpowiedź NIE MOŻE czekać na wysyłkę powiadomień.
- **FR-009**: Jeśli zapis lub publikacja zdarzenia się nie powiedzie, system MUSI odpowiedzieć
  kodem 5xx, zalogować błąd z pełnym kontekstem i NIE MOŻE pozostawić stanu, w którym ponowne
  doręczenie zostanie uznane za duplikat, mimo że powiadomienia nie zostały zlecone.

**Deduplikacja**

- **FR-010**: Kluczem deduplikacji MUSI być para: dostawca + identyfikator zdarzenia nadany przez
  dostawcę (`event_id`).
- **FR-011**: Webhook o kluczu już przyjętym MUSI otrzymać kod 200 z komunikatem, że zdarzenie
  zostało już odebrane i jest przetwarzane, oraz identyfikatorem zdarzenia; NIE MOŻE ponownie
  publikować zdarzenia ani wysyłać powiadomień. Wykrycie duplikatu MUSI być zalogowane.
- **FR-012**: Przy równoczesnych doręczeniach tego samego klucza dokładnie jedno MUSI zostać
  przyjęte jako nowe zdarzenie.
- **FR-013**: Doręczenia odrzucone (401, 400, 422, 5xx) NIE MOGĄ rejestrować klucza
  deduplikacji.
- **FR-014**: Duplikat o treści innej niż pierwotnie przyjęta MUSI być potraktowany jako duplikat
  i zalogowany jako ostrzeżenie o rozbieżności treści.

**Dostawcy płatności**

- **FR-015**: Każdy dostawca MUSI dostarczać: sposób uwierzytelnienia, reguły walidacji swojego
  formatu oraz przekształcenie treści do ujednoliconego modelu zdarzenia płatności. Pozostała
  część systemu (deduplikacja, powiadomienia, kanały) MUSI operować wyłącznie na modelu
  ujednoliconym.
- **FR-016**: Dodanie nowego dostawcy MUSI wymagać wyłącznie dodania nowego komponentu dostawcy
  i jego rejestracji w konfiguracji (wraz z sekretem); NIE MOŻE wymagać zmian w istniejącym kodzie
  przyjmowania webhooków, deduplikacji, powiadomień, kanałów ani innych dostawców.
- **FR-017**: W tej wersji system MUSI obsługiwać jednego dostawcę, którego format odpowiada
  przykładowemu payloadowi.

**Powiadomienia i kanały**

- **FR-018**: Po opublikowaniu zdarzenia „przyjęto zdarzenie płatności” system MUSI asynchronicznie
  zlecić osobne zadanie wysyłki dla każdego dostępnego kanału. Kanał dostępny to kanał
  zarejestrowany i włączony w konfiguracji.
- **FR-019**: Kanał MUSI dać się wyłączyć i włączyć w konfiguracji bez zmian w kodzie.
- **FR-019a**: Konfiguracja środowiska MUSI określać, czy zdarzenia testowe (`livemode: false`)
  wyzwalają powiadomienia. Domyślnie: w środowisku deweloperskim tak (z oznaczeniem „testowe”),
  na produkcji nie. Gdy powiadomienia są wyłączone, zdarzenie testowe jest nadal uwierzytelniane,
  walidowane, zapisywane i deduplikowane, otrzymuje odpowiedź 200, a pominięcie powiadomień MUSI
  zostać zalogowane na poziomie informacyjnym.
- **FR-020**: Powiadomienie MUSI zawierać co najmniej: typ zdarzenia, numer zamówienia, kwotę
  w czytelnej postaci z walutą, dane klienta (imię i nazwisko, firma, e-mail, kraj — jeśli
  dostępne), metodę płatności i wystawcę, identyfikator transakcji, referencję dostawcy,
  identyfikator sklepu i kanał sprzedaży, czas zdarzenia, identyfikator zdarzenia, nazwę
  dostawcy oraz oznaczenie zdarzenia testowego (`livemode: false`).
- **FR-020a**: Treść powiadomień (temat, treść, etykiety pól) MUSI być w języku polskim. Czasy
  MUSZĄ być prezentowane w strefie Europe/Warsaw z oznaczeniem strefy, a kwoty w formacie polskim
  (przecinek dziesiętny, kod waluty po kwocie, np. 299,90 PLN).
- **FR-021**: Kanał Mailtrap MUSI wysyłać powiadomienie jako e-mail na skonfigurowany adres
  (lub adresy) działu sprzedaży, wyłącznie przez sandbox Mailtrap.
- **FR-022**: Kanał SalesTeam MUSI być mockiem zachowującym się jak prawdziwa integracja:
  rejestruje (loguje) otrzymane powiadomienia i pozwala w konfiguracji wybrać jeden globalny tryb:
  sukces, błąd przejściowy (każda próba), błąd trwały lub „psuj N pierwszych prób” (N pierwszych
  prób danego powiadomienia kończy się błędem przejściowym, kolejne sukcesem; N konfigurowalne).
  Zachowanie MUSI być deterministyczne, tak aby ponowienia, automatyczny powrót po awarii
  i magazyn porażek dało się powtarzalnie zademonstrować i przetestować.
- **FR-023**: Dodanie nowego kanału MUSI wymagać wyłącznie dodania nowego komponentu kanału
  i jego rejestracji w konfiguracji; NIE MOŻE wymagać zmian w istniejącym kodzie przyjmowania
  webhooków, powiadomień, dostawców ani innych kanałów.
- **FR-024**: Awaria, spowolnienie lub wyłączenie jednego kanału NIE MOŻE blokować ani opóźniać
  doręczenia na pozostałe kanały.
- **FR-025**: Identyfikator korelacji nadany przy przyjęciu webhooka MUSI być przekazywany do
  wszystkich zadań wysyłki, wpisów w logu i wpisów magazynu porażek związanych z tym zdarzeniem.

**Odporność na awarie**

- **FR-026**: Każde zadanie wysyłki MUSI mieć najwyżej 4 próby; po nieudanej próbie kolejna
  następuje po 1 minucie, następnie po 5 minutach, następnie po 15 minutach.
- **FR-027**: Każda nieudana próba MUSI być zalogowana na poziomie błędu z: nazwą kanału, numerem
  próby, identyfikatorem zdarzenia, identyfikatorem korelacji, treścią powiadomienia oraz klasą,
  komunikatem i śladem stosu błędu.
- **FR-028**: Błędy przejściowe kanału (niedostępność, timeout, błąd serwera, przekroczony limit
  żądań) MUSZĄ skutkować ponowieniem. Błędy trwałe (odrzucenie powiadomienia jako nieprawidłowego,
  błąd konfiguracji kanału) MUSZĄ kończyć zadanie od razu, bez kolejnych prób.
- **FR-029**: Zadanie, które wyczerpało próby lub zakończyło się błędem trwałym, MUSI trafić do
  magazynu porażek z pełną treścią, nazwą kanału, liczbą prób, ostatnim błędem, identyfikatorem
  korelacji i czasem porażki, z możliwością ręcznego ponowienia.
- **FR-030**: Wysyłka MUSI być idempotentna względem klucza: dostawca + identyfikator zdarzenia
  + kanał. Ponowne wykonanie zadania (automatyczne lub ręczne) NIE MOŻE wysłać drugiego
  powiadomienia na kanał, który potwierdził doręczenie.
- **FR-031**: Każda próba wysyłki MUSI mieć ograniczony czas trwania, tak aby zawieszony kanał
  był traktowany jako błąd przejściowy, a nie blokował przetwarzania.

### Key Entities

- **Dostawca płatności**: zewnętrzna bramka wysyłająca webhooki; ma identyfikator (podawany
  w nagłówku `X-Payment-Provider`), sekret bieżący i opcjonalny sekret poprzedni (na czas rotacji), format treści
  i reguły jego walidacji.
- **Odebrane zdarzenie**: trwały zapis przyjętego webhooka; zawiera dostawcę, identyfikator
  zdarzenia nadany przez dostawcę (unikalny w obrębie dostawcy), typ zdarzenia, surową treść,
  znacznik czasu dostawcy, tryb (produkcyjny/testowy), czas przyjęcia i identyfikator korelacji.
- **Zdarzenie płatności (model ujednolicony)**: niezależna od dostawcy postać zdarzenia:
  typ, transakcja (identyfikator, referencja dostawcy, kwota w jednostkach podrzędnych, waluta,
  status, metoda płatności, wystawca), klient (identyfikator, e-mail, imię, nazwisko, firma,
  kraj), metadane zamówienia (numer zamówienia, sklep, kanał sprzedaży), tryb i czas zdarzenia.
- **Kanał powiadomień**: miejsce doręczenia powiadomienia działowi sprzedaży (Mailtrap,
  SalesTeam, przyszłe); ma nazwę, stan włączenia i własną konfigurację.
- **Doręczenie powiadomienia**: wynik wysyłki jednego zdarzenia na jeden kanał; klucz: dostawca
  + identyfikator zdarzenia + kanał; stan (oczekuje / doręczone / nieudane), liczba prób,
  ostatni błąd, czas doręczenia.
- **Wpis magazynu porażek**: zdefiniowany w `001-app-foundation`; tutaj dodatkowo wskazuje kanał
  i identyfikator zdarzenia.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 100% webhooków z brakującym, niepoprawnym lub przeterminowanym (poza oknem
  5 minut) uwierzytelnieniem otrzymuje odpowiedź 401 i nie powoduje żadnego powiadomienia.
- **SC-002**: 100% webhooków z uszkodzoną lub niepoprawną treścią otrzymuje odpowiedź 400 lub 422
  (0% błędów serwera), a każde odrzucenie ma wpis w logu z treścią żądania i identyfikatorem
  korelacji.
- **SC-003**: Dostawca płatności otrzymuje odpowiedź na webhook w czasie poniżej 1 sekundy
  w 95% przypadków, niezależnie od stanu kanałów powiadomień.
- **SC-004**: Przy działających kanałach dział sprzedaży otrzymuje powiadomienie na każdym
  dostępnym kanale w ciągu 1 minuty od przyjęcia webhooka.
- **SC-005**: Przy 10 doręczeniach tego samego zdarzenia (sekwencyjnych i równoczesnych) każdy
  kanał otrzymuje dokładnie 1 powiadomienie, a 9 doręczeń otrzymuje odpowiedź 200 z komunikatem
  o duplikacie — w 100% powtórzeń testu.
- **SC-006**: Kanał niedostępny krócej niż 20 minut otrzymuje powiadomienie po powrocie do
  działania bez ingerencji operatora.
- **SC-007**: 0% utraconych powiadomień: każde zlecone powiadomienie jest albo doręczone, albo
  znajduje się w magazynie porażek najpóźniej 25 minut po pierwszej próbie.
- **SC-008**: Awaria jednego kanału nie wydłuża czasu doręczenia na pozostałe kanały ponad
  wartość z SC-004.
- **SC-009**: Dodanie nowego kanału lub nowego dostawcy nie wymaga zmiany żadnego istniejącego
  komponentu poza rejestracją w konfiguracji — weryfikowane przeglądem zmiany.
- **SC-010**: Operator ustala, dlaczego dane powiadomienie nie zostało doręczone, w mniej niż
  2 minuty, korzystając z identyfikatora zdarzenia lub korelacji, logów i magazynu porażek.
- **SC-011**: Przy szczytowym obciążeniu do 1 webhooka na sekundę kryteria SC-003 i SC-004 są
  spełnione; wyższe obciążenie nie jest wymagane w tej wersji.

## Assumptions

- Wszyscy dostawcy wysyłają na jeden adres `POST /api/v1/webhooks/payment`; dostawca jest
  rozpoznawany po nagłówku `X-Payment-Provider` (decyzja z 2026-11-16). Dostawcy, którzy nie
  pozwalają dodać własnego nagłówka, wymagają w przyszłości pośrednika lub zmiany tej decyzji.
- Pierwszy obsługiwany dostawca ma format przykładowego payloadu; jego nazwa zostanie ustalona
  w planie. Natywne formaty Stripe i PayU to przyszli dostawcy dodawani zgodnie z FR-016.
- Kwota w treści jest wyrażona w jednostkach podrzędnych waluty (29990 PLN = 299,90 PLN).
- Obsługa zdarzeń testowych (`livemode: false`) zależy od konfiguracji środowiska (FR-019a,
  decyzja z 2026-11-16). Przykładowy payload ma `livemode: true`, więc jest powiadamiany w każdym
  środowisku.
- Uwierzytelnienie ma pierwszeństwo przed walidacją treści (401 przed 400/422).
- Dostawca podpisuje każde doręczenie (także ponowione) świeżym znacznikiem czasu, więc okno
  5 minut nie blokuje jego własnych ponowień; dokładny format nagłówka podpisu zostanie ustalony
  w planie (decyzja z 2026-11-16: HMAC + okno czasowe).
- Zegar systemu jest zsynchronizowany (rozbieżność z zegarem dostawcy mieści się w oknie 5 minut).
- Powiadomienia obejmują wszystkie typy zdarzeń, bez filtrowania (decyzja z 2026-11-16);
  wszystkie typy zdarzeń pierwszego dostawcy mają tę samą strukturę co przykładowy payload.
- Przyjęte zdarzenie i zduplikowane zdarzenie otrzymują kod 200; rozróżnia je komunikat
  w odpowiedzi.
- Zapisy odebranych zdarzeń są przechowywane bezterminowo w tej wersji; polityka retencji jest
  poza zakresem, a deduplikacja działa dla wszystkich zapisanych zdarzeń.
- Duplikat zdarzenia, którego powiadomienie trafiło do magazynu porażek, nie wznawia
  przetwarzania; odzyskanie odbywa się przez ręczne ponowienie wpisu.
- Adres (lub adresy) działu sprzedaży dla kanału Mailtrap jest podawany w konfiguracji.
- Skala jest demonstracyjna (do 1 webhooka na sekundę, decyzja z 2026-11-16): test obciążeniowy,
  dławienie kanałów i ograniczanie liczby żądań na adresie przyjmowania są poza zakresem; przy tej
  skali limity wysyłki sandboxa Mailtrap nie są wąskim gardłem.
- Przesłanie danych osobowych klienta (imię, nazwisko, e-mail) do wewnętrznego działu sprzedaży
  jest dozwolone; w logach dane te nie są maskowane (konstytucja maskuje sekrety i dane
  uwierzytelniające, nie dane kontaktowe).
- Mechanizmy przekrojowe (identyfikator korelacji, maskowanie, magazyn porażek, blokada
  współdzielona, jednolita obsługa błędnych żądań) są dostarczone przez `001-app-foundation`.
- Odstępy ponowień 1, 5, 15 minut (4 próby) spełniają wymóg konstytucji i fundamentu
  dotyczący rosnącego odstępu i co najmniej 4 prób; przykładowe wartości z konstytucji
  (`[10, 30, 90, 270]` s) były tylko przykładem.
