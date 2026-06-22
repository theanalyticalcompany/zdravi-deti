# Nasazení a provoz

Tento návod popisuje obecné spuštění aplikace bez konkrétních hesel, domén a osobních údajů.

## Doporučený hosting

Pro MVP stačí běžný PHP hosting s databází. Prioritou je nízká cena, jednoduchý provoz a možnost HTTPS.

Minimální požadavky:

- PHP 8.1 nebo novější,
- PDO MySQL pro produkci,
- MySQL nebo MariaDB databáze,
- Apache rewrite přes `.htaccess`,
- HTTPS/TLS certifikát,
- pravidelné zálohy databáze.

Na lokální vývoj lze použít PHP se SQLite.

## Co koupit nebo zapnout

Minimum:

- vlastní doménu nebo subdoménu,
- PHP hosting s MySQL/MariaDB,
- SSL certifikát,
- automatické zálohy databáze.

Volitelné:

- samostatnou e-mailovou schránku pro technické zprávy,
- Google OAuth aplikaci, pokud chcete přihlášení přes Google.

## Produkční instalace

1. V administraci hostingu vytvořte databázi.
2. Importujte `database/schema.sql`.
3. Nahrajte adresáře `app`, `config`, `database`, `docs` a `public`.
4. Ideálně nastavte web root domény na adresář `public`.
5. Pokud web root nejde změnit, ponechte veřejný pouze vstupní `index.php` a `.htaccess` a zakažte přímý přístup do neveřejných adresářů.
6. Zkopírujte `config/config.example.php` na `config/config.php`.
7. V `config/config.php` nastavte:

```php
'base_url' => 'https://vase-domena.cz',
'dsn' => 'mysql:host=DB_HOST;dbname=DB_NAME;charset=utf8mb4',
'user' => 'DB_USER',
'password' => 'DB_PASSWORD',
```

8. Otevřete aplikaci v prohlížeči, vytvořte první účet a založte dítě.

Soubor `config/config.php` nikdy neukládejte do veřejného repozitáře.

## Lokální vývoj

Nejjednodušší varianta:

1. Nainstalujte PHP 8.1+ s rozšířeními PDO a SQLite.
2. Zkopírujte `config/config.example.php` na `config/config.php`.
3. Nastavte SQLite DSN, například:

```php
'dsn' => 'sqlite:' . __DIR__ . '/../var/local.sqlite',
'user' => null,
'password' => null,
```

4. Vytvořte lokální databázi:

```bash
php tools/init_sqlite.php
```

5. Spusťte vývojový server:

```bash
php -S localhost:8080 -t public
```

Vestavěný PHP server je vhodný jen pro vývoj.

## Google přihlášení

1. V Google Cloud Console vytvořte OAuth Client typu Web application.
2. Přidejte redirect URI:

```text
https://vase-domena.cz/?r=google_callback
```

3. Do `config/config.php` doplňte `client_id`, `client_secret` a `redirect_uri`.

Pokud hodnoty necháte prázdné, tlačítko Google přihlášení se nezobrazí.

## Export PDF

Export pro lékaře je tiskově optimalizovaná HTML stránka:

1. V detailu dítěte klikněte na `Export pro lékaře`.
2. Klikněte na `Uložit nebo tisknout PDF`.
3. V prohlížeči zvolte `Uložit jako PDF`.

Serverová generace PDF není pro MVP nutná a na sdíleném hostingu by zbytečně zvyšovala paměťové nároky.

## Zálohy

Doporučení:

- zapnout automatické zálohy hostingu,
- držet retenci alespoň 7 až 30 dní,
- občas ověřit obnovu na testovací databázi,
- před většími změnami udělat manuální export databáze.
