# TODO / Backlog

## Lékařské dokumenty a kontroly

- Přidat plánování kontrol.
  - Rodič zadá datum, čas, typ kontroly, lékaře a poznámku.
  - Kontrola může být plánovaná, proběhlá nebo zrušená.
  - U proběhlé kontroly půjde doplnit závěr, doporučení a přílohy.

- Přidat e-mailové připomínky ke kontrolám.
  - Aplikace pošle připomínku před plánovanou kontrolou.
  - Uživatel si nastaví, kdy připomínku chce dostat.
  - K připomínce půjde připojit nebo odkázat související lékařskou zprávu.

- Přidat možnost uložit kopii EHIC.
  - U dítěte půjde uložit kopii evropského průkazu zdravotního pojištění.
  - V UI musí být jasně vidět, že jde o citlivý dokument.
  - Soubor musí být dostupný jen rodičům s oprávněním k dítěti.

- Přidat OCR pro nahrané skeny.
  - Po nahrání skenu aplikace zkusí rozpoznat text.
  - Rozpoznaný text se uloží k dokumentu a půjde vložit do záznamu z proběhlé kontroly.
  - Uživatel musí mít možnost text ručně opravit.

- Rozšířit export pro lékaře.
  - Do exportu přidat proběhlé kontroly, EHIC a související dokumenty.
  - Export musí jasně oddělit zdravotní záznamy, léky, teploty, lékaře, kontroly a dokumenty.
  - U dokumentů zvážit, jestli se mají exportovat jako odkazy, přílohy nebo jen seznam.

## Provoz a bezpečnost dat

- Dopracovat zálohování databáze a obnovu ze zálohy.
  - Stanovit, kde bude uložená denní záloha databáze.
  - Připravit postup obnovy po chybě nebo smazání dat.
  - Ověřit, že záloha neobsahuje veřejně dostupná data ani uniklé konfigurační údaje.

- Navrhnout failover nebo nouzový provoz.
  - Cílem je minimalizovat riziko, že výpadek hostingu nebo databáze znamená ztrátu všech dat.
  - Pro levný provoz stačí jednoduchý plán: záloha mimo Endoru, export databáze a popsaný postup obnovy.

## Produktové úpravy

- Přepracovat Typy péče.
  - Aktuální koncept nedává v aplikaci jasný smysl.
  - Není dobře provázaný s rychlým záznamem na mobilu.
  - Rychlé záznamy mají být defaultní systémové položky.
  - Uživatelsky přidané položky mají být standardní vlastní položky rodiny.
  - Výsledek má být jednoduchý: rodič vidí praktické volby pro rychlý zápis, ne technickou správu typů.

- Přejmenovat roli Vlastník na Administrátor rodiny.
  - V UI má být místo „Vlastník“ text „Administrátor rodiny“.
  - Oprávnění se nemění: administrátor může spravovat rodinu, rodiče, přístupy k dětem a zrušit rodinu.
  - Texty v nastavení, rodině a chybových hláškách musí používat nové pojmenování konzistentně.
