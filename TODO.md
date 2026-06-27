# TODO / Backlog

## Lékařské dokumenty a kontroly

- Přidat e-mailové připomínky ke kontrolám.
  - Aplikace pošle připomínku před plánovanou kontrolou.
  - Uživatel si nastaví, kdy připomínku chce dostat.
  - K připomínce půjde odkázat související lékařskou zprávu.

## Provoz a bezpečnost dat

- Dopracovat zálohování databáze a obnovu ze zálohy.
  - Stanovit, kde bude uložená denní záloha databáze.
  - Připravit postup obnovy po chybě nebo smazání dat.
  - Ověřit, že záloha neobsahuje veřejně dostupná data ani uniklé konfigurační údaje.

- Navrhnout failover nebo nouzový provoz.
  - Cílem je minimalizovat riziko, že výpadek hostingu nebo databáze znamená ztrátu všech dat.
  - Pro levný provoz stačí jednoduchý plán: záloha mimo Endoru, export databáze a popsaný postup obnovy.
