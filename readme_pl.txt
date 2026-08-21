Autouzupełnianie po NIP (w3b_nipautofill)
=========================================

Po wpisaniu numeru NIP w polu „Numer NIP" (vat_number) na stronie zamówienia
moduł pobiera dane firmy z darmowego API Ministerstwa Finansów
(Wykaz podatników VAT — „biała lista", https://wl-api.mf.gov.pl)
i uzupełnia pola: nazwa firmy, ulica, kod pocztowy, miasto.

Działanie
---------
- ST Easy Checkout (one page): moduł reaguje tylko, gdy zaznaczono
  „Firma" w przełączniku Rodzaj klienta (radio p_or_c). Działa w obu
  formularzach: adres dostawy i adres do faktury.
- Standardowy checkout PrestaShop / formularz adresu w koncie klienta:
  moduł reaguje na każde wpisanie poprawnego numeru NIP (nie ma tam
  przełącznika prywatny/firma).
- Pod pustym polem NIP wyświetlana jest podpowiedź „Wpisz NIP, aby
  automatycznie pobrać dane firmy" — od razu po wybraniu „Firma" oraz
  po każdym przeładowaniu formularza; znika po pobraniu danych.
- NIP jest czyszczony przed walidacją: spacje, kropki, myślniki i prefiks
  „PL" są usuwane (także w polu formularza po wyjściu z niego), a suma
  kontrolna sprawdzana po stronie JS i serwera.
- Zapytanie do API MF idzie przez kontroler modułu (ten sam host, bez CORS),
  z tokenem modułu. Rozstrzygnięte odpowiedzi (znaleziono / nie znaleziono)
  są cache'owane w tabeli ps_w3b_nipautofill_cache na dany dzień, żeby nie
  wyczerpywać dziennych limitów darmowego API; wpisy starsze niż 30 dni są
  usuwane automatycznie.
- Token endpointu zależy tylko od sklepu i daty (ważny dzień bieżący
  i poprzedni), NIE od zalogowanego klienta. To celowe: ST Easy Checkout
  loguje i rejestruje klienta AJAX-em bez przeładowania strony, więc token
  liczony przez Tools::getToken() (hash id klienta + hasła) przestawał
  pasować zaraz po zalogowaniu i każde pobranie kończyło się błędem 403.
- Ponieważ token nie jest już powiązany z sesją, nadużycia ogranicza limit
  na IP: maksymalnie 60 zapytań na godzinę, które faktycznie idą do API MF
  (odpowiedzi z cache limitu nie zużywają). Licznik trzyma tabela
  ps_w3b_nipautofill_rate. Po przekroczeniu endpoint zwraca 429
  z reason=rate_limited, a formularz prosi o ręczne uzupełnienie adresu.
- Ulica i miasto są zamieniane na pisownię „Tytułową" (Poznań, Ul. 3 Maja 12),
  nazwa firmy zostaje w oryginalnej pisowni z rejestru.
- Moduł nie zmienia kraju w formularzu — dane z rejestru są zawsze polskie.

Pliki
-----
- w3b_nipautofill.php                 — rejestracja assetów, token, walidacja NIP
- controllers/front/lookup.php        — proxy AJAX do wl-api.mf.gov.pl + cache + limit IP
- views/js/front.js                   — nasłuch pola vat_number, uzupełnianie pól
- views/css/front.css                 — stylowanie komunikatu statusu
- translations/pl.php                 — tłumaczenia PL
- upgrade/upgrade-1.0.1.php           — dokłada tabelę limitu przy aktualizacji

Odinstalowanie usuwa tabelę cache i tabelę limitu.

Historia zmian
--------------
1.0.1 — token endpointu odpięty od tożsamości klienta (naprawa błędu 403 po
        zalogowaniu się w trakcie checkoutu); limit zapytań na IP; komunikat
        o przekroczeniu limitu.
1.0.0 — pierwsza wersja.
