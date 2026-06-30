# UI test matrix

Tento seznam mapuje hlavní grafické ovládací prvky na testovací scénáře. Spustitelné scénáře jsou v `tests/smoke.php` a `tests/orchestrated_flows.php`.

## Automatizované průchody

### TC-SEC-NEG-001 Negativní bezpečnostní průchod
- Ovládací prvky a routy: login, registrace, logout, správa rodiny, přístupy, detail dítěte, záznamy, dokumenty, EHIC, kontroly, export.
- Krytí: `tests/security_negative_flows.php`.
- Ověření: neplatné heslo, rate limit, chybějící CSRF, cizí `child_id`, `record_id`, `document_id`, `appointment_id`, `invitation_id`, cizí lék/typ péče a nepovolený upload neprovedou stavovou změnu.

### TC-FLOW-001 Nová rodina a administrátor
- Ovládací prvky: `Vytvořit účet`, `Správa rodiny`, `Přidat dítě`, `Uložit`, `Zrušit rodinu`.
- Krytí: `tests/orchestrated_flows.php`.
- Ověření: registrace založí uživatele, vytvoří rodinu a zobrazí správu rodiny.

### TC-FLOW-002 Přidání dvou dětí
- Ovládací prvky: `Přidat dítě`, `Detail`, `Dokumenty`, `Lékaři`, `Přístupy`, `Smazat`.
- Krytí: `tests/orchestrated_flows.php`, částečně `tests/smoke.php`.
- Ověření: vzniknou dvě děti, každé má samostatné ID a samostatné řízení přístupu.

### TC-FLOW-003 Pozvánka rodiče a sdílení jen jednoho dítěte
- Ovládací prvky: `Přidat rodiče`, `Zrušit`, `Uložit přístupy`.
- Krytí: `tests/orchestrated_flows.php`, `tests/smoke.php`.
- Ověření: pozvaný rodič se registruje, nejdřív nevidí žádné dítě, po nastavení vidí jen vybrané dítě.

### TC-FLOW-004 Zdravotní záznamy dítěte
- Ovládací prvky: `Uložit teplotu`, `Uložit lék`, `Uložit péči`, `Upravit`, `Uložit změny`, `Smazat`.
- Krytí: `tests/orchestrated_flows.php`, `tests/smoke.php`.
- Ověření: uloží se měření, lék a uživatelský typ péče; záznam jde otevřít pro editaci a smazat. Rychlý zápis příznaků už v detailu dítěte není.

### TC-FLOW-005 EHIC
- Ovládací prvky: ikona EHIC, `Nahrát`, `Zobrazit`, `Stáhnout`, `Smazat`, `Zavřít`, `Uložit EHIC`.
- Krytí: `tests/orchestrated_flows.php`, `tests/smoke.php`.
- Ověření: EHIC se nahraje jako citlivý šifrovaný dokument, zobrazí se v přehledu, `document_view` vrátí HTML stránku s náhledem, `document_inline` vrátí HTTP 200, `Content-Disposition: inline` a dešifrovaná PNG data; stažení vrátí attachment; smazání odstraní odkaz z přehledu.

### TC-FLOW-006 Dokumentace dítěte
- Ovládací prvky: `Dokumentace`, `Uložit EHIC`, `Uložit dokument`, `Zobrazit`, `Stáhnout`, `Smazat`.
- Krytí: `tests/orchestrated_flows.php`, `tests/smoke.php`.
- Ověření: dokumentace obsahuje seznam dokumentů, upload EHIC a upload běžného dokumentu; dokumenty jsou šifrované a dostupné jen oprávněným rodičům.
- Mobilní/PWA ověření: dokumentace se otevře jako běžná sekce stránky, ne jako `<dialog>`, formuláře při odeslání zobrazí stav nahrávání a upload přijímá i `.heic/.heif` soubory z iPhonu.

### TC-FLOW-007 Export pro lékaře
- Ovládací prvky: `Export pro lékaře`, `Uložit nebo tisknout PDF`, `Aktualizovat export`.
- Krytí: `tests/orchestrated_flows.php`.
- Ověření: export stránka je dostupná pro dítě a obsahuje volby exportu.

### TC-FLOW-008 Nastavení a účet rodiče
- Ovládací prvky: `Odhlásit`, `Odhlásit ostatní`, `Odhlásit`, `Smazat můj účet`.
- Krytí: `tests/orchestrated_flows.php`, `tests/smoke.php`.
- Ověření: přizvaný rodič může smazat svůj účet; administrátor rodiny tlačítko nevidí, dokud rodina existuje.

### TC-FLOW-009 Léčiva a typy péče
- Ovládací prvky: `Přidat`, `Aktivovat`, `Deaktivovat`, `Smazat`, `Přidat typ péče`.
- Krytí: `tests/orchestrated_flows.php`, `tests/smoke.php`.
- Ověření: systémové léky existují v DB; typy péče jsou jen uživatelské, lze je přidat, aktivovat/deaktivovat a nepoužitý typ smazat.

### TC-FLOW-010 Lékaři dítěte
- Ovládací prvky: `Lékaři`, `Hledat`, `Přidat`, `Odebrat`, `Detail dítěte`, `Zpět na správu rodiny`.
- Krytí: `tests/smoke.php`; UI kontrola bude rozšířena při další změně NRPZS vyhledávání.
- Ověření: poskytovatel se importuje se všemi odbornostmi, lze ho vyhledat a přiřadit dítěti.

## Závěr coverage

Po doplnění `TC-FLOW-005` má EHIC zobrazení explicitní test case. Kritická tlačítka běžného rodičovského průchodu jsou krytá orchestrací. Zbývající méně časté administrační ovládací prvky mají test case v této matici a částečné DB krytí ve smoke testu; při dalších úpravách UI je vhodné přesunout i `TC-FLOW-009` a `TC-FLOW-010` do plného HTTP průchodu.
