# Gestionale Multi-Loggia — organizzazione, identità e capitazioni

Fondamenta Laravel 13, Livewire 4, Blade, Tailwind e Fortify. Documenti iniziali redatti prima del codice: ARCHITECTURE.md, DATABASE.md, REQUIREMENTS.md, ROADMAP.md. Modello organizzativo e CRUD realizzati nella richiesta successiva: obbedienze, riti/compatibilità, gerarchie e logge. Fase 3: persone locali, iscrizioni e storico, importazione CSV e impostazioni loggia. Capitazioni implementate nella fase 4 e canoni verso sovrastrutture nella fase 5; Tesoreria operativa non implementata. Solo dati dimostrativi fittizi, nessun account demo nel seed.

## Prerequisiti
PHP 8.3+ (CLI e web della stessa versione), Composer 2, estensioni ctype, curl, dom/XML, fileinfo, filter, hash, mbstring, openssl, PDO/pdo_mysql, session, tokenizer, zip. MySQL 8.x InnoDB/utf8mb4. Node 22.12+ o 24 LTS soltanto sulla macchina di build. Il lock Composer è risolto con platform.php=8.3.0, anche se in questa sessione si esegue PHP 8.4.26; `composer check-platform-reqs` verifica l'ambiente reale. PHPUnit 11 mantiene la compatibilità di sviluppo PHP 8.3.

## Sviluppo con MySQL
Dalla radice del progetto:
```sh
composer install
cp .env.example .env
php artisan key:generate
```
Configurare `.env`: DB_HOST/PORT/DATABASE/USERNAME/PASSWORD reali, APP_URL; non ci sono credenziali predefinite. Poi:
```sh
php artisan migrate
npm ci
npm run build
php artisan serve
```
Aprire http://localhost:8000; `/up` restituisce solo `{"status":"ok"}`. È un controllo di vita PHP, non di disponibilità MySQL/SMTP. Per hot reload eseguire `npm run dev` in un altro terminale locale; non serve in produzione.

## Anteprima senza MySQL (solo sviluppo)
La sessione consegnata usa SQLite locale senza dati personali, SMTP disattivato con `MAIL_MAILER=array`; `.env` non deve essere distribuito. Dopo aver copiato `.env.example`, impostare:
```dotenv
DB_CONNECTION=sqlite
DB_DATABASE=/percorso/assoluto/progetto/database/database.sqlite
MAIL_MAILER=array
```
Creare il file SQLite, generare APP_KEY e migrare. In questo ambiente il PHP disponibile è `/workspace/toolchain/bin/php`; Composer si invoca con `/workspace/toolchain/bin/php /workspace/toolchain/bin/composer`. Non sono strumenti richiesti sull'hosting. Per avviare il server in questa sessione usare `PATH=/workspace/toolchain/bin:$PATH /workspace/toolchain/bin/php artisan serve --no-reload`: il flag conserva le variabili delle librerie PHP della toolchain locale.

## Verifiche
```sh
composer validate --strict
composer check-platform-reqs
composer test
npm run build
```
`composer test` esegue controllo stile Pint, Larastan livello 7 e test Laravel/PHPUnit. Test infrastrutturali su SQLite in memoria; non provano vincoli, lock e concorrenza MySQL. Il resoconto effettivo è in VERIFICATION.md. La CI esegue questi controlli su PHP 8.3, Node 24 e MySQL 8. I resoconti delle fasi successive sono in ORGANIZATION-VERIFICATION.md e IDENTITY-VERIFICATION.md.

## Accesso tramite invito e OTP
La fase 2 è implementata: Fortify verifica le credenziali, poi invia un OTP al canale verificato. Solo il consumo atomico dell'OTP crea la sessione completa. Registrazione pubblica, TOTP/passkey e login senza OTP restano disabilitati. Nessun account o password demo nel seed.

Configurare SMTP e il gateway SMS seguendo [IDENTITY-CONFIGURATION.md](IDENTITY-CONFIGURATION.md). Per il primo Super User, con SMTP operativo:
```sh
php artisan identity:invite-superuser indirizzo-autorizzato@example.invalid
```
Sostituire l'indirizzo con quello del destinatario autorizzato: il comando invia un invito, non crea una password né autentica. Gli amministratori locali invitano utenti e assegnano ruoli per la propria loggia. Il Super User non ottiene accesso ai contenuti locali; l'assistenza richiede consenso temporaneo esplicito.

Vedere [IDENTITY.md](IDENTITY.md) per flussi e matrice permessi, [IDENTITY-VERIFICATION.md](IDENTITY-VERIFICATION.md) per test e [schermate](docs/screenshots/identity/README.md). Senza SMTP/SMS configurato l'applicazione segnala il mancato invio; il driver array non invia OTP.

## Distribuzione cPanel Linux
1. Verificare PHP 8.3+ e relative estensioni anche nella CLI cron; MySQL 8.x effettivo e HTTPS. Non è stato verificato un hosting specifico.
2. In build eseguire `npm ci && npm run build`. Preparare vendor con `composer install --no-dev --prefer-dist --optimize-autoloader` su PHP compatibile con il server. Nessun Node, Redis o Docker richiesto sul server. Controllare piattaforma reale senza `--ignore-platform-reqs`.
3. Caricare progetto e vendor fuori dalla directory pubblica; includere `public/build`, lockfile e `.env.example`. Escludere `.git`, node_modules, `.env` locale, database.sqlite, log e cache di test. Creare `.env` sul server con APP_ENV=production, APP_DEBUG=false, APP_URL=https://dominio-reale, SESSION_SECURE_COOKIE=true e configurazione reale MySQL/SMTP. Generare una APP_KEY nuova una sola volta; conservarla nei backup, non ruotarla senza migrazione dei dati cifrati.
4. Impostare document root del dominio su `<progetto>/public`. Non esporre l'intera radice e non spostare tutti i file in public_html. Se il piano non permette document root separata, concordare con il provider una configurazione sicura prima della pubblicazione.
5. Rendere scrivibili al solo utente PHP `storage` e `bootstrap/cache`; evitare 777. `.env` leggibile solo dall'utente applicativo. I file `storage/app/private` restano fuori dal web root; nessun `storage:link` per allegati riservati.
6. Eseguire con il PHP corretto `php artisan migrate --force`, `php artisan config:cache`, `php artisan route:cache`, `php artisan view:cache`. Non copiare cache generate in sviluppo. Non eseguire `migrate:fresh` in produzione. Verificare `/` e `/up`, asset e cookie HTTPS; verificare invito, login con OTP e assenza di registrazione pubblica.
7. Configurare cron cPanel, sostituendo i percorsi sotto con quelli reali; `flock` evita sovrapposizioni. Se assente, concordare un lock equivalente; nessun processo residente:
```cron
* * * * * cd /percorso/progetto && /percorso/php artisan schedule:run >> /dev/null 2>&1
* * * * * /usr/bin/flock -n /percorso/progetto/storage/framework/queue.lock /percorso/php /percorso/progetto/artisan queue:work database --stop-when-empty --max-time=50 --timeout=30 --tries=3 >> /dev/null 2>&1
```
La fase 1 non programma job di business. Il cron predispone l'infrastruttura; nelle fasi successive monitorare failed_jobs e alert neutri, ridurre informazioni nei payload/errori. SMTP e provider SMS non configurati né verificati: non è possibile inviare OTP. Backup database e file privati cifrati, accessi ristretti e restore da collaudare prima di dati reali.

## Provenienza
Starter ufficiale `laravel/livewire-starter-kit`, commit `0f62a26c4e4b401c1300930f47d72a497add8cce`, licenza MIT (LICENSE). Adattamenti fase 1: niente wizard Chisel, dipendenze server opzionali rimosse, Vite standard senza font remoti, autenticazione chiusa, seed vuoto e layout italiano. Pacchetti TOTP/passkey transitivi di Fortify non sono funzionalità attivate.

## Modello dati e struttura organizzativa
La nuova fase aggiunge le due migrazioni `2026_10_14_160000_create_organization_tables.php` e `2026_10_14_160100_add_organization_access.php`. Prima di migrare un ambiente operativo, eseguire backup protetto e verificare restore; poi, con MySQL 8 configurato:
```sh
php artisan migrate --force
npm ci
npm run build
```
Il deploy senza Node usa gli asset `public/build` già compilati. Non usare migrate:fresh su dati operativi; il rollback di queste due migrazioni elimina le tabelle organizzative e non va usato come disattivazione.

Schermate Livewire implementate (accesso riservato):
- `/admin/organizzazione/obediences`: ricerca, CRUD/disattivazione obbedienze e associazione dei riti.
- `/admin/organizzazione/rites`: ricerca, CRUD/disattivazione riti.
- `/admin/organizzazione/structures`: ricerca, CRUD/disattivazione e struttura genitore facoltativa.
- `/admin/organizzazione/lodges`: ricerca, CRUD/disattivazione, numero per obbedienza, rito compatibile e storico territoriale.
- `/loggia`: sole logge per cui il soggetto dispone di membership attiva e almeno un ruolo; nessun accesso implicito per amministratori piattaforma.

Il Super User è un flag server-side non mass-assignable, non un ruolo tenant universale. Ogni schermata e richiesta Livewire richiede account autenticato, secondo fattore attestato server-side e autorizzazione. La fase 2 emette i marker di sessione esclusivamente dopo OTP valido; verifica inoltre session_version ad ogni richiesta. Nessuna route di anteprima o bypass distribuito. Il comando iniziale invia solo l'invito autorizzato, senza concessione diretta di sessione.

Dati dimostrativi completamente fittizi, solo in locale/testing:
```sh
php artisan db:seed --class=OrganizationDemoSeeder
```
Il seeder è idempotente, non crea account ed è vietato in produzione. Il seed predefinito resta vuoto. Membership e ruoli locali sono ora gestiti mediante inviti e autorizzazioni della fase 2.

Documenti attuali: ORGANIZATION.md (invarianti/assunzioni), ORGANIZATION-VERIFICATION.md (risultati), DATABASE.md (vincoli). Schermate reali in `docs/screenshots/README.md`, con solo dati fittizi.

### Test MySQL
Oltre a `composer test`, la suite MySQL include controlli InnoDB/chiave generata. Serve un database **dedicato e sacrificabile** con nome terminante in `_test`; RefreshDatabase ricrea le tabelle. Usare una configurazione di test separata e protetta, con DB_CONNECTION=mysql, DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME e DB_PASSWORD reali per quella sola istanza; nessuna credenziale di produzione. Poi:
```sh
php vendor/bin/phpunit -c phpunit.mysql.xml
```
Non eseguire la suite con un database operativo. Il bootstrap blocca nomi che non terminano in `_test`. In questa sessione le verifiche sono state eseguite su un container temporaneo MySQL 8.0.46, rimosso al termine; Docker è esclusivamente uno strumento di test, non una dipendenza applicativa o requisito cPanel. La CI include un servizio MySQL 8 con credenziali effimere dedicate ai test.

## Fase 3 — anagrafiche e impostazioni loggia
Applicare `2026_10_14_180000_create_membership_tables.php` mediante `php artisan migrate --force` dopo backup/restore verificato. Il rollback elimina anagrafiche/storico/importazioni/configurazioni: non usarlo per disattivare iscrizioni. Deploy con build aggiornato; nessuna nuova dipendenza Composer/npm.

- `/loggia/fratelli`: elenco locale, ricerca nome/cognome, filtro stato ultima iscrizione, esportazione CSV autorizzata.
- `/logge/{lodge}/fratelli/nuovo` e `/logge/{lodge}/fratelli/{person}`: inserimento, modifica, iscrizioni, esenzioni motivate, chiusura definitiva, invito vincolato alla persona e storico cifrato.
- `/loggia/fratelli/importazione`: modello CSV, anteprima/validazione e conferma atomica; massimo 200 righe/256 KiB, solo nuove persone.
- `/loggia/impostazioni`: anagrafica/preferenze locali, struttura organizzativa, responsabili e visibilità della futura tesoreria.
- `/loggia/posizione-personale`: sole iscrizioni del proprio account nella loggia attiva. Nessun saldo fittizio; il modulo economico non è ancora disponibile.

Amministratore locale gestisce, Tesoriere legge/esporta, Fratello consulta soltanto la propria scheda. Ruoli, account e iscrizioni sono separati: chiudere un'iscrizione conserva accesso storico e non cambia automaticamente i ruoli. Revocare l'accesso operativo dalla pagina Accessi quando necessario. Super User senza membership/consenso non legge elenchi. Ogni identificativo URL viene verificato rispetto alla loggia attiva e alle autorizzazioni correnti.

Dati dimostrativi espliciti (locale/testing, nessun account o messaggio):
```sh
php artisan db:seed --class=MembershipDemoSeeder
```
Nove persone completamente fittizie in tre logge demo, stati attivo/sospeso/concluso; seeder idempotente, vietato in produzione. Il seed predefinito resta vuoto. Le anteprime CSV sono cifrate, scadono in 15 minuti e vengono rimosse da `model:prune` ogni ora tramite il cron scheduler già documentato. Dopo conferma i dati del payload sono subito svuotati. Mantenere APP_KEY nei backup protetti.

[Modello e assunzioni](MEMBERSHIP.md), [verifiche](MEMBERSHIP-VERIFICATION.md), [schermate desktop/mobile](docs/screenshots/membership/README.md). Fermarsi qui: contabilità, notifiche operative e report economici sono fasi future.

## Fase 4 — capitazioni periodiche
Migrazione `2026_10_14_190000_create_capitation_tables.php`: piani/versioni, condizioni individuali, rate immutabili, rettifiche, ricevute collegate al gateway Tesoreria, batch e imputazioni. Backup e restore prima di `php artisan migrate --force`; rollback distruttivo solo su test o restore controllato. Nessuna nuova dipendenza Composer/npm; includere public/build aggiornato.

- `/loggia/capitazioni`: piani mensili/trimestrali/semestrali/annuali, scadenza/prorata espliciti, versioni economiche future, stato attivo/inattivo e generazione idempotente.
- `/loggia/capitazioni/scadenze`, `/morosi`, `/riepilogo`: scadenzario, morosi raggruppati per persona e riepilogo delle competenze selezionate.
- `/logge/{lodge}/capitazioni/fratelli/{person}`: situazione individuale, condizioni personali, rettifiche, storico versamenti, credito e imputazioni su più rate.
- `/loggia/capitazioni/personale`: prospetto protetto delle proprie rate/versamenti/credito nella loggia attiva. La pagina iscrizioni della fase 3 ora rimanda qui.

Generazione automatica giornaliera alle 01:15 Europe/Rome mediante cron scheduler esistente; ripetibile manualmente:
```sh
php artisan capitation:generate
```
L'operazione genera soltanto rate, senza registrare entrate. Quote e condizioni già maturate non sono sovrascritte. Nuove versioni da cicli futuri, esenzioni/sconti/importi personali, rettifiche motivate; dettaglio in [CAPITATIONS.md](CAPITATIONS.md).

**Tesoreria assente:** gli incassi effettivi sono disabilitati e non simulati. ReceiptGateway è il contratto transazionale condiviso da implementare nella fase Tesoreria; il modulo non crea conti, casse o movimenti paralleli. Pagamenti parziali, riparto su più rate, credito e annullamento delle imputazioni sono realizzati nel servizio e verificati con adapter di test sulla stessa connessione/transazione. L'uso successivo del credito non richiama il gateway né crea una seconda entrata.

Dati completamente fittizi e idempotenti, nessun account/incasso/messaggio:
```sh
php artisan db:seed --class=CapitationDemoSeeder
```
Solo locale/testing. Conservare APP_KEY nei backup per motivazioni cifrate. [Verifiche fase 4](CAPITATIONS-VERIFICATION.md) e [schermate](docs/screenshots/capitations/README.md). Fermarsi qui: nessun canone o modulo Tesoreria avviato.


## Fase 5 — canoni verso sovrastrutture

Regole generali versionate per obbedienza/livello, condizioni specifiche delle sovrastrutture e deroghe locali con validità. Importi fissi o per fratello, periodicità mensile/trimestrale/semestrale/annuale, criterio e conteggio salvati, destinatario dallo storico territoriale. Obblighi immutabili e idempotenti, dashboard/scadenzario/storico, pagamenti parziali attraverso il contratto di uscita della Tesoreria.

```sh
php artisan migrate --force
php artisan levy:generate
# Solo locale/testing, senza account né pagamenti fittizi:
php artisan db:seed --class=LevyDemoSeeder
```

Lo scheduler esistente genera i canoni alle 01:30 Europe/Rome. Interfaccia globale `/admin/canoni`, locale `/loggia/canoni`. Nuove condizioni dopo l’avvio solo su cicli futuri; riferimento al primo giorno del ciclo, ore 00:00. Vedere [regole, gerarchie e integrazione finanziaria](LEVIES.md), [test](LEVIES-VERIFICATION.md) e [schermate desktop/mobile](docs/screenshots/levies/README.md).

La Tesoreria è ancora assente: adapter `DisbursementGateway` indisponibile, pagamenti bloccati con messaggio esplicito, nessuna uscita simulata. Il servizio transazionale è verificato con spy esclusivamente nei test. Il collegamento al futuro modulo finanziario dovrà postare il movimento reale sulla stessa connessione. Nessuna fase successiva implementata.
