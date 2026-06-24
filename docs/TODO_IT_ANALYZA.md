# IT analýza backlogu podle TODO

Tento dokument převádí položky z `TODO.md` do implementačního návrhu. Vychází ze současného stavu aplikace: dítě má detail, dokumentaci, ošetřující lékaře z NRPZS, export pro lékaře, Správu rodiny, Nastavení, auditní logy a základní PWA režim.

## 1. Plánování kontrol

### Očekávané použití

Rodič typicky potřebuje rychle uložit, že má dítě objednanou kontrolu: pediatr, ORL, zubař, oční, laboratorní odběry nebo návazná kontrola po nemoci. Často bude mít jen datum a lékaře, detail doplní později. Po proběhlé kontrole bude chtít zapsat závěr a připojit zprávu nebo fotku dokumentu.

### Umístění v GUI

- Detail dítěte: nové tlačítko `Kontroly` vedle `Lékaři`, `Dokumentace` a `Export pro lékaře`.
- Přehled: u každého dítěte zobrazit nejbližší plánovanou kontrolu, pokud existuje.
- Správa rodiny: nepatří sem jako hlavní agenda; maximálně přístupy. Kontroly jsou zdravotní data dítěte, tedy mají být v detailu dítěte.
- Dokumentace dítěte: u dokumentu umožnit vazbu na proběhlou kontrolu.

### Návrh fungování

Na detailu dítěte by tlačítko `Kontroly` otevřelo dialog nebo samostatnou sekci s kartami:

- `Plánované`
- `Proběhlé`
- `Zrušené`

Formulář nové kontroly:

- datum a čas,
- typ kontroly,
- lékař z přiřazených lékařů dítěte nebo výběr z NRPZS,
- stav: plánovaná, proběhlá, zrušená,
- poznámka před kontrolou,
- závěr po kontrole,
- doporučení,
- přílohy z dokumentace.

### Datový model

Nová tabulka `child_appointments`:

- `id`
- `child_id`
- `provider_id` nullable
- `title`
- `appointment_type`
- `scheduled_at`
- `status`: `planned`, `completed`, `cancelled`
- `pre_note`
- `result_note`
- `recommendation`
- `created_by_user_id`
- `created_at`
- `updated_at`

Vazba dokumentů na kontrolu:

- doplnit do `child_documents` sloupec `appointment_id` nullable, nebo vytvořit spojovací tabulku `appointment_documents`.
- Lepší je spojovací tabulka, protože jeden dokument může patřit ke kontrole i obecné dokumentaci.

### Implementační poznámky

- Kontrola není totéž co běžný zdravotní záznam v časové ose. Je to událost s plánem, stavem a přílohami.
- Proběhlá kontrola se může promítnout do časové osy jako typ záznamu, ale neměla by být schovaná jen mezi běžnými záznamy.
- Zrušené kontroly nemají být v exportu standardně, pouze volitelně.

### Vyhodnocení návrhu

Původní zadání odpovídá reálnému použití, ale je potřeba oddělit plánování od dokumentace. Nejlepší bude zavést samostatnou entitu `kontrola`, která umí mít dokumenty a lékaře. Nepřidávat to jen jako další `Typ péče`, protože by se tím ztratily plánované termíny, stavy a připomínky.

## 2. E-mailové připomínky ke kontrolám

### Očekávané použití

Rodič chce dostat připomínku den předem nebo několik hodin před kontrolou. U dítěte s akutním problémem může chtít připomenout i kontrolu za 2 dny. Připomínka má být stručná a bezpečná: kdo, kdy, ke komu, případně poznámka.

### Umístění v GUI

- U formuláře kontroly: pole `Připomenout` s volbami.
- Nastavení: globální preference připomínek pro uživatele.
- Detail kontroly: seznam naplánovaných připomínek a možnost vypnout.

### Návrh fungování

U každé kontroly nabídnout:

- bez připomínky,
- 2 hodiny předem,
- 1 den předem,
- vlastní datum a čas.

E-mail by obsahoval:

- název aplikace,
- jméno dítěte,
- datum a čas kontroly,
- lékaře nebo zařízení,
- krátkou poznámku,
- odkaz do aplikace.

Neposílat citlivé zdravotní detaily do e-mailu, pokud to není výslovně zapnuté. E-mail může být čten mimo zabezpečené prostředí.

### Datový model

Nová tabulka `appointment_reminders`:

- `id`
- `appointment_id`
- `user_id`
- `remind_at`
- `sent_at`
- `status`: `pending`, `sent`, `failed`, `cancelled`
- `last_error`
- `created_at`

Volitelně uživatelské preference:

- `user_notification_settings`
- `default_appointment_reminder_minutes`
- `email_enabled`

### Technické řešení odesílání

Na sdíleném hostingu jsou možnosti omezené. Praktické varianty:

- cron přes Endoru, pokud je dostupný,
- externí cron služba volající URL v aplikaci,
- ruční fallback: připomínky se odešlou při návštěvě aplikace, pokud je čas, ale to není spolehlivé.

Pro produkci doporučuji chráněný endpoint typu:

`/?r=cron_send_reminders&token=...`

Ten:

- vybere neodeslané připomínky `remind_at <= now`,
- pošle e-mail,
- zapíše audit/log,
- nastaví `sent_at`.

### Vyhodnocení návrhu

Připomínky dávají smysl, ale musí být navázané na samostatnou entitu kontroly. Bez plánovaných kontrol by připomínky byly jen obecné e-maily bez dobrého kontextu. Důležité je nepřenášet citlivé zdravotní informace do e-mailu automaticky.

## 3. Kopie EHIC

### Očekávané použití

Rodič chce mít u dítěte kopii evropského průkazu zdravotního pojištění pro cestování nebo řešení péče mimo ČR. Bude to většinou fotka přední a zadní strany nebo PDF.

### Umístění v GUI

Nejlépe v `Dokumentace` dítěte jako speciální typ dokumentu.

V dialogu Dokumentace přidat:

- pole `Typ dokumentu`,
- hodnoty: běžný dokument, lékařská zpráva, laboratorní výsledek, EHIC, očkování, jiný.

EHIC by se neměl dávat jako samostatná hlavní stránka. Pro uživatele je to typ dokumentu dítěte, ne separátní agenda.

### Návrh fungování

Při nahrání dokumentu:

- rodič vybere typ `EHIC`,
- aplikace označí dokument jako citlivý,
- zobrazí varování, že jde o osobní doklad,
- uloží se do stejného chráněného úložiště jako ostatní dokumenty.

V seznamu dokumentů:

- EHIC zobrazit štítkem `Citlivé`,
- defaultně jej nezahrnovat do exportu pro lékaře,
- zahrnutí do exportu povolit jen explicitní volbou.

### Datový model

Rozšířit `child_documents`:

- `document_type`: `general`, `medical_report`, `lab_result`, `ehic`, `vaccination`, `other`
- `is_sensitive` boolean

Volitelně:

- `valid_until`, pokud by se později sledovala platnost dokladu.

### Bezpečnost

- Ukládat mimo `public`.
- Stahování jen přes autorizovanou routu.
- Auditovat stažení EHIC.
- V exportu EHIC nezahrnovat automaticky.

### Vyhodnocení návrhu

Položka v TODO odpovídá potřebě, ale implementačně je lepší neudělat samostatnou funkci `EHIC`, nýbrž rozšířit dokumenty o typy a citlivost. To je jednodušší, konzistentní a lépe použitelné.

## 4. OCR pro nahrané skeny

### Očekávané použití

Rodič vyfotí lékařskou zprávu a aplikace se pokusí přečíst text. Rodič text zkontroluje, případně opraví, a pak ho může použít v poznámce ke kontrole nebo exportu.

### Umístění v GUI

V `Dokumentace` dítěte:

- u dokumentu tlačítko `Rozpoznat text`,
- u dokumentu sekce `Rozpoznaný text`,
- možnost `Vložit do kontroly` nebo `Připojit ke kontrole`.

Pokud bude dokument nahraný jako součást kontroly, OCR text se zobrazí přímo v detailu kontroly.

### Návrh fungování

Po nahrání dokumentu:

1. Dokument se uloží.
2. Aplikace nastaví OCR stav `čeká na zpracování`.
3. OCR proces dokument zpracuje.
4. Výsledek se uloží jako editovatelný text.
5. Rodič text zkontroluje a potvrdí.

Stavy OCR:

- `not_requested`
- `queued`
- `processing`
- `done`
- `failed`

### Datový model

Rozšířit `child_documents`:

- `ocr_status`
- `ocr_text`
- `ocr_error`
- `ocr_processed_at`
- `ocr_confirmed_at`

Pokud bude OCR později složitější, lze udělat samostatnou tabulku `document_ocr_results`.

### Technické možnosti

Varianta levná a jednoduchá:

- OCR jako ruční/externí proces mimo Endoru, zatím jen uložit pole pro text.

Varianta automatická:

- OCR API služba,
- upload dokumentu do API,
- uložit výsledek,
- řešit cenu a ochranu dat.

Varianta lokální server:

- Tesseract OCR mimo Endoru,
- vyžaduje vlastní VPS nebo worker.

Na Endoře nelze realisticky spoléhat na instalaci Tesseractu nebo background worker.

### Bezpečnost a souhlas

OCR znamená posílání zdravotnických dokumentů do další služby, pokud se použije externí API. UI musí jasně říct:

- kam se dokument posílá,
- že jde o citlivá data,
- zda se text ukládá,
- kdo k němu má přístup.

### Vyhodnocení návrhu

OCR dává smysl, ale není to první krok. Nejprve je vhodné dodělat typy dokumentů a kontroly. Automatické OCR přes externí službu by mělo přijít až po rozhodnutí o ochraně dat a ceně. Pro MVP stačí pole `Rozpoznaný text` editovatelné ručně a později přidat automatické OCR.

## 5. Rozšíření exportu pro lékaře

### Očekávané použití

Rodič jde s dítětem k lékaři a potřebuje rychle vytisknout nebo ukázat přehled. Lékař nechce kompletní databázi života dítěte, ale relevantní výsek: poslední dny nemoci, teploty, léky, příznaky, důležité alergie, váhu, ošetřující lékaře a případné nedávné dokumenty nebo kontroly.

### Umístění v GUI

Detail dítěte:

- tlačítko `Export pro lékaře` zůstává,
- před exportem se zobrazí jednoduché nastavení exportu.

Možné UI:

- stránka `Připravit export`,
- nebo horní formulář přímo na exportní stránce.

Formulář parametrů:

- období: 24 h, 72 h, 7 dní, 30 dní, vlastní,
- zahrnout teploty,
- zahrnout léky,
- zahrnout příznaky,
- zahrnout péči,
- zahrnout kontroly,
- zahrnout dokumenty,
- zahrnout EHIC,
- rozsah detailu: stručně / podrobně.

### Návrh fungování

Výchozí export:

- posledních 72 hodin,
- teploty,
- léky,
- příznaky,
- alergie,
- váha,
- ošetřující lékaři,
- graf 72 hodin,
- nedávné proběhlé kontroly pouze pokud existují,
- dokumenty pouze jako seznam, ne přílohy.

Rozšířený export:

- podle parametrů zahrne dokumenty a kontroly,
- dokumenty z poslední doby zobrazí jako seznam s názvem, datem, typem a lékařem,
- EHIC jen po explicitním zaškrtnutí.

### Datový model

Není nutně potřeba nová tabulka pro samotný export. Parametry mohou být GET parametry:

- `from`
- `to`
- `include_temperatures`
- `include_medications`
- `include_symptoms`
- `include_care`
- `include_appointments`
- `include_documents`
- `include_ehic`
- `detail_level`

Volitelně později:

- `export_presets`, pokud si rodina bude ukládat vlastní šablony.

### Dokumenty v exportu

Na sdíleném hostingu je praktičtější:

- pro tiskový HTML export zobrazit seznam dokumentů,
- pro stažení dokumentu dát autorizovaný odkaz,
- přílohy do jednoho PDF řešit až později.

Automatické slučování dokumentů do PDF by bylo větší technická etapa.

### Vyhodnocení návrhu

TODO je správně formulované: export má mít parametry. Je důležité nepřidat do výchozího exportu všechno, protože lékař by dostal šum. Výchozí export má být stručný, ale ručně rozšiřitelný.

## 6. Zálohování databáze a obnova

### Očekávané použití

Uživatel očekává, že nepřijde o data dítěte. Technicky jde o kombinaci pravidelné zálohy databáze, zálohy nahraných dokumentů a popsaného postupu obnovy.

### Umístění v GUI

Tato funkce nemusí být v běžném rodičovském UI.

Možnosti:

- `Nastavení` pro administrátora aplikace: stav poslední zálohy.
- Pro běžného rodiče jen případný export vlastních dat později.

V současné aplikaci není role provozního administrátora, jen role v rodině. Proto doporučuji řešit zálohování nejdřív provozně mimo GUI.

### Rozsah zálohy

Zálohovat:

- MySQL/MariaDB databázi,
- složku `var/uploads`,
- produkční `config/config.php` mimo veřejný repozitář,
- dokumentaci k obnově.

Nezálohovat do veřejného GitHubu:

- hesla,
- produkční config,
- uploady,
- dump databáze.

### Technické řešení

Levná varianta:

- denní dump databáze přes cron nebo Endora nástroj,
- stažení dumpu mimo Endoru,
- komprimovat a šifrovat,
- uchovávat posledních 7 denních a 4 týdenní zálohy.

Pokud Endora cron není použitelný:

- externí cron služba,
- nebo ruční pravidelný export přes hosting.

### Obnova

Popsat postup:

1. Zastavit aplikaci nebo přepnout do údržby.
2. Obnovit databázi.
3. Obnovit `var/uploads`.
4. Ověřit login, přehled dítěte, dokumenty a export.
5. Zapsat audit provozní obnovy mimo aplikaci.

### Vyhodnocení návrhu

TODO je správně, ale formulace `zálohování databáze` je moc úzká. Musí zahrnovat i nahrané dokumenty, jinak se obnoví metadata bez souborů. GUI není první priorita; první priorita je spolehlivý provozní postup.

## 7. Failover nebo nouzový provoz

### Očekávané použití

Při výpadku hostingu rodič nechce přijít o data. U této aplikace je důležitější obnova dat než vysoká dostupnost v reálném čase. Aplikace není urgentní nemocniční systém, ale zdravotní deník.

### Umístění v GUI

Failover jako takový nepatří do rodičovského UI.

Vhodné doplnit do dokumentace:

- kde jsou zálohy,
- jak rychle lze obnovit,
- kdo má přístup,
- jak se pozná poslední úspěšná záloha.

### Technické varianty

Nízká cena:

- Endora jako primární hosting,
- pravidelný dump databáze,
- kopie uploadů mimo Endoru,
- připravený postup obnovy na jiný hosting.

Střední varianta:

- VPS s automatickými snapshoty,
- object storage pro uploady,
- monitoring dostupnosti.

Vyšší varianta:

- managed DB,
- oddělené úložiště dokumentů,
- CI/CD deploy,
- monitoring a alerting.

### Doporučení pro tento projekt

Pro aktuální fázi je rozumné:

- nepřidávat složitý live failover,
- udělat pravidelné zálohy,
- připravit obnovu na alternativní hosting,
- mít lokální kopii kódu a dokumentovaný deploy.

### Vyhodnocení návrhu

Původní požadavek `failover` je pochopitelný, ale pro cenu a složitost je lepší začít `disaster recovery` plánem. Skutečný failover by byl dražší a pro tento typ aplikace zatím nepřináší odpovídající hodnotu.

## 8. Přepracování Typů péče

### Očekávané použití

Rodič na mobilu nechce přemýšlet nad technickými typy záznamů. Chce rychle zadat:

- teplotu,
- lék,
- příznaky,
- pití,
- spánek,
- stolici,
- jídlo,
- obecnou poznámku,
- jinou péči.

Současné `Typy péče` jsou spíše administrace číselníku. To běžnému rodiči moc nepomáhá.

### Umístění v GUI

Doporučená změna:

- odstranit hlavní navigační položku `Typy péče`,
- přesunout správu vlastních rychlých položek do `Nastavení` nebo do `Správa rodiny`,
- na detailu dítěte zobrazit rychlé záznamy jako praktické akce.

Konkrétně:

- Detail dítěte: `Rychlý zápis`
- Nastavení nebo Správa rodiny: `Vlastní položky rychlého zápisu`

### Návrh fungování

Systémové rychlé položky:

- Teplota
- Lék
- Příznaky
- Poznámka
- Péče

Volitelné systémové položky:

- Pití
- Spánek
- Stolice
- Jídlo

Uživatelské položky:

- rodina si přidá vlastní položku,
- položka se zobrazí v rychlém zápisu,
- lze ji skrýt, ale ne mazat historická data.

### Datový model

Současná tabulka `record_types` se dá použít, ale potřebuje rozlišit:

- `is_system`
- `is_quick`
- `sort_order`
- `icon`
- `input_schema`

Příklady `input_schema`:

- `temperature`: číslo + čas + poznámka
- `boolean_note`: jen poznámka
- `scale_note`: škála + poznámka
- `text_note`: text

Pokud by se to rozrostlo, dává smysl nová tabulka `quick_record_templates`.

### UI logika

Detail dítěte by neměl ukazovat dlouhé formuláře najednou. Lepší:

- řada velkých rychlých tlačítek,
- po tapnutí se otevře kompaktní formulář,
- po uložení návrat na detail dítěte.

Na mobilu má být ideální cesta:

1. otevřít dítě,
2. tapnout typ záznamu,
3. zadat hodnotu,
4. uložit.

### Vyhodnocení návrhu

TODO velmi dobře pojmenovává problém. Současný koncept `Typy péče` je moc technický. Doporučení je změnit mentální model z `správa typů` na `rychlé záznamy`. Administrace typů má ustoupit do pozadí.

## 9. Přejmenování Vlastník na Administrátor rodiny

### Očekávané použití

Slovo `Vlastník` je technické a u rodinné aplikace působí tvrdě. `Administrátor rodiny` lépe vysvětluje, že uživatel má správní oprávnění, ne že `vlastní` rodinu nebo dítě.

### Umístění v GUI

Změnit všude:

- Správa rodiny,
- Nastavení,
- přístupy k dětem,
- chybové hlášky,
- e-mailové texty,
- export, pokud roli někde zobrazuje,
- dokumentace.

### Návrh fungování

Technicky může role v databázi zůstat `OWNER`. Není nutná migrace hodnot.

Změnit pouze prezentační vrstvu:

- `role_label('OWNER')` vrací `Administrátor rodiny`,
- texty hlášek nepoužívají `vlastník`,
- interní funkce `require_owner()` může zůstat, ale uživatelské texty budou jiné.

### Dopad na oprávnění

Oprávnění se nemění:

- administrátor může spravovat rodinu,
- zvát a odebírat rodiče,
- nastavovat přístupy k dětem,
- mazat děti,
- zrušit rodinu.

Rodič:

- může pracovat s dětmi, ke kterým má přístup,
- nemůže zrušit rodinu,
- nemůže spravovat ostatní rodiče, pokud se to výslovně nezmění.

### Vyhodnocení návrhu

Toto je jednoduchá a správná změna. Doporučuji ji udělat dříve než větší refaktory, protože sjednotí jazyk aplikace a sníží zmatení uživatelů.

## Doporučené pořadí implementace

1. Přejmenovat `Vlastník` na `Administrátor rodiny`.
2. Přepracovat `Typy péče` na praktické rychlé záznamy.
3. Přidat typy dokumentů a citlivost dokumentu, včetně EHIC jako typu dokumentu.
4. Přidat plánování kontrol.
5. Navázat dokumenty na kontroly.
6. Rozšířit export pro lékaře o parametry, kontroly a dokumenty.
7. Přidat připomínky kontrol přes cron nebo externí trigger.
8. Připravit zálohování databáze a uploadů.
9. Připravit nouzovou obnovu na jiný hosting.
10. OCR řešit až po stabilizaci dokumentů a kontrol.

## Shrnutí dopadu na architekturu

Největší změna není v jednotlivých formulářích, ale v modelu aplikace:

- `Dokument` už existuje a má se rozšířit o typ, citlivost a vazbu na kontrolu.
- `Kontrola` má vzniknout jako samostatná entita, ne jako obyčejný záznam péče.
- `Rychlý záznam` má být produktový koncept nad `record_types`, ne jen technický číselník.
- `Export pro lékaře` má být parametrizovaný pohled, ne jen jedna pevná tisková stránka.
- `Zálohování` musí zahrnovat databázi i uploadované soubory.

Tím se aplikace posune z jednoduchého zdravotního deníku na použitelný rodičovský zdravotní archiv, ale pořád bez zbytečné složitosti v běžném mobilním použití.
