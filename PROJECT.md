# Toggl → WeFact — architectuur en invarianten

Dit document beschrijft de actuele implementatie. [README.md](README.md) bevat installatie, bediening, updates, back-up en herstel. Historische tussenmodellen zijn geen uitgangspunt voor nieuw werk.

## Scope en ontwikkelregels

Persoonlijke lokale macOS-tool voor één gebruiker: Toggl-uren selecteren, per project een WeFact-concept maken, lokaal registreren en daarna invoiced-tagging. Geen automatisch verzenden, publieke SaaS of gebruikersbeheer.

Fases 1–5, data/recovery (6A) en dagelijkse bediening (6B) zijn geïmplementeerd. Fase 6C betreft onderhoud, installatie en documentatie; dit geeft geen opdracht voor nieuwe facturatiefunctionaliteit.

Lees PROJECT.md vóór implementatie. Bouw uitsluitend expliciet gevraagde scope. Behoud veiligheid en auditgegevens. Geen grote frameworks/dependencies zonder noodzaak en uitleg. Geen externe writes tijdens ontwikkeling zonder expliciete opdracht. Tests uitsluitend met stubs/testdatabases. Credentials nooit in code, Git, documentatie, algemene backups of diagnostiek.

## Architectuur

PHP 8.2+, Composer, PDO SQLite, curl, mbstring en bcmath. Server-side HTML, eenvoudige CSS en progressive enhancement zonder JS-library.

| Onderdeel | Verantwoordelijkheid |
| --- | --- |
| bootstrap.php | Configuratie, timezone, bestaande database openen en actueel schema valideren; geen init/migrate |
| public/index.php, src/Http | Routes en wiring van controllers/services |
| src/Config | .env-parser; algemene environment overrides, credentials uitsluitend .env |
| src/Database | PDO, migrations, validatie, init, consistente backup en no-clobber restore |
| src/Toggl | Client/transport, quota, datumrange, reader, entryclassificatie en uren/status |
| src/WeFact | Debiteuren/cache, concept-write, decimalserialisatie en diagnostiek |
| src/Project | Broncatalogus, mappings, integer-tarieven en configuratievalidatie |
| src/Invoice | Preview/berekening/payload, confirmation, reserveringen, historie, recovery en tagsync |
| src/View, templates, public/assets | Escaping, Nederlandse presentatie en progressieve interactie |
| bin/admin.php, bin/migrate.php | Expliciet lokaal onderhoud; Composer-aliases in composer.json |
| tests | PHP- en JS-regressies zonder live API |

Actieve routes: /, /toggl, /wefact, /projecten, /uren, /factuur-preview, /concept-bevestigen en /facturen. /facturen is de historiepagina. GET /facturen/urenspecificatie?invoice_id={lokaal-id} toont een uitsluitend lokale historische urenspecificatie.

## Configuratie en lokale veiligheid

APP_NAME, APP_TIMEZONE (Europe/Amsterdam), DB_PATH (storage/app.sqlite), TOGGL_API_TOKEN en WEFACT_API_KEY. Algemene environmentvariabelen kunnen .env overschrijven; token/key niet. API-URL's staan vast in transports. Workspace volgt uit Toggl-/projectdata.

Composer start start via @php bin/start.php dezelfde PHP-binary als Composer en bindt 127.0.0.1:8080 met uitsluitend public/ als document root. Runtime/LocalServer geeft per serverproces -d overrides mee: display_errors/startup_errors uit, log_errors aan en error_reporting=E_ALL. Het log staat in storage/logs/php-error.log, met 0700/0600 rechten en behoud van bestaande inhoud. Globale php.ini blijft intact en extensies blijven geladen; losse CLI-tests behouden hun eigen instellingen. Geen login: niet extern beschikbaar maken. Sessies staan in storage/, met HttpOnly, SameSite Strict, strict mode en no-store. POST gebruikt CSRF/validatie; redirects voorkomen browserherhaling. HTML wordt escaped, databasequeries gebruiken PDO/prepared statements. JavaScript is nooit de financiële/veiligheidsautoriteit.

## Projecten en brongegevens

- Toggl-project-ID identificeert project, WeFact-Identifier de debiteur. Namen zijn presentatie; DebtorCode is het herkenbare nummer.
- Eén mapping/tarief per project. Meerdere projecten mogen dezelfde debiteur gebruiken, met elk een eigen integer hourly_rate_cents.
- Expliciete projectrefresh: één GET /api/v9/me/projects?include_archived=true. Eerst volledige validatie, daarna transactionele catalogusupdate.
- Niet meer ontvangen projecten blijven bestaan als niet beschikbaar/inactief; archiveren/hernoemen verwijdert geen mapping of tarief.
- Uren en Projectkoppelingen gebruiken de lokale active-status: standaard actief, optioneel archief. Uren houdt periodeprojecten en geselecteerd project zichtbaar, onafhankelijk van mapping/invoiced-status.
- Archief-/zoekfilters doen geen API-request. Wijzigingen in Toggl worden lokaal bekend na expliciete catalogusrefresh.
- Facturatie vereist actief/beschikbaar project, debiteur uit de juiste cache en positief tarief. Zichtbaar archief betekent niet factureerbaar.
- Debiteurenrefresh via WeFact debtor/list: maximaal 1.000 per pagina, veiligheidsgrens 100 pagina's. Alleen volledig gevalideerde data vervangt de cache transactioneel.
- Fingerprints binden caches, mappings en acties aan API-configuratie. Oude IDs onder een andere key worden niet ongemerkt gebruikt.

## Datums en uren ophalen

Uren ondersteunt vrije periodes binnen de terugkijkgrens, niet uitsluitend kalendermaanden. De inclusieve minimumdatum is vandaag minus drie kalendermaanden in APP_TIMEZONE; maandultimo wordt op de laatste bestaande dag begrensd. DateRange::earliestStart levert dezelfde grens aan HTML min en servervalidatie in TimeEntryController. Ongeldige/omgekeerde datums en te vroege begindatum worden vóór API-ophalen geweigerd.

Interne ISO-datums en lokale middernachten blijven behouden. GET /api/v9/me/time_entries gebruikt UTC RFC3339 start_date/end_date. Tot is in de UI inclusief, intern middernacht van de volgende dag exclusief. Entry-starttijd bepaalt de periode, niet overlap. DST blijft correct.

Normaal één bulkrequest; ontbrekende projectnamen kunnen één extra GET /me/projects?include_archived=true veroorzaken. Sessienamen worden hergebruikt. Geen detailrequest per project/entry.

Een batch met minstens 1.000 entries geldt als mogelijk afgekapt: halveer de periode en lees subranges, maximaal acht time-entryrequests per actie. Dedupliqueer IDs en behoud beschikbare ouderresultaten als later iets mislukt. Geen onbeperkte paginering/retry.

Completeness beschrijft uitsluitend de API-fetch, nooit het zichtbare/factureerbare aantal:
- Geldige lege array/batch: volledig geslaagd.
- Volledige dataset met alleen gefactureerde entries: alles al gefactureerd.
- Volledige dataset zonder resultaat door gebruikersfilters: lege filters.
- Malformed response, API-/netwerkfout, quota of afgebroken splitting: onvolledig, ook met nul zichtbare entries.
- Geen definitieve facturatie van een onvolledige dataset.

De concrete Toggl HTTP 400 start_date-grens blijft een begrijpelijk vangnet. Interne verse bevestigingscontrole, tagsync en sommige CLI-checks gebruiken de generieke DateRange-parser zonder UI-terugkijkvalidatie; API-afwijzingen blokkeren daar veilig. Geen alternatieve route voor oudere uren en geen persistente historische urencache.

## Entryclassificatie en selectie

TimeEntryStatus herkent volledige tagnamen case-insensitive: invoiced en Billed by factuursturen.nl. Geen substringmatch. Actieve lokale invoiced_entries gelden ook als gefactureerd zonder Toggl-tag. Reserveringen blokkeren onzekere entries.

Negatieve duration of ontbrekende stop betekent running: nooit selecteerbaar of factureerbaar. Historische facturatietags worden niet verwijderd/vervangen.

Selecteer geldige entry-IDs vóór financiële groepering. Servervalidatie controleert dataset, project/workspace, filter, tags, running, lokale registraties/reserveringen en actuele configuratie. Geposte bedragen, durations, tarieven en klantgegevens zijn geen waarheid.

Sessies bewaren opgehaalde data en selectie/modi/datasetbinding. Nieuwe fetch vervangt datasetnonce en preview. Een preview is geen factuurregistratie.

## Financiële invarianten

**Eén Toggl-project = één aparte WeFact-conceptfactuur**, ook bij dezelfde debiteur.

1. Behoud exacte Toggl-duration per geselecteerde entry in integer seconden.
2. Tel geselecteerde seconden per project op als duration_seconds.
3. Rond uitsluitend het projecttotaal eenmaal omhoog:
   billed_duration_seconds = duration_seconds + (900 - duration_seconds % 900) % 900.
4. Geldbasis: billed_duration_seconds × hourly_rate_cents / 3600.
5. Rond projectbedrag één keer half-up naar hele centen; gebruik integer arithmetic en overflowcontroles.

Geen afronding per entry. Werkelijke seconden en originele entries blijven apart voor audit. Voorbeeld: 27:18:47 → 27:30; 12000 tariefcenten → 330000 totaalcenten.

### Regelmodi en verdeling

Samengevat is standaard: één regel Werkzaamheden {projectnaam}.

Per omschrijving groepeert geselecteerde entries met trim + mb_strtolower. Lege omschrijving wordt Werkzaamheden. Geen fuzzy matching; oorspronkelijke entries/IDs blijven bewaard.

LineCalculator verdeelt deterministisch:
1. Elke groep krijgt floor(werkelijke groepsseconden / 900) kwartieren.
2. Verdeel resterende projectkwartieren naar grootste secondenrest; gelijke resten op genormaliseerde omschrijvingssleutel.
3. Regelcenten beginnen bij het naar beneden afgeronde bedrag; verdeel restcenten tot het eenmaal half-up afgeronde projecttotaal naar grootste geldrest, met dezelfde stabiele tie-break.

Som van billed-regelseconden is de afgeronde projectduur; som van regelcenten is het projectbedrag. Beide modi geven hetzelfde totaal voor dezelfde selectie. Een kleine groep kan nul kwartieren krijgen; werkelijke seconden/IDs verdwijnen niet.

### WeFact-payload en restcenten

InvoicePayload bouwt:
- Status=0, Debtor=Identifier, Date=huidige lokale datum, VatCalcMethod=excl, Description=projectnaam.
- InvoiceLines: Number=compacte kwartieruren, NumberSuffix=" uur", PriceExcl=projectuurtarief met twee decimalen.
- Regelomschrijving Werkzaamheden {projectnaam}, of {projectnaam} — {omschrijving}.
- PeriodicType=once, StartDate/EndDate=gekozen periode.
- Transport voegt controller=invoice, action=add en uitsluitend daar geheime api_key toe.
- Geen IgnoreDiscount, send/edit/delete, productcodes of vaste TaxCode.

ApiDecimal/JsonPayload behouden decimalen zonder floats als financiële waarheid. Number is bijvoorbeeld 25, 25.25, 25.5, 25.75. De UI toont uren:minuten; zichtbare datums zijn dd-mm-yyyy of dd-mm-yyyy HH:mm in APP_TIMEZONE. Interne/API-datums blijven ISO.

Restcentbescherming blijft nodig wanneer kwartierprijs geen hele centen oplevert. Lokale centverdeling kan afwijken van afzonderlijk half-up afronden van Number × PriceExcl. InvoicePayload controleert elke regel; bij verschil blokkeert de app vóór de write en vraagt Samengevat. Geen verborgen prijs-/hoeveelheidscorrectie. Ook geretourneerd AmountExcl moet exact gelijk zijn.

Btw wordt niet lokaal berekend; gebruiker controleert dit en het concept in WeFact. Geen automatische verzending.

## Confirmation, idempotency en crashes

Prepare valideert de expliciet bijgewerkte preview en maakt uitsluitend een lokale prepared-actie met sessiegebonden token, snapshot en credentialvrije payload.

Definitieve confirm:
1. Valideer token/eigenaar, prepared-status, dataset, API-fingerprint, previewhash en actuele configuratie.
2. Haal Toggl-uren opnieuw read-only op; eis volledige data en identiek geselecteerd model.
3. Controleer factuurdatum, claim unieke entryreserveringen transactioneel en zet vóór de externe write duurzaam reconciliation_required.
4. Doe één invoice/add zonder automatische retry.
5. Eis bevestigd concept, juiste debiteur, EUR/niet verzonden en exact AmountExcl. Registreer factuur/entries transactioneel en zet succeeded.

Betrouwbare expliciete afwijzing zonder aangemaakte factuur: cancelled, eigen reserveringen vrij. Onzekere transport-/response-uitkomst: reconciliation_required en reserveringen behouden. Bevestigde aanmaak met afwijking of lokale registratiefout: created_review-diagnostiek en bekende remote-identiteit behouden; nooit blind opnieuw aanmaken.

Pre-write marker voorkomt herhaling na crash tussen remote-write en lokale registratie. UI-submitguards helpen tegen dubbelklik; servercontroles blijven leidend. Gebruikte/geannuleerde tokens starten geen nieuwe write; zo nodig nieuwe preview.

## Tagging na lokale registratie

Alleen actieve succesvolle facturen van de juiste bronconfiguratie. Maximaal 25 individuele PUTs per ronde naar /api/v9/workspaces/{workspace_id}/time_entries/{time_entry_id}, exact:

~~~json
{"tags":["invoiced"],"tag_action":"add"}
~~~

Geen bestaande tags meesturen of aparte tag-create. Toggl kan onbekende tagnaam bij toevoegen aanmaken. Geen automatische retry. Elke entry krijgt synced, failed, unknown of blijft pending; eerdere successen blijven gecommit.

Handmatige retry doet eerst verse bulk-GET van de factuurperiode. Reeds gesynchroniseerde entries en entries met erkende facturatietag worden overgeslagen. Ontbrekende entry/workspaceafwijking wordt niet blind geschreven. Quota/authenticatiefouten stoppen verdere writes. Een oude periode kan retry blokkeren.

Taggingfailure verandert nooit factuurbedrag, lifecycle of lokale blokkering. Veilige diagnostiek bewaart HTTP-status, geredigeerde begrensde body, toegestane headers, entry/endpoint/payload en tijd. Bestaande auditpogingen blijven intact. /facturen toont alleen functionele syncstatus, openstaande aantallen en retryknop.

## API-robuustheid

Toggl: vaste HTTPS-host, Basic Auth token:api_token, allowlisted routes, geen redirects, connect-/totale timeout 5/20 seconden. Clientactie-memoization voorkomt dubbele GETs; transport start requests minstens 1,1 seconde uit elkaar.

Quotaheaders X-Toggl-Quota-Remaining/X-Toggl-Quota-Resets-In en Retry-After worden per scope in sessie verwerkt. HTTP 402/429 en bekende blokkades stoppen requests. Ontbrekende quota is onbekend. Free-uitgangspunt 30 requests/uur; geen globale quota-administratie over browsers/CLI's heen.

WeFact: vast HTTPS POST https://api.mijnwefact.nl/v2/, IPv4, geen redirects, timeouts 5/20 seconden. Debtor/list en invoice/show zijn read-only ondanks POST; invoice/add is de enige WeFact-write. JSON/HTTP/API/responsevalidatie en veilige diagnostiek onderscheiden afwijzing, onbekende uitkomst en bevestigd succes. Geen blinde writeretries.

## SQLite, lifecycle en audit

DB is autoritatief voor lokale facturatie. Runtime/Storage maakt uitsluitend de afgeschermde runtime-map aan (0700), ook voor sessies zonder voorafgaande serverstart. Dit staat los van database-initialisatie. Database::connect vereist bestaand bestand (mode=rw), activeert foreign keys en busy_timeout=5000. Bootstrap controleert integrity, foreign keys en actueel schema; geen automatische init/migrate.

Belangrijkste tabellen:
- migrations: toegepaste versies.
- toggl_projects, toggl_project_sync, project_mappings: broncatalogus en configuratie.
- wefact_debtors, wefact_cache_state: vervangbare cache.
- invoice_actions, invoice_entry_reservations: bevestiging/snapshots en unieke actieve entryclaims.
- invoices, invoiced_entries: factuur-/entrysnapshots, werkelijke/gefactureerde projecttijd, integer geld, lokale blokkering en tagstatus.
- invoice_action_results, toggl_tag_sync_attempts, invoice_reconciliation_events: veilige technische/handmatige audit.
- Fundament-/legacytabellen blijven bestaan waar migrations dat vereisen; geen alternatieve actuele configuratiebron.

Migrations 001–013 vormen geordende geschiedenis. 005 vereenvoudigde entryduration; 007 lifecycle; 008 projectkwartieren; 009–011 tagsync/audit; 012 handmatige reconciliation; 013 afzonderlijke remote factuurstatus. Toegepaste migrations nooit herschrijven.

Actieve entryregistraties blijven gefactureerd zolang niet expliciet released. Een gecontroleerd manual_deleted_test-concept bewaart factuur/snapshots/audit maar telt niet actief mee; alleen eigen entries/reserveringen worden vrijgegeven. Geen automatische detectie van handmatige remote-wijzigingen.

De factuurpagina toont succesvolle actieve Facturen en open Handmatige controle vereist. Afgesloten factuuracties worden niet weergegeven of voor deze pagina opgehaald; de records blijven intern beschikbaar via InvoiceRepository::history() voor diagnose/audit en herstel. Oude mislukte pogingen stellen latere succesvolle facturen niet ter discussie.

### Handmatige WeFact-statuscontrole

GET /facturen leest uitsluitend lokale historie en invoice_remote_status. Een CSRF-beveiligde POST action=refresh_status doet per succesvolle actieve factuur één read-only invoice/show met het lokaal opgeslagen Identifier. InvoiceReadTransport en de bestaande CurlTransport/WeFactClient verzorgen deze read; geen retries, invoice/edit of andere nieuwe externe writes.

RemoteStatusService hergebruikt conservatief de bestaande bronfingerprint (WeFact-key én Toggl-token), omdat historische registraties geen afzonderlijke WeFact-keyfingerprint bevatten. Een mismatch wordt zonder API-request overgeslagen. Afgesloten registraties en onzekere acties worden niet bevraagd.

invoice_remote_status bewaart statuscode, substatus, actueel factuurnummer/datum, verzendteller/-datum, valuta, exacte integer-centbedragen, betaaldatum/vervaldatum en controlemetadata. Optionele afwezige waarden blijven null; onbruikbare verzendtellers/datums blijven onbekend. Ongeldige verplichte velden, identiteit of geldbedragen verwerpen de snapshot. WeFact-decimale geldwaarden worden vóór JSON-decoding als tekst behouden; ook creditbedragen worden zonder floats verwerkt.

Primaire labels volgen uitsluitend Status: 0 Concept, 2 Verzonden, 3 Deels betaald, 4 Betaald, 8 Creditfactuur, 9 Vervallen, anders Onbekend. AmountOutstanding leidt nooit tot een alternatieve primaire status. Verzending is afzonderlijk: Sent>0 geregistreerd, Sent=0 geen registratie, ontbrekend/onbruikbaar onbekend; geldige SentDate wordt Nederlands weergegeven.

De badge Te versturen wordt uitsluitend lokaal bepaald: laatste succesvolle Status=0, of Status=2 met Sent=0. Andere/onbekende/onvoldoende remote gegevens geven geen badge. Zonder succesvolle controle mag de expliciete aanmaaksnapshot Concept als fallback dienen; de melding dat de actuele status nog niet gecontroleerd is blijft zichtbaar. Een mislukte refresh verandert deze afleiding uit de laatste geldige snapshot niet.

Elke geldige snapshot wordt afzonderlijk transactioneel opgeslagen. Een fout werkt alleen de pogingmetadata bij en bewaart de laatst geldige snapshot. Authenticatie/toegangs-/rate-limitproblemen stoppen verdere requests. Statusrefresh verandert nooit invoices.wefact_status=0, lifecycle, financiële snapshots, reservations, invoiced_entries of Toggl-tags. Ook een remote fout geeft niets lokaal vrij. /facturen toont de laatst bekende status, het actuele bekende nummer, controletijd en een subtiele foutmelding; Urenspecificatie blijft onafhankelijk beschikbaar.

### Lokale urenspecificatie

Specification leest factuurcontext en de destijds opgeslagen billed_duration_seconds uit invoices, en oorspronkelijke omschrijvingen/exacte duration_seconds uit invoiced_entries. Ook released entries blijven onderdeel van hun oorspronkelijke factuurhistorie. De entryset wordt tegen invoice_actions.snapshot_json gecontroleerd en de som tegen de geregistreerde werkelijke projecttijd. Ontbrekende/inconsistente historie geeft een melding; geen reconstructie via actuele bronnen.

Groepering gebruikt trim + mb_strtolower, met Werkzaamheden als lege omschrijving, zonder kwartier- of centverdeling. De detailpagina behoudt oorspronkelijke entries en toont geregistreerde en gefactureerde tijd afzonderlijk. Beschikbaarheid is onafhankelijk van actuele remote factuurstatus en lokale lifecycle. Deze GET-flow gebruikt geen sessie, API, mapping, tarief of factuurmutatie.

## Onderhoud en herstel

Bestaande expliciete commando's:
- composer db:init -- --confirm-first-install: uitsluitend eerste administratie.
- composer migrate: bestaande geldige DB bijwerken.
- composer db:backup: gecontroleerde VACUUM INTO-snapshot.
- composer db:restore -- --file=PAD --confirm-restore: geldige appbackup; geen bestaand doel overschrijven.
- php bin/admin.php actions: open reconciliationacties inspecteren.
- composer db:reconcile: no_invoice of link_existing; exacte opties in README.

Nieuwe gevoelige onderhoudsbestanden 0600, nieuwe mappen 0700. .env apart veilig bewaren, nooit automatisch meebackuppen. Git negeert .env, storage-inhoud, backups en SQLitebestanden. Stop processen voor restore, zet DB/nevenbestanden veilig apart en controleer mogelijke latere remote-facturen na oude restore.

Reconciliation eist originele actionhash/entryset, geldige status, unieke reserveringen en auditreden. No_invoice vereist geen bekende remote-identiteit/succesregistratie. Link_existing vereist gecontroleerd WeFact-ID/nummer, debiteur en exact bedrag; registreert oorspronkelijke snapshot zonder write of automatische tagsync. Geen algemene correctie-/verwijderflow.

## Verificatie en bewuste grenzen

Composer test draait alle PHP-suites. JS: tests/selection.cjs en tests/daily.cjs. Syntaxchecks en Composer-validatie staan in README. Destructieve tests uitsluitend tijdelijke databases; geen echte API nodig.

Geen automatische cloudbackup, achtergrondpolling, historische urenimport, remote-verwijderdetectie of algemene factuurcorrectie-UI. Behoud restcentenblokkering, restrictie op inactieve projecten en recoveryveiligheid bij toekomstige UX-wijzigingen.
