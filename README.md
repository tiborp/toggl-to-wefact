# Toggl → WeFact

Een lokale PHP-app die **Toggl Track-uren omzet in WeFact-conceptfacturen**. Haal uren op, selecteer wat je wilt factureren en controleer de preview voordat je een concept aanmaakt.

**Eén Toggl-project levert één aparte conceptfactuur op**, ook als meerdere projecten bij dezelfde debiteur horen. Facturen worden nooit automatisch verzonden.

## Functionaliteit

- Uren ophalen voor een gekozen periode en filteren per project.
- WeFact-debiteuren koppelen en lokaal een uurtarief per Toggl-project instellen.
- Individuele tijdregistraties selecteren, met factuurregels samengevat of per omschrijving.
- De geselecteerde tijd per project één keer naar boven afronden op kwartieren; individuele registraties blijven ongewijzigd.
- Succesvolle facturatie lokaal registreren en de bijbehorende entries daarna in Toggl als `invoiced` taggen.
- Factuurhistorie en bescherming tegen dubbele facturatie.

## Requirements

- **PHP >= 8.2**; PHP **8.4** is getest en aanbevolen.
- PHP-extensies: PDO, pdo_sqlite, curl, mbstring en bcmath.
- Composer en een schrijfbare lokale opslagmap voor **SQLite**.
- Een Toggl Track API-token en WeFact API-key met passende toegang. Houd rekening met de WeFact IP-whitelist.

De app is bedoeld voor lokaal gebruik en wordt gebruikt op macOS. Node.js is alleen nodig voor de JavaScript-tests. De PHP-tests vereisen daarnaast de DOM-extensie (via Composer require-dev).

## Installatie en configuratie

Voer vanuit de projectmap uit:

```sh
composer install
cp .env.example .env
```

Vul in `.env` je `TOGGL_API_TOKEN` en `WEFACT_API_KEY` in. Hier stel je ook `APP_TIMEZONE` (standaard `Europe/Amsterdam`) en `DB_PATH` (standaard `storage/app.sqlite`) in. Credentials worden uitsluitend uit dit bestand gelezen.

Initialiseer de database **alleen bij een eerste installatie**:

```sh
chmod 600 .env
composer db:init -- --confirm-first-install
chmod 700 storage
```

De runtime-map `storage/` wordt waar nodig automatisch aangemaakt met afgeschermde rechten. Dit initialiseert geen database: daarvoor blijft het expliciete installatiecommando nodig.

Commit `.env`, databases en back-ups nooit.

## Starten

```sh
composer start
```

Open **<http://127.0.0.1:8080>**. Stop de server met Ctrl+C.

## Workflow

1. Vernieuw de projecten vanuit Toggl en debiteuren vanuit WeFact via de interface.
2. Stel bij **Projectkoppelingen** per project een debiteur en uurtarief in.
3. Kies bij **Uren** een periode, haal uren op en selecteer de registraties die je wilt factureren.
4. Werk de **factuur-preview** bij. Kies **Samengevat** of **Per omschrijving** en controleer tijd, tarief en bedrag.
5. Bereid de conceptfactuur voor en bevestig de aanmaak op het bevestigingsscherm.
6. Controleer het resultaat bij **Facturen** en in WeFact. Verzenden doe je zelf in WeFact.

## Beperkingen en veiligheid

- Uren ophalen kan maximaal **drie kalendermaanden terug**, vanwege de gebruikte Toggl API/accountbeperking. API-quota kunnen ophalen en tagging beperken.
- Running entries en reeds gefactureerde uren worden uitgesloten. Ook de historische tag `Billed by factuursturen.nl` wordt herkend.
- Een mislukte Toggl-tagsync maakt een succesvolle lokale factuurregistratie niet ongedaan. Openstaande tags kunnen via de interface opnieuw worden gesynchroniseerd.
- Bij een onzekere factuuraanmaak is eerst handmatige controle in WeFact nodig; maak niet blind opnieuw een factuur aan.
- Bedragen zijn exclusief btw. Controleer de btw op het WeFact-concept. Als **Per omschrijving** niet exact dezelfde centen oplevert, blokkeert de app de aanmaak en kun je **Samengevat** gebruiken.
- De app heeft geen login en is niet bedoeld voor publieke hosting. Gebruik uitsluitend `public/` als document root.

## Onderhoud en tests

Maak vóór updates een back-up en voer migrations expliciet uit; dagelijks starten maakt of migreert de database niet automatisch.

```sh
composer db:backup
composer migrate
composer test
```

Herstelcommando: `composer db:restore -- --file=/pad/naar/backup.sqlite --confirm-restore`. Stop eerst de app en zet een bestaande database veilig apart; herstel overschrijft niets. Bewaar `.env` afzonderlijk en beveiligd.

Voor gecontroleerde recovery van onzekere factuuracties is `composer db:reconcile` beschikbaar. Zie [PROJECT.md](PROJECT.md) voor technische achtergrond en herstelvoorwaarden.

De tests gebruiken geen echte API's. JavaScript-tests: `node tests/selection.cjs` en `node tests/daily.cjs`.
