# Distribuzione cPanel PHP/MySQL

**Stato: applicazione verificata in ambiente di test, non dichiarata pronta per la produzione.** Prima di dati reali completare [ACCEPTANCE-CHECKLIST.md](ACCEPTANCE-CHECKLIST.md), configurare i servizi effettivi e far approvare privacy, conservazione e ripristino dal titolare. Non è stato fornito un hosting cPanel su cui eseguire il collaudo. Nessuna credenziale o destinazione reale è inventata.

## Requisiti da verificare con il provider

PHP **8.3+** nella CLI cron e nel web, stesse estensioni Composer: PDO MySQL, mbstring, XML/dom, curl, fileinfo, openssl, **sodium**, zip e dipendenze indicate da `composer check-platform-reqs`. MySQL **8 InnoDB**, utf8mb4, connessione UTC e isolamento **REPEATABLE READ**. Non sostituire MariaDB senza un nuovo collaudo di vincoli e concorrenza. Solo EUR.

Cron cPanel, shell con `flock` e `timeout`, `proc_open` abilitato per il client **mysqldump MySQL 8**, spazio/quota per backup e file privati. Il client `mysql` serve sulla macchina isolata di ripristino. PCNTL è utile per i timeout Laravel, ma il wrapper applica anche un limite esterno al worker. Nessun daemon, Redis, Docker o Node richiesto sul server; Node 24 serve soltanto in build. Docker è stato usato esclusivamente nei test.

Richiedere HTTPS valido, backup e copia cifrata su archivio esterno, invio SMTP con TLS verificato; eventuale SMS con endpoint HTTPS e contratto documentato. Verificare limiti di CPU/memoria/durata cron su volumi effettivi. Se il piano non offre strumenti, isolamento della directory pubblica o backup coerenti, **non distribuire dati reali su quel piano**.

## Preparare il rilascio

Da checkout del commit approvato, in ambiente di build compatibile:

```sh
composer install --no-interaction --prefer-dist
composer validate --strict
composer check-platform-reqs
composer test
npm ci
npm audit
npm run build
# Su database MySQL dedicato e sacrificabile, nome terminante in _test:
php vendor/bin/phpunit -c phpunit.mysql.xml
# Preparare vendor del pacchetto di produzione dopo i test:
composer install --no-dev --prefer-dist --optimize-autoloader
```

Conservare commit, lockfile, esiti CI e checksum del pacchetto. Non eseguire `composer update` sull'hosting. Includere `vendor` e `public/build`; escludere `.git`, `.env` di sviluppo, database SQLite, `node_modules`, log/cache/dati di test, chiavi private e backup. Non copiare cache configurazione generate altrove. Le schermate documentali contengono solo dati fittizi e non servono nell'hosting.

## Layout raccomandato

Esempio da adattare all'account reale:

```text
/home/UTENTE/apps/multi-loggia/current/       applicazione + vendor + .env
/home/UTENTE/apps/multi-loggia/current/public/  document root del dominio
/home/UTENTE/secure/backup-public.key          sola chiave PUBBLICA backup
/home/UTENTE/secure/backups/                   archivi cifrati locali
```

Impostare il dominio cPanel sulla `public` del rilascio. `.env`, `storage/app/private`, `storage/logs`, `bootstrap/cache`, `vendor` e sorgenti devono stare **fuori dal web root**. Nessun symlink pubblico allo storage. Directory scrivibili al solo utente applicativo: `storage`, `bootstrap/cache` e cartella backup; preferire 0700 per dati/backup e 0600 per `.env` e segreti. Per codice/asset usare permessi minimi compatibili con il modello PHP-FPM del provider, mai 0777. Usare account DB dedicato alla sola base applicativa; niente utente root. Le migrazioni richiedono DDL: se si separano credenziali di deploy e runtime, verificarne i privilegi senza concedere accesso ad altri database.

## Alternativa sicura se document root non modificabile

Tenere **l'intera applicazione** in `/home/UTENTE/apps/multi-loggia/current`, fuori da `public_html`. Copiare in `public_html` soltanto il contenuto di `public/`: build, favicon, robots, `.htaccess`; sostituire `index.php` con [scripts/cpanel-public-index.php.example](scripts/cpanel-public-index.php.example), impostando il percorso assoluto dell'applicazione. Non copiare il progetto nella directory web e non affidarsi a una denylist del suo contenuto.

Impostare nell'ambiente privato:

```dotenv
APP_PUBLIC_PATH=/home/UTENTE/public_html
```

La configurazione è letta anche dalla CLI, così Vite e i controlli sui percorsi usano la stessa directory pubblica del web. Il front controller non deve contenere credenziali. Vietati symlink dell'applicazione/storage nel web root. Verificare che altri domini dell'account non espongano la directory privata. La `.htaccess` pubblica disabilita gli indici e nega file nascosti/operativi; non sostituisce la separazione fisica. Se il provider impedisce anche questo layout, il piano non è adatto.

## Configurazione privata

Copiare `.env.example` fuori dal web root e valorizzare con dati reali, senza allegarlo a ticket/log:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://DOMINIO-REALE
APP_LOCALE=it
APP_TIMEZONE=Europe/Rome
DB_CONNECTION=mysql
DB_HOST=HOST-REALE
DB_PORT=3306
DB_DATABASE=DATABASE-APPLICATIVO
DB_USERNAME=UTENTE-APPLICATIVO
DB_PASSWORD=SEGRETO-DA-PASSWORD-MANAGER
SESSION_DRIVER=database
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
CACHE_STORE=database
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=90
IDENTITY_DELIVERY_DRIVER=configured
COMMUNICATIONS_ENABLED=false
BACKUP_PUBLIC_KEY_FILE=/home/UTENTE/secure/backup-public.key
BACKUP_DIRECTORY=/home/UTENTE/secure/backups
MYSQLDUMP_BINARY=/percorso/verificato/mysqldump
```

I valori in maiuscolo sono **segnaposto**, non credenziali utilizzabili. Generare `APP_KEY` una sola volta per una nuova installazione (`php artisan key:generate`), salvarla nel deposito segreti e non rigenerarla a ogni rilascio. Conservare anche l'eventuale `OTP_SECRET`. Sul ripristino riusare le chiavi del backup. Una rotazione richiede un progetto di ricifratura: cambiare solo `.env` rende illeggibili i dati.

Database remoto: richiedere TLS verificato, configurare `MYSQL_ATTR_SSL_CA` reale e verificare `Ssl_cipher`; non disattivare la verifica del certificato. In locale preferire socket/localhost secondo il provider. Non modificare l'isolamento InnoDB a READ COMMITTED: i report richiedono snapshot coerenti.

SMTP: `MAIL_MAILER=smtp`, host/porta/username/password reali, mittente tecnico neutro, `MAIL_SCHEME=smtps` su 465 oppure `MAIL_SCHEME=smtp` con `MAIL_REQUIRE_TLS=true` sulla porta STARTTLS del provider. Non impostare `MAIL_URL`: in produzione viene rifiutato per evitare override dei requisiti TLS. Verificare CA, SPF/DKIM/DMARC, dominio mittente, limiti e consegna reale. `array`, `log` e `fake` non sono invii riusciti. Dettagli di OTP e SMS in [IDENTITY-CONFIGURATION.md](IDENTITY-CONFIGURATION.md).

SMS facoltativo: `SMS_DRIVER=http`, `SMS_ENDPOINT` HTTPS reale, `SMS_TOKEN` privato e `SMS_SENDER` autorizzato. Contratto: POST JSON `{to,text,sender}`, Bearer token, successo HTTP e JSON `accepted:true`; redirect disabilitati, timeout 10 secondi. Un provider diverso richiede un adapter/test specifico. Senza configurazione il canale non invia; non selezionarlo per OTP/notifiche prima della verifica.

I proxy TLS non vengono considerati fidati automaticamente. Se il provider termina HTTPS a monte, configurare **solo** proxy/header realmente controllati; non fidarsi indiscriminatamente di `X-Forwarded-Proto` o di tutti gli IP. Abilitare il redirect HTTP→HTTPS nel dominio/vhost cPanel anche per asset e `/up`; le route web applicative rifiutano HTTP in produzione. HSTS è emesso sulle risposte HTTPS applicative; verificare anche la configurazione del server per i file statici.

## Installare o aggiornare

1. Fermare scheduler e worker usando il lock operativo comune; attivare `php artisan down --retry=60` e verificare 503 dal web. Per il primo passaggio alla fase 8, il comando nuovo richiede già le tabelle della fase 8: usare prima un backup cifrato verificato con lo strumento precedente/provider, senza saltare la salvaguardia. Negli aggiornamenti successivi, prima di aggiornamenti con dati, produrre **nuovo backup forzato**, copiarlo cifrato all'esterno e verificarne il catalogo/restore. Non trattare un backup di stamattina come snapshot immediatamente precedente al deploy.
2. Distribuire il rilascio/vendor/asset senza sovrascrivere `.env`, dati privati o chiavi. Con rilasci versionati, condividere in modo controllato storage privato e configurazione fuori dal web root; aggiornare la document root o il percorso privato del front controller. Cache di bootstrap per rilascio, niente symlink pubblico allo storage.
3. Con il PHP corretto eseguire:

```sh
php artisan migrate --force
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan operations:integrity --files
php artisan operations:check --deployment
```

4. Il controllo deployment iniziale può segnalare heartbeat/backup non ancora osservati: risolverli realmente prima del via libera. Non ignorare altri errori. Non utilizzare `migrate:fresh`, `db:wipe` o il seed su dati operativi. `migrate` è ripetibile e la nuova migrazione non riscrive i saldi.
5. Configurare i cron e collaudare password+OTP, revoche, logge diverse, report/export, upload privato e notifiche su account autorizzati. Solo dopo i controlli riprendere il servizio con `php artisan up`. Per il primo Super User utilizzare l'invito ordinario; nessuna password/bypass di emergenza viene generata.

## Cron senza daemon

Usare [scripts/cpanel-cron.sh](scripts/cpanel-cron.sh), dopo aver verificato i percorsi. I processi condividono il lock; l'attesa è limitata a 55 secondi. Il worker usa database queue, termina quando vuoto o dopo il limite temporale e ha un watchdog esterno. Il codice Laravel non avvia worker residenti.

Esempio **da sostituire con percorsi reali**, salvando output in un log privato e disabilitando le email automatiche del cron:

```cron
* * * * * GESTIONALE_ROOT=/home/UTENTE/apps/multi-loggia/current GESTIONALE_PHP=/percorso/php /bin/sh /home/UTENTE/apps/multi-loggia/current/scripts/cpanel-cron.sh schedule >> /home/UTENTE/secure/cron.log 2>&1
* * * * * GESTIONALE_ROOT=/home/UTENTE/apps/multi-loggia/current GESTIONALE_PHP=/percorso/php /bin/sh /home/UTENTE/apps/multi-loggia/current/scripts/cpanel-cron.sh queue >> /home/UTENTE/secure/cron.log 2>&1
30 2 * * * GESTIONALE_ROOT=/home/UTENTE/apps/multi-loggia/current GESTIONALE_PHP=/percorso/php /bin/sh /home/UTENTE/apps/multi-loggia/current/scripts/cpanel-cron.sh backup >> /home/UTENTE/secure/cron.log 2>&1
*/5 * * * * GESTIONALE_ROOT=/home/UTENTE/apps/multi-loggia/current GESTIONALE_PHP=/percorso/php /bin/sh /home/UTENTE/apps/multi-loggia/current/scripts/cpanel-cron.sh check >> /home/UTENTE/secure/cron.log 2>&1
```

Il backup entra temporaneamente in manutenzione e ripristina lo stato precedente; se interrotto da segnale lascia manutenzione per verifica. Un kill forzato richiede controllo manuale. Il wrapper non risolve cron disabilitati, quote o limiti incompatibili: collaudarli. Pianificare copia cifrata esterna e monitoraggio da un sistema indipendente: se cron/DB/SMTP si fermano, l'applicazione può non consegnare i propri alert.

## Backup, ripristino e rollback

Procedura e prova effettiva in [OPERATIONS.md](OPERATIONS.md) e [OPERATIONS-VERIFICATION.md](OPERATIONS-VERIFICATION.md). Conservare archivio cifrato, digest e catalogo esterno fidato, chiave privata offline e registro aggiornato delle cancellazioni. Verificare ripristino su DB isolato prima di dati reali. Il backup cPanel non cifrato non sostituisce questa procedura.

Rollback codice: manutenzione e lock comune; `COMMUNICATIONS_ENABLED=false`, pulizia cache di configurazione, `php artisan communications:pause --cancel` usando il codice della fase 8, poi ritorno al rilascio precedente con i suoi asset e cache ricostruite. **Non fare rollback delle migrazioni**: le nuove tabelle possono restare inutilizzate senza perdere notifiche/privacy. I job `default` e i fatti contabili sono preservati; quelli della coda comunicazioni vengono annullati prima di rimuovere la relativa classe. Verificare nuovamente integrità, accessi e saldi.

Ripristino DB è diverso dal rollback codice: comporta RPO/perdita delle operazioni dopo lo snapshot. Richiede decisione del responsabile, riconciliazione degli eventi successivi e quarantena del runtime ripristinato. Non automatizzare il ritorno a un vecchio backup dopo un errore di deploy.
