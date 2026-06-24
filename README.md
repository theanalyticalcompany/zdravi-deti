# Zdraví dětí

MVP webové aplikace pro rodinný zdravotní deník dětí.

Aplikace je záměrně napsaná jako lehký PHP monolit s MySQL/MariaDB databází. Nepotřebuje Node.js, Composer ani build krok, takže ji lze provozovat i na levnějším sdíleném hostingu s PHP a databází.

## Co aplikace umí

- registrace a přihlášení e-mailem a heslem,
- obnova zapomenutého hesla přes jednorázový e-mailový odkaz,
- volitelné Google OAuth přihlášení,
- správa aktivních zařízení a odhlášení ostatních sessions,
- automatické založení rodiny při registraci,
- role vlastníka rodiny a dalších rodičů,
- pozvánky rodičů e-mailem,
- správa dětí a přístupů rodičů,
- jednorázový import poskytovatelů zdravotních služeb z NRPZS/ÚZIS,
- přiřazení ošetřujících lékařů k dítěti z lokálního číselníku,
- evidence váhy, alergií a dopočítání věku dítěte,
- evidence teplot, podaných léků, příznaků a záznamů péče,
- rychlé zadání léku a příznaků na detailu dítěte,
- editace a mazání zdravotních záznamů,
- předpřipravený číselník vybraných dětských léků s dávkovací informací,
- auditní log důležitých akcí a rate limiting pro přihlášení a obnovu hesla,
- e-mailový transport přes lokální log, `mail()`, SMTP nebo obecné API,
- automatický tmavý režim podle nastavení prohlížeče,
- PWA instalace na iOS a Android přes prohlížeč,
- přehled všech dětí na hlavní stránce s grafem za posledních 72 hodin,
- detail dítěte se souhrnem a časovou osou,
- tiskově optimalizovaný export pro lékaře,
- CSRF ochrana formulářů, hashování hesel a serverové ověření přístupů.

## Struktura

- `public/` - web root aplikace.
- `app/` - aplikační logika, routy, repozitáře a šablony.
- `config/` - konfigurační šablona. Skutečný `config.php` se do repozitáře neukládá.
- `database/` - databázové schema pro MySQL/MariaDB a SQLite pro lokální vývoj.
- `docs/` - obecná architektura a provozní návod.
- `tools/init_sqlite.php` - lokální pomocný skript pro vytvoření SQLite databáze.

## Rychlé lokální spuštění

1. Nainstalujte PHP 8.1+ se zapnutým rozšířením PDO SQLite.
2. Zkopírujte `config/config.example.php` na `config/config.php`.
3. Pro lokální režim nastavte v `config/config.php` SQLite DSN, například `sqlite:../var/local.sqlite`.
4. Pokud chcete mít v lokální databázi rovnou lékaře z NRPZS, uložte CSV export jako `database/seed/nrpzs_providers.csv` nebo ponechte lokální soubor `var/nrpzs/export-2026-06.csv`.
5. Spusťte `php tools/init_sqlite.php`.
6. Spusťte vestavěný server: `php -S localhost:8080 -t public`.
7. Otevřete `http://localhost:8080`.

Pro produkci použijte MySQL/MariaDB a importujte `database/schema.sql`.

Podrobnější postup je v `docs/NASAZENI_A_PROVOZ.md`.

## Kontroly

- Syntax všech PHP souborů: `php -l app/routes.php` nebo celý strom podle GitHub Actions workflow.
- Smoke test nad dočasnou SQLite databází: `php tests/smoke.php`.
- Ruční scénáře pro klíčové flow jsou v `tests/SCENARIOS.md`.
- GitHub Actions obsahují PHP kontrolu a bezpečnostní secret scan přes Gitleaks.

## PWA

Aplikaci lze na iOS a Androidu přidat na plochu z prohlížeče. PWA režim cachuje jen statické soubory, ikony, manifest a offline obrazovku; zdravotní data a přihlášené stránky se do offline cache neukládají.
