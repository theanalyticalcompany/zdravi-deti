# NRPZS seed data

Sem lze uložit veřejný CSV export poskytovatelů zdravotních služeb z NRPZS/ÚZIS pod názvem `nrpzs_providers.csv`.

Skript `tools/init_sqlite.php` při vytvoření lokální SQLite databáze automaticky hledá:

1. `database/seed/nrpzs_providers.csv`
2. `var/nrpzs/export-2026-06.csv`

Pokud soubor najde, naimportuje poskytovatele rovnou při inicializaci databáze.
