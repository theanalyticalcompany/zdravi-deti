# Architektura a UI rozhodnutí

## Architektura

Aplikace je implementovaná jako modulární monolit:

- PHP server-rendered web,
- MySQL/MariaDB databáze v produkci,
- SQLite varianta pro lokální vývoj,
- PDO prepared statements,
- PHP sessions,
- bez externích balíčků a bez build kroku.

Tento přístup snižuje provozní náklady a usnadňuje nasazení na sdílený hosting.

## Doménové moduly

- `users`, `families`, `family_members` - identita a rodina,
- `children`, `child_access` - děti a přístupy,
- `health_records` - společný základ zdravotních událostí,
- `temperature_records` - detail teploty,
- `medications`, `medication_administrations` - číselník a podání léků,
- `record_types` - systémové typy a vlastní typy péče,
- `family_invitations` - pozvánky dalších rodičů.

## Role

- Administrátor rodiny může spravovat rodinu, děti, rodiče a zrušit rodinu.
- Další rodiče mohou pracovat se záznamy a dětmi podle přiděleného přístupu.
- Zrušení rodiny smaže rodinu, související záznamy a odebere členství ostatních rodičů.

## UI směr

Návrh je minimalistický a funkcionalistický:

- světlé neutrální pozadí,
- bílá pracovní plocha,
- tlumená primární barva,
- kontrastní barvy jen pro zdravotně významné hodnoty a důležité akce,
- karty pouze pro samostatné informační jednotky,
- tabulky a formuláře bez dekorativních prvků,
- responzivní layout bez nutnosti mobilní aplikace.

## Bezpečnostní hranice

- Hesla se ukládají přes `password_hash`.
- Všechny zápisové formuláře mají CSRF token.
- Přístup k dítěti se ověřuje serverově přes `child_access`.
- Vybraný lék a typ péče se ověřuje proti aktuální rodině.
- Mazání používá fyzické odstranění podle aktuálních požadavků MVP.
- Zdravotní hodnoty se neposílají v URL, aby se nedostávaly do běžných access logů.
