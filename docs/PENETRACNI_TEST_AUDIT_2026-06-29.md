# Penetrační test a bezpečnostní audit

Datum: 2026-06-29  
Prostředí: `https://deti.ivanjelinek.cz/` na Endora.cz  
Rozsah: aplikace Zdraví dětí, veřejné HTTP rozhraní, lokální audit kódu, testovací lokální aplikační flow.

## Shrnutí

Aplikace má dobrý základ: produkce vynucuje HTTPS, interní adresáře nejsou přímo dostupné, dokumenty jsou servírované přes autorizované routy, formuláře mají CSRF ochranu, session cookie má `Secure`, `HttpOnly` a `SameSite=Lax`, uploadované dokumenty jsou ukládané mimo veřejnou část webu a aplikace podporuje šifrování dokumentů na disku.

Největší riziko je v procesu pozvánek a registrace: pozvaný e-mail je použit jako důkaz identity, ale nově registrovaný uživatel nemusí před přijetím do rodiny prokázat vlastnictví e-mailové schránky. U aplikace se zdravotními daty dítěte je to vysoké riziko.

## Stav oprav po implementaci 2026-06-29

- H-01 opraveno: prijeti pozvanek je vazane na `email_verified_at`; neovereny ucet zustava jen cekajici.
- M-01 opraveno: Google callback vyzaduje `email_verified`.
- M-02 opraveno: HTTPS odpovedi posilaji HSTS.
- M-03 opraveno: CSP uz nepovoluje `unsafe-inline` pro skripty ani styly.
- M-04 opraveno: registrace ma rate limiting podle IP.
- L-01 opraveno: logout je stav menici POST akce s CSRF.
- L-02 zustava jako architektonicke residualni riziko sdileneho hostingu; sifrovani dokumentu na disku zustava aktivni.

## Metodika

Provedené kroky:

- statický audit `app/routes.php`, `app/helpers.php`, `app/repositories.php`, DB schémat a testů,
- neinvazivní produkční HTTP kontroly bez brute-force a bez destruktivních akcí,
- kontrola veřejné dostupnosti interních cest,
- kontrola bezpečnostních hlaviček a cookie atributů,
- lokální spuštění testů `tests/smoke.php` a `tests/orchestrated_flows.php`,
- audit toků: login, reset hesla, Google login, pozvánky, rodinné role, upload/zobrazení/mazání dokumentů.

Omezení:

- nebyl proveden DoS, brute-force ani agresivní automatický scan,
- nebyly ručně procházeny produkční zdravotní dokumenty,
- nebyl vytvořen nový produkční testovací účet,
- autentizovaná produkční část byla hodnocena primárně podle kódu a lokálních orchestrace testů.

## Produkční ověření

Ověřené body:

- `http://deti.ivanjelinek.cz/` vrací `301` na HTTPS.
- `/`, chráněné routy dítěte, dokumentů a exportu bez session přesměrovávají na login.
- `/app/routes.php`, `/app/helpers.php`, `/config/config.php`, `/var/uploads/`, `/.git/config`, `.env`, `database/schema.sql` vrací `403`.
- Session cookie má `Secure`, `HttpOnly`, `SameSite=Lax`.
- Aplikační odpovědi obsahují:
  - `Content-Security-Policy`,
  - `X-Frame-Options: DENY`,
  - `X-Content-Type-Options: nosniff`,
  - `Referrer-Policy: same-origin`,
  - `Permissions-Policy`.

## Nálezy

### H-01: Pozvánka do rodiny nevyžaduje ověření e-mailu

Závažnost: vysoká

Popis:

Pokud administrátor rodiny pozve e-mail, aplikace po registraci účtu se stejným e-mailem automaticky přijme čekající pozvánku. Samotná registrace ale neověřuje, že uživatel danou e-mailovou schránku skutečně vlastní.

Dopad:

Útočník, který zná nebo uhádne pozvaný e-mail a zaregistruje se dřív než skutečný adresát, může být přidán do rodiny. To může vést k neoprávněnému přístupu k rodinnému prostoru a potenciálně k dětským zdravotním datům po přidělení přístupů.

Evidence v kódu:

- `accept_pending_invitations_for_user($userId, $email)` přijímá pozvánky podle e-mailu.
- `page_register()` vytvoří účet a hned zpracuje čekající pozvánky.
- Registrace nemá e-mailový verifikační krok.

Doporučení:

- Zavést `email_verified_at` u uživatele.
- Po registraci poslat potvrzovací e-mail s jednorázovým tokenem.
- Pozvánky přijímat až po ověření e-mailu.
- U Google loginu přijímat pozvánku jen pokud Google vrátí `email_verified = true`.
- Do té doby nového uživatele zobrazit administrátorovi jako čekajícího, ne aktivního člena.

### M-01: Google login nekontroluje `email_verified`

Závažnost: střední až vysoká

Popis:

Google callback ověřuje `aud`, `sub` a existenci e-mailu, ale nekontroluje `email_verified`. U běžných Gmail účtů je riziko menší, u externích adres v Google účtu je vhodné tuto hodnotu explicitně vyžadovat.

Dopad:

Pokud by Google vrátil neověřený e-mail, aplikace by jej mohla použít pro přijetí pozvánky nebo založení identity navázané na tento e-mail.

Doporučení:

- V `action_google_callback()` vyžadovat `email_verified === true` nebo řetězec `'true'`.
- Pokud není e-mail ověřený, login odmítnout s jasnou hláškou.

### M-02: Chybí HSTS

Závažnost: střední

Popis:

HTTP je přesměrované na HTTPS, ale HTTPS odpovědi neobsahují `Strict-Transport-Security`.

Dopad:

Bez HSTS je uživatel při první návštěvě teoreticky zranitelnější vůči downgrade/MITM scénářům na nedůvěryhodné síti.

Doporučení:

- Přidat hlavičku:

`Strict-Transport-Security: max-age=31536000; includeSubDomains`

- `preload` přidat jen pokud je jisté, že všechny subdomény budou trvale přes HTTPS.

### M-03: CSP povoluje inline JavaScript a inline CSS

Závažnost: střední

Popis:

Aktuální CSP obsahuje `script-src 'self' 'unsafe-inline'` a `style-src 'self' 'unsafe-inline'`.

Dopad:

Pokud se v budoucnu objeví XSS chyba, inline skripty významně usnadní její zneužití. V aktuálním kódu je většina výstupů escapovaná přes `e()`, ale CSP by měla být poslední vrstva obrany.

Doporučení:

- Přesunout inline JS do `public/assets/app.js`.
- Přesunout inline styly mimo HTML.
- Zavést nonce nebo hash pro nezbytné inline části.
- Cílově používat `script-src 'self'` bez `unsafe-inline`.

### M-04: Veřejná registrace nemá rate limiting

Závažnost: střední

Popis:

Login a reset hesla rate limit mají. Registrace účtu ale podle kódu rate limit nemá.

Dopad:

Riziko automatizované registrace účtů, zahlcení DB, zneužití e-mailových toků nebo přípravy účtů pro pozvánkové útoky.

Doporučení:

- Přidat rate limit na `register` podle IP a e-mailu.
- Logovat `auth.register_rate_limited`.
- Zvážit e-mailové ověření před aktivací účtu.

### L-01: Logout je GET akce

Závažnost: nízká

Popis:

Odhlášení je dostupné přes GET routu `?r=logout`.

Dopad:

Nejde o únik dat, ale cizí stránka může uživatele odhlásit například vložením odkazu nebo obrázku. U citlivé aplikace je vhodné držet všechny stav měnící akce jako POST s CSRF.

Doporučení:

- Změnit logout na POST formulář s CSRF tokenem.
- GET `logout` ponechat jen jako stránku s potvrzením nebo přesměrování.

### L-02: Šifrování dokumentů chrání hlavně data na disku, ne kompromitovaný hosting

Závažnost: nízká až střední

Popis:

Dokumenty jsou šifrované AES-256-GCM, ale klíč je uložený v produkční konfiguraci na stejném hostingu.

Dopad:

Při úniku samotných souborů z upload adresáře šifrování pomáhá. Při plné kompromitaci hostingu nebo konfigurace útočník získá i klíč.

Doporučení:

- Zachovat šifrování.
- Oddělit zálohy souborů od konfigurace.
- U záloh použít samostatný šifrovací klíč mimo Endoru.
- Pro vyšší úroveň bezpečnosti zvážit externí secret management nebo hosting s oddělenými secrets.

## Pozitivní zjištění

- CSRF ochrana je globálně aplikovaná na POST requesty.
- Hesla jsou hashovaná přes `password_hash`.
- Reset hesla používá náhodný token, ukládá hash tokenu a token je jednorázový.
- Login a reset hesla mají rate limiting.
- Session ID se po loginu regeneruje.
- Aktivní zařízení lze revokovat.
- Dokumentové routy vyžadují autorizovaný přístup přes `child_access`.
- Uploady jsou mimo veřejně dostupný web root.
- Přímý přístup k `app`, `config`, `database`, `var`, `.git` a `.env` je na produkci blokovaný.
- Mazání dokumentu je po poslední úpravě testované tak, že smaže jen konkrétní dokument a ostatní dokumenty dítěte nechá zachované.
- PWA náhled dokumentů má fallback pro nepodporované mobilní formáty.

## Doporučené pořadí oprav

1. Zavést ověření e-mailu a navázat na něj přijímání pozvánek.
2. U Google loginu vyžadovat `email_verified`.
3. Přidat rate limiting registrace.
4. Přidat HSTS hlavičku.
5. Převést logout na POST.
6. Postupně zpřísnit CSP a odstranit `unsafe-inline`.
7. Připravit dokumentovaný bezpečný režim záloh: DB, uploady, konfigurační secrets zvlášť.

## Závěr

Aktuální stav není otevřený veřejným únikem souborů nebo konfigurace. Největší reálné riziko je identity flow kolem pozvánek a registrace. U aplikace pracující se zdravotními údaji dětí by e-mailová verifikace měla být brána jako povinný bezpečnostní krok, ne jako volitelné vylepšení.
