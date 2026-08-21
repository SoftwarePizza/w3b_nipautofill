# w3b_nipautofill — autouzupełnianie danych firmy po numerze NIP

Moduł PrestaShop (1.7.6+ / 8.x / 9.x). Po wpisaniu numeru NIP w polu
**Numer NIP** (`vat_number`) na stronie zamówienia moduł pobiera dane firmy
z darmowego API Ministerstwa Finansów — [Wykaz podatników VAT („biała
lista")](https://wl-api.mf.gov.pl) — i uzupełnia pola: nazwa firmy, ulica,
kod pocztowy, miasto.

API MF nie wymaga klucza ani rejestracji.

## Działanie

- **ST Easy Checkout** (one page checkout): moduł reaguje tylko, gdy zaznaczono
  „Firma" w przełączniku rodzaju klienta (radio `p_or_c`). Działa w obu
  formularzach — adres dostawy i adres do faktury.
- **Standardowy checkout PrestaShop / formularz adresu w koncie klienta**:
  moduł reaguje na każde wpisanie poprawnego numeru NIP (nie ma tam
  przełącznika prywatny/firma).
- Pod pustym polem NIP wyświetla się podpowiedź „Wpisz NIP, aby automatycznie
  pobrać dane firmy" — od razu po wybraniu „Firma" oraz po każdym przeładowaniu
  formularza AJAX-em; znika po pobraniu danych.
- NIP jest czyszczony przed walidacją: spacje, kropki, myślniki i prefiks `PL`
  są usuwane (także w samym polu po wyjściu z niego), a suma kontrolna
  sprawdzana po stronie JS **i** serwera.
- Ulica i miasto trafiają do formularza w pisowni „Tytułowej"
  (`Płock`, `Ul. 3 Maja 12`); nazwa firmy zostaje w oryginalnej pisowni
  z rejestru.
- Moduł nie zmienia kraju w formularzu — dane z rejestru są zawsze polskie.

## Architektura

Zapytanie do API MF idzie przez własny kontroler modułu (`controllers/front/lookup.php`),
czyli ten sam host — bez CORS i bez wystawiania czegokolwiek w przeglądarce.

**Cache.** Rozstrzygnięte odpowiedzi (znaleziono / nie znaleziono) są zapisywane
w tabeli `ps_w3b_nipautofill_cache` na dany dzień, żeby nie wyczerpywać dziennych
limitów darmowego API. Błędy transportowe nie są cache'owane. Wpisy starsze niż
30 dni usuwają się automatycznie.

**Token.** Token endpointu zależy tylko od sklepu i daty (ważny dzień bieżący
i poprzedni) — celowo **nie** od zalogowanego klienta. Zobacz „Historia zmian",
wersja 1.0.1.

**Limit.** Ponieważ token nie jest powiązany z sesją, nadużycia ogranicza limit
na IP: 60 zapytań na godzinę, które faktycznie idą do API MF (odpowiedzi z cache
limitu nie zużywają). Licznik trzyma tabela `ps_w3b_nipautofill_rate`.
Po przekroczeniu endpoint zwraca `429` z `reason=rate_limited`, a formularz
prosi o ręczne uzupełnienie adresu.

## Instalacja

Wgraj katalog `w3b_nipautofill` do `modules/` i zainstaluj moduł w panelu
(Moduły → Menedżer modułów) albo:

```bash
php bin/console prestashop:module install w3b_nipautofill
```

Moduł nie ma ekranu konfiguracji — działa od razu po instalacji.
Odinstalowanie usuwa obie tabele.

## Odpowiedzi endpointu

`GET /module/w3b_nipautofill/lookup?ajax=1&nip=<nip>&token=<token>`

| Odpowiedź | HTTP | Znaczenie |
|---|---|---|
| `{"ok":true,"found":true,"data":{…}}` | 200 | firma znaleziona |
| `{"ok":true,"found":false}` | 200 | NIP poprawny, brak firmy w rejestrze |
| `{"ok":false,"reason":"invalid_nip"}` | 200 | zła suma kontrolna NIP |
| `{"ok":false,"reason":"api_error"}` | 200 | API MF nie odpowiedziało |
| `{"ok":false,"reason":"rate_limited"}` | 429 | przekroczony limit na IP |
| `{"ok":false,"reason":"token"}` | 403 | zły lub przeterminowany token |

Pole `source` w odpowiedzi mówi, czy dane przyszły z `cache`, czy z `api`.

## Historia zmian

### 1.0.1

Naprawa błędu 403 przy pobieraniu danych po NIP.

Token endpointu liczył się przez `Tools::getToken(false)`, czyli
`hash(id klienta + hash hasła)`. ST Easy Checkout loguje i rejestruje klienta
AJAX-em, bez przeładowania strony — token wypieczony w HTML podczas renderu
jako gość przestawał więc pasować do tego, co serwer liczył już dla
zalogowanego klienta. Każde pobranie kończyło się HTTP 403, a formularz
pokazywał ogólny komunikat o błędzie. Samo API MF działało poprawnie.

- token zależy tylko od sklepu i daty, więc przeżywa logowanie, wylogowanie
  i konwersję gość → klient,
- limit na IP zastępuje utracone powiązanie z sesją,
- nowa tabela `ps_w3b_nipautofill_rate` + `upgrade/upgrade-1.0.1.php`,
- osobny komunikat w JS dla przekroczonego limitu.

### 1.0.0

Pierwsza wersja.

## Licencja

Licencja prywatna. © 2016–2026 W3B Solutions.
