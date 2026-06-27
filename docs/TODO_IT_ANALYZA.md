# IT analýza zbývajícího backlogu

Tento dokument je srovnaný s aktuálním `TODO.md` a popisuje jen položky, které ještě zbývá navrhnout nebo implementovat.

## 1. E-mailové připomínky ke kontrolám

### Očekávané použití

Rodič má u dítěte naplánovanou kontrolu a chce dostat připomínku předem. Typické scénáře jsou preventivní prohlídka, kontrola po nemoci, zubař, oční nebo specialista. Připomínka má pomoct nezapomenout na termín, ne přenášet citlivé zdravotní detaily e-mailem.

### Zařazení v GUI

Připomínky patří přímo ke kontrole:

- v dialogu nebo stránce `Kontroly` u dítěte,
- u formuláře nové nebo editované kontroly jako pole `Připomenout`,
- v detailu kontroly jako přehled naplánovaných připomínek,
- volitelně v `Nastavení` jako výchozí preference pro celý účet.

Do `Správy rodiny` nepatří, protože jde o osobní notifikaci konkrétního rodiče, ne o rodinnou administraci.

### Návrh fungování

U každé kontroly nabídnout jednoduché volby:

- bez připomínky,
- 2 hodiny předem,
- 1 den předem,
- vlastní datum a čas.

E-mail má obsahovat jen bezpečný rozsah:

- název aplikace,
- jméno dítěte,
- datum a čas kontroly,
- název lékaře nebo zařízení, pokud je vyplněný,
- odkaz do aplikace.

Citlivé zdravotní poznámky neposílat automaticky. Pokud by se později mělo posílat víc detailů, musí to být samostatná volba.

### Datový model

Navržená tabulka `appointment_reminders`:

- `id`
- `appointment_id`
- `user_id`
- `remind_at`
- `sent_at`
- `status`: `pending`, `sent`, `failed`, `cancelled`
- `last_error`
- `created_at`
- `updated_at`

Volitelně později `user_notification_settings`:

- `user_id`
- `default_appointment_reminder_minutes`
- `email_enabled`

### Technická realizace

Na Endoře je nejpraktičtější chráněný cron endpoint:

`/?r=cron_send_reminders&token=...`

Ten provede:

- výběr připomínek se stavem `pending`, kde `remind_at <= now`,
- odeslání e-mailu přes stejný mailer jako pozvánky a reset hesla,
- zápis výsledku do databáze,
- auditní log pro odeslanou nebo selhanou připomínku.

Trigger může být:

- cron na hostingu, pokud je dostupný,
- externí cron služba,
- nouzově opportunistické spuštění při návštěvě aplikace, ale to není spolehlivé.

### Rizika a kontroly

- Opakované poslání stejné připomínky: řešit stavem a transakcí.
- Citlivý obsah v e-mailu: držet e-mail stručný.
- Neplatný e-mail rodiče: zapsat chybu, neblokovat aplikaci.
- Změna termínu kontroly: přepočítat nebo zrušit existující neodeslané připomínky.

## 2. Zálohování databáze a obnova

### Očekávané použití

Uživatel očekává, že nepřijde o zdravotní historii dítěte ani o nahrané dokumenty. Záloha proto nesmí být jen dump databáze, ale musí zahrnovat také uploadované soubory a provozní konfiguraci uloženou mimo veřejný repozitář.

### Zařazení v GUI

První verze nemusí být v rodičovském UI. Jde hlavně o provozní funkci.

Do budoucna lze přidat administrační stránku mimo běžnou rodinnou roli:

- datum poslední úspěšné zálohy,
- počet uchovaných záloh,
- poslední chyba,
- odkaz na interní obnovovací postup.

Běžný rodič by měl mít spíš možnost exportu vlastních dat, ne přístup k provozním zálohám celé aplikace.

### Rozsah zálohy

Zálohovat:

- databázi,
- složku s uploady,
- produkční konfiguraci mimo GitHub,
- dokumentaci obnovy,
- informaci o verzi aplikace nebo commitu.

Neukládat do veřejného GitHubu:

- dump databáze,
- uploadované dokumenty,
- hesla,
- `.env` nebo produkční `config.php`,
- zálohy s osobními údaji.

### Technická realizace

Levná varianta pro aktuální provoz:

- denní dump databáze,
- pravidelná kopie uploadů mimo Endoru,
- komprese,
- šifrování archivu,
- retenční politika například 7 denních a 4 týdenní zálohy.

Možná úložiště:

- lokální šifrovaná kopie stažená mimo hosting,
- S3 kompatibilní object storage,
- šifrovaný cloud disk,
- jiný hostingový prostor oddělený od produkce.

### Obnova

Připravit runbook:

1. Přepnout aplikaci do údržby.
2. Obnovit databázi ze zvoleného dumpu.
3. Obnovit uploadované soubory.
4. Zkontrolovat konfiguraci.
5. Ověřit login, přehled, detail dítěte, dokumenty, EHIC a export.
6. Zapsat datum a výsledek obnovy do provozního záznamu.

### Rizika a kontroly

- Záloha bez uploadů je nedostatečná.
- Neověřená záloha je jen pocit bezpečí, ne skutečná obnova.
- Zálohy obsahují citlivá data, musí být šifrované.
- Přístup k zálohám musí být oddělený od běžného hostingu.

## 3. Failover a nouzový provoz

### Očekávané použití

Při výpadku hostingu nebo databáze je hlavní cíl nepřijít o data a umět aplikaci obnovit v rozumném čase. Pro tuto fázi aplikace není nutné drahé aktivní vysokodostupnostní řešení.

### Zařazení v GUI

Failover nepatří do běžného UI. Patří do provozní dokumentace a kontrolního checklistu.

V aplikaci lze později doplnit jen stavovou informaci pro administrátora provozu:

- datum poslední zálohy,
- stav posledního ověření obnovy,
- kontakt nebo postup pro nouzový režim.

### Doporučený model

Pro aktuální projekt dává smysl disaster recovery plán, ne plný live failover:

- Endora jako primární hosting,
- pravidelné zálohy databáze a uploadů mimo Endoru,
- připravený alternativní hosting nebo VPS,
- dokumentovaný postup nasazení aplikace z GitHubu,
- uložený postup vytvoření konfigurace bez zveřejnění tajných údajů.

### RTO a RPO

Navržené provozní cíle:

- RPO: ztráta maximálně posledních 24 hodin dat při denní záloze.
- RTO: obnovení služby v řádu hodin, pokud je dostupná záloha a alternativní hosting.

Pokud budou požadavky přísnější, je potřeba přejít na dražší architekturu s managed databází, odděleným úložištěm a automatizovaným deployem.

### Technická realizace

Minimální varianta:

- udržovat GitHub jako čistý zdroj kódu bez tajných údajů,
- mít šablonu konfigurace,
- zálohovat DB a uploady mimo Endoru,
- popsat restore do nového prostoru,
- pravidelně testovat obnovu na lokálním nebo testovacím prostředí.

Střední varianta:

- VPS,
- automatické snapshoty,
- object storage pro soubory,
- monitoring dostupnosti,
- alert při chybě cron záloh.

### Rizika a kontroly

- Failover bez pravidelného restore testu je nespolehlivý.
- Zálohy na stejném hostingu neřeší výpadek hostingu.
- Příliš složitá infrastruktura zvedne cenu a údržbu.
- Při obnově je nutné chránit produkční konfiguraci a osobní data.

## Doporučené pořadí

1. Zálohování databáze a uploadů.
2. Ověřený restore test.
3. Runbook nouzového provozu.
4. E-mailové připomínky ke kontrolám.
5. Monitoring cronů a záloh.

## Shrnutí dopadu na architekturu

Zbývající backlog už není primárně o novém rodičovském UI, ale o provozní spolehlivosti a notifikacích:

- připomínky vyžadují plánovač nebo cron endpoint,
- zálohy musí zahrnout databázi i uploady,
- nouzový provoz potřebuje dokumentovaný restore postup,
- běžné UI má zůstat jednoduché a nemá uživatele zatěžovat provozními detaily.
