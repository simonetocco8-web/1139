# Gestionale Multi-Loggia — fase 1

Fondamenta Laravel 13, Livewire 4, Blade, Tailwind e Fortify. Documenti iniziali redatti prima del codice: ARCHITECTURE.md, DATABASE.md, REQUIREMENTS.md, ROADMAP.md. Nessuna capitazione, canone o tesoreria implementati. Nessun dato personale o account demo.

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
`composer test` esegue controllo stile Pint, Larastan livello 7 e test Laravel/PHPUnit. Test infrastrutturali su SQLite in memoria; non provano vincoli, lock e concorrenza MySQL. Il resoconto effettivo è in VERIFICATION.md. La CI esegue questi controlli su PHP 8.3 e Node 24, ma non è stata eseguita su GitHub in questa sessione.

## Autenticazione deliberatamente chiusa
Fortify è installato, ma `Fortify::ignoreRoutes()` e features vuote impediscono login, registrazione, reset e gestione credenziali. Le pagine private/componimenti editabili dello starter sono rimossi. Anche una sessione già autenticata non accede a dashboard o impostazioni. Il componente Livewire pubblico espone solo lingua, fuso e valuta. Non riattivare il login standard prima di inviti, OTP email/SMS, revoca, policy e isolamento testati nella fase 2. TOTP/passkey dello starter non sono il secondo canale richiesto.

## Distribuzione cPanel Linux
1. Verificare PHP 8.3+ e relative estensioni anche nella CLI cron; MySQL 8.x effettivo e HTTPS. Non è stato verificato un hosting specifico.
2. In build eseguire `npm ci && npm run build`. Preparare vendor con `composer install --no-dev --prefer-dist --optimize-autoloader` su PHP compatibile con il server. Nessun Node, Redis o Docker richiesto sul server. Controllare piattaforma reale senza `--ignore-platform-reqs`.
3. Caricare progetto e vendor fuori dalla directory pubblica; includere `public/build`, lockfile e `.env.example`. Escludere `.git`, node_modules, `.env` locale, database.sqlite, log e cache di test. Creare `.env` sul server con APP_ENV=production, APP_DEBUG=false, APP_URL=https://dominio-reale, SESSION_SECURE_COOKIE=true e configurazione reale MySQL/SMTP. Generare una APP_KEY nuova una sola volta; conservarla nei backup, non ruotarla senza migrazione dei dati cifrati.
4. Impostare document root del dominio su `<progetto>/public`. Non esporre l'intera radice e non spostare tutti i file in public_html. Se il piano non permette document root separata, concordare con il provider una configurazione sicura prima della pubblicazione.
5. Rendere scrivibili al solo utente PHP `storage` e `bootstrap/cache`; evitare 777. `.env` leggibile solo dall'utente applicativo. I file `storage/app/private` restano fuori dal web root; nessun `storage:link` per allegati riservati.
6. Eseguire con il PHP corretto `php artisan migrate --force`, `php artisan config:cache`, `php artisan route:cache`, `php artisan view:cache`. Non copiare cache generate in sviluppo. Non eseguire `migrate:fresh` in produzione. Verificare `/` e `/up`, asset e cookie HTTPS; login/registrazione devono restare assenti.
7. Configurare cron cPanel, sostituendo i percorsi sotto con quelli reali; `flock` evita sovrapposizioni. Se assente, concordare un lock equivalente; nessun processo residente:
```cron
* * * * * cd /percorso/progetto && /percorso/php artisan schedule:run >> /dev/null 2>&1
* * * * * /usr/bin/flock -n /percorso/progetto/storage/framework/queue.lock /percorso/php /percorso/progetto/artisan queue:work database --stop-when-empty --max-time=50 --timeout=30 --tries=3 >> /dev/null 2>&1
```
La fase 1 non programma job di business. Il cron predispone l'infrastruttura; nelle fasi successive monitorare failed_jobs e alert neutri, ridurre informazioni nei payload/errori. SMTP e provider SMS non configurati né verificati: non è possibile inviare OTP. Backup database e file privati cifrati, accessi ristretti e restore da collaudare prima di dati reali.

## Provenienza
Starter ufficiale `laravel/livewire-starter-kit`, commit `0f62a26c4e4b401c1300930f47d72a497add8cce`, licenza MIT (LICENSE). Adattamenti fase 1: niente wizard Chisel, dipendenze server opzionali rimosse, Vite standard senza font remoti, autenticazione chiusa, seed vuoto e layout italiano. Pacchetti TOTP/passkey transitivi di Fortify non sono funzionalità attivate.
