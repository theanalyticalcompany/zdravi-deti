# Testovací scénáře

## Přihlášení a rate limiting

1. Otevřít přihlášení a zadat neplatné heslo pro existující účet.
2. Ověřit obecnou chybu bez detailu, zda účet existuje.
3. Opakovat pětkrát a ověřit dočasné zablokování dalších pokusů.
4. Přihlásit se správným heslem po vypršení limitu a ověřit návrat na přehled.

## Reset hesla

1. Zadání neznámého e-mailu musí vrátit stejnou hlášku jako známý e-mail.
2. Zadání registrovaného e-mailu musí vytvořit e-mail s odkazem pro reset.
3. Odkaz musí jít použít jen jednou a jen do expirace.
4. Nové heslo kratší než 10 znaků nesmí projít.

## Pozvánky rodičů

1. Pozvat neexistující e-mail a ověřit odeslanou pozvánku.
2. Registrovat pozvaný e-mail a ověřit oznámení původnímu rodiči.
3. Pozvat již existující e-mail a ověřit okamžité přidání do rodiny.
4. Odebrat rodiče, vlastník musí zůstat neodebratelný.

## Záznamy dítěte

1. Na detailu dítěte rychle uložit podání předpřipraveného léku.
2. Ověřit zobrazení dávkovací informace a odkazu na SÚKL.
3. Rychle uložit příznaky, upravit je a smazat.
4. Ověřit, že timeline defaultně zobrazuje 72 hodin.

## Auditní logy

1. Po přihlášení, neúspěšném přihlášení, resetu, pozvánce, změně dítěte a smazání záznamu ověřit řádky v `audit_logs`.
2. Zkontrolovat, že audit neukládá hesla ani resetovací tokeny.

## E-mailový transport

1. Lokálně ponechat `mail.enabled=false` a ověřit zápis do `var/mail.log`.
2. Na produkci nastavit `mail.transport=smtp` nebo `mail.transport=api`.
3. Ověřit doručení resetu hesla a pozvánky.
