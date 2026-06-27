# Testovací scénáře

Spustitelné kontroly:

- `php tests/smoke.php` ověřuje doménovou logiku, databázi, šifrování dokumentů, pozvánky, přístupy, import lékařů a základní CRUD operace.
- `php tests/orchestrated_flows.php` spouští lokální webovou aplikaci nad dočasnou SQLite databází a prochází klíčové UI/HTTP flow.
- `tests/UI_TEST_MATRIX.md` mapuje tlačítka a odkazy na test case.

## TC-01 Registrace a založení rodiny

1. Otevřít registraci.
2. Vyplnit jméno, e-mail a heslo.
3. Ověřit přesměrování na přehled a existenci rodiny administrátora.
4. Ověřit dostupnost Správy rodiny.

## TC-02 Přidání dětí

1. Ve Správě rodiny přidat první dítě včetně váhy a alergií.
2. Ověřit detail dítěte.
3. Vrátit se do Správy rodiny a přidat druhé dítě.
4. Ověřit, že děti mají oddělené záznamy a samostatné přístupy.

## TC-03 Pozvánka rodiče a přístupy

1. Administrátor rodiny odešle pozvánku na e-mail dalšího rodiče.
2. Pozvánka se zobrazí v seznamu a lze ji zrušit.
3. Pozvaný rodič se zaregistruje.
4. Bez explicitního přístupu nevidí žádné dítě.
5. Administrátor rodiny mu nasdílí jen první dítě.
6. Pozvaný rodič vidí první dítě a nevidí druhé dítě.

## TC-04 Záznamy dítěte

1. Na detailu dítěte uložit teplotu.
2. Uložit podání léku.
3. Ověřit samostatný refresh časové osy bez reloadu celé stránky.
4. Uložit uživatelský typ péče.
5. Otevřít editaci záznamu.
6. Smazat záznam.

## TC-05 EHIC

1. Otevřít Dokumentaci dítěte.
2. Nahrát EHIC jako obrázek.
3. Ověřit potvrzení o uložení.
4. Ověřit, že v Přehledu je u dítěte ikona EHIC a odkaz `Zobrazit`.
5. Otevřít `Zobrazit` a ověřit HTTP 200, inline zobrazení a správný MIME typ.
6. Otevřít `Stáhnout` a ověřit attachment.
7. Smazat EHIC.
8. Ověřit, že se odkaz z Přehledu ztratil.

## TC-06 Dokumenty

1. Otevřít Dokumentaci.
2. Vyhledat lékaře pro dokument.
3. Nahrát běžný dokument s názvem a poznámkou.
4. Zobrazit, stáhnout a smazat dokument.
5. Ověřit, že uložený soubor není na disku v plaintextu, pokud je zapnuté šifrování.

## TC-07 Export pro lékaře

1. Otevřít Export pro lékaře.
2. Ověřit základní údaje dítěte.
3. Upravit parametry exportu.
4. Ověřit zahrnutí měření, léků, příznaků, péče, kontrol a dokumentů podle voleb.
5. EHIC zahrnout jen při explicitním zaškrtnutí.

## TC-08 Nastavení účtu a zařízení

1. Otevřít Nastavení.
2. Ověřit seznam aktivních zařízení.
3. Uživatel v roli rodiče vidí možnost smazat vlastní účet.
4. Administrátor rodiny možnost smazání účtu nevidí, dokud rodina existuje.
5. Ověřit odhlášení ostatních zařízení, pokud existují.

## TC-09 Léčiva a typy péče

1. Otevřít Léčiva.
2. Ověřit existenci systémových léků.
3. Přidat vlastní lék.
4. Aktivovat/deaktivovat vlastní lék.
5. Otevřít Typy péče.
6. Ověřit, že systémové položky péče nejsou v uživatelském seznamu.
7. Přidat vlastní typ péče, aktivovat/deaktivovat ho a nepoužitý typ smazat.

## TC-10 Lékaři dítěte

1. Otevřít Lékaře dítěte.
2. Vyhledat podle oboru, města a textu.
3. Přidat lékaře k dítěti.
4. Odebrat lékaře.
5. Ověřit, že obory pochází z NRPZS a jsou řazené podle češtiny.

## TC-11 Přihlášení a reset hesla

1. Zadání neplatného hesla vrátí obecnou chybu.
2. Opakované chyby spustí rate limit.
3. Reset hesla pro neznámý e-mail vrátí stejnou hlášku jako pro známý e-mail.
4. Registrovaný e-mail dostane resetovací odkaz.
5. Token lze použít jen jednou a nové heslo musí splnit minimální délku.

## TC-12 PWA stabilita

1. Otevřít aplikaci po nasazení nové verze.
2. Ověřit, že HTML odkazuje na aktuální verzi JS/CSS.
3. Ověřit, že service worker při dostupné síti nevrací starý JS/CSS.
4. Projít několik obrazovek a ověřit, že tlačítka reagují a stránka nebělá.
