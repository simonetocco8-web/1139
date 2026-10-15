# Verifica e configurazione hosting cPanel

Audit 15 ottobre 2026, codice baseline `d2bba13`. L'applicazione risulta pubblicata secondo quanto riferito dall'utente; **non è stato ispezionato il server**. Questo documento indica verifiche da svolgere dal gestore, non configurazioni già applicate. Non inviare .env, chiavi o credenziali in chat/repository. Riferimenti operativi completi: DEPLOYMENT.md e OPERATIONS.md.

## 1. Accertare cosa è stato installato

Registrare commit/versione del pacchetto, checksum, versione PHP web e CLI, versione MySQL reale e data dell'ultimo rilascio. Confrontare asset e lockfile con il commit. Se il server contiene modifiche non versionate, acquisire una copia redatta per un nuovo audit: l'esito sul repository non certifica il contenuto di produzione.

Non eseguire test automatici in produzione. Il solo nome DB_DATABASE non basta a proteggerli (P03); niente PHPUnit/composer test, seed, migrate:fresh, rollback o restore di prova sul database operativo.

## 2. Prerequisiti del piano

| Voce | Richiesta / verifica |
| --- | --- |
| PHP | >=8.3 compatibile con lockfile; web e cron con stessa versione ed estensioni |
| Estensioni | composer check-platform-reqs sul pacchetto runtime; PDO MySQL, mbstring, XML/DOM, curl, fileinfo, openssl, sodium e restanti requisiti Composer |
| Database | MySQL 8.x, InnoDB, utf8mb4, UTC per timestamp tecnici; REPEATABLE READ per report composti |
| Shell/cron | comandi PHP con percorsi assoluti, cron cPanel, flock, timeout; proc_open per mysqldump 8 |
| Risorse | quote disco per privati/backup, memoria PHP per PDF, tempo cron e CPU verificati su carico rappresentativo |
| Servizi | HTTPS valido, SMTP con TLS; eventuale provider SMS col contratto supportato |
| Backup | deposito cifrato esterno all'hosting, chiave privata fuori server, catalogo digest fidato e restore indipendente |

Il codice non imposta l'isolamento REPEATABLE READ e il preflight non lo attesta: verificarlo sulla connessione effettiva. MariaDB non è equivalente al MySQL 8 testato: prima di utilizzarlo serve collaudo specifico di CHECK, colonne generate e concorrenza. Non sono necessari Redis, WebSocket, Node runtime o daemon; Docker è stato usato soltanto nella sandbox di test.

Se flock/timeout/proc_open/client MySQL o una directory pubblica sicura non sono disponibili, definire un'alternativa verificata con il provider; il wrapper attuale non soddisfa i requisiti su quel piano senza tali strumenti.

## 3. Document root e privati

Layout raccomandato, con percorsi esemplificativi da adattare:

```text
/home/UTENTE/apps/gestionale/current/       codice, vendor, .env, storage
/home/UTENTE/apps/gestionale/current/public/ document root del dominio
/home/UTENTE/secure/backups/               soli backup cifrati
/home/UTENTE/secure/backup-public.key      chiave PUBBLICA destinatario
```

Il progetto intero non deve stare nel web root. Verificare che altri domini/subdomini dell'account non espongano la cartella privata. storage/app/private e log/cache non vanno pubblicati né collegati con symlink web. Permessi minimi compatibili col PHP del provider; .env/chiavi 0600, dati/backup preferibilmente 0700, mai 0777. .htaccess richiede mod_rewrite/AllowOverride compatibili, disabilita listing e nega file operativi, ma non sostituisce la separazione fisica.

Se cPanel non permette una document root personalizzata: applicazione fuori public_html; in public_html soltanto contenuto di public, asset e front controller adattato da scripts/cpanel-public-index.php.example con percorso assoluto privato. Impostare APP_PUBLIC_PATH alla cartella pubblica reale anche per CLI. Non copiare .env/vendor/app/storage nel web root e non creare symlink pubblici all'intera applicazione.

Verificare richieste non invasive a percorsi sensibili noti come /.env, /composer.json, /vendor/autoload.php, /storage/logs/laravel.log: atteso nessun contenuto riservato (403/404). Non registrare eventuali risposte sensibili in report pubblici; se esposte, bloccare il servizio e coinvolgere il responsabile.

## 4. Ambiente privato

| Variabili | Valore/controllo richiesto |
| --- | --- |
| APP_ENV / APP_DEBUG | production / false, anche nella CLI cron |
| APP_URL | origine HTTPS reale e affidabile, usata per link email; verificare host e proxy |
| APP_KEY | chiave già utilizzata dai dati; non rigenerare durante aggiornamenti |
| OTP_SECRET | opzionale >=32 caratteri casuali; se assente derivazione da APP_KEY; conservarlo col piano segreti |
| APP_PUBLIC_PATH | vuoto nel layout standard o percorso assoluto della public separata |
| APP_TIMEZONE / APP_LOCALE | Europe/Rome / it |
| DB_CONNECTION / DB_HOST / DB_PORT / DB_DATABASE | mysql e destinazione effettiva confermata, inclusi socket/DB_URL se presenti |
| DB_USERNAME / DB_PASSWORD | account applicativo dedicato, privilegi minimi; nessun accesso ad altri database |
| MYSQL_ATTR_SSL_CA | CA valida se database remoto; verificare TLS e identità server senza disabilitare i controlli |
| SESSION_DRIVER / CACHE_STORE / QUEUE_CONNECTION | database, stessa connessione per outbox/queue e tabella jobs prevista |
| SESSION_ENCRYPT / SESSION_SECURE_COOKIE / SESSION_HTTP_ONLY | true / true / true |
| SESSION_SAME_SITE / SESSION_LIFETIME | lax / durata approvata, esempio corrente 30 minuti |
| DB_QUEUE_RETRY_AFTER | 90 nell'esempio; superiore al timeout worker; coordinare tutti i consumatori |
| IDENTITY_DELIVERY_DRIVER | configured, mai fake su hosting |
| COMMUNICATIONS_ENABLED | false fino al collaudo; poi attivare insieme a regole locali e preferenze |
| BACKUP_PUBLIC_KEY_FILE / BACKUP_DIRECTORY / MYSQLDUMP_BINARY | percorsi assoluti verificati e privati; chiave pubblica valida, mysqldump 8 |
| PRIVACY_POLICY_REFERENCE / PRIVACY_*_RETENTION_DAYS | solo valori approvati; zero disabilita eliminazioni, storico comunicazioni positivo almeno 31 giorni |

La configurazione di produzione forza debug false e alcuni attributi cookie, ma non compensa APP_ENV errato, session encryption disabilitata, privilegi DB eccessivi o una document root sbagliata. .env.example contiene intenzionalmente segnaposto e default locali: non basta copiarlo.

Se DB remoto, verificare anche il TLS del client mysqldump e i privilegi di backup; non affidarsi alla sola riuscita delle query web. Proteggere i backup di cPanel, che potrebbero includere dati e segreti non cifrati dal formato applicativo.

## 5. SMTP, inviti e OTP

MAIL_MAILER=smtp, host/porta/username/password reali, mittente tecnico neutro autorizzato. SMTPS 465 con MAIL_SCHEME=smtps oppure STARTTLS con MAIL_SCHEME=smtp e MAIL_REQUIRE_TLS=true sulla porta del provider. MAIL_URL non usato: il trasporto applicativo lo rifiuta in produzione per evitare override dei requisiti TLS.

Controllare certificati/CA, SPF/DKIM/DMARC, quote e antispam. Provare un invito e login OTP verso account di collaudo autorizzati; verificare tempi reali inferiori ai cinque minuti e messaggi neutrali senza appartenenza o posizione economica. SMTP accettato non significa consegna finale; log e contenuto OTP non vanno riversati in ticket.

Prima installazione vuota: primo Super User con identity:invite-superuser verso una casella verificata tramite invito; nessuna password predefinita. **Su installazione già avviata non eseguire bootstrap, seed o manipolazioni degli utenti per aggirare accessi mancanti.** Gestire recupero come procedura di identità separata, senza bypass OTP.

SMS opzionale: driver http, endpoint HTTPS, token privato, sender autorizzato. Contratto corrente POST JSON to/text/sender con Bearer, HTTP successo + accepted:true, no redirect. Se il provider non lo supporta occorre adapter dedicato. Senza provider non abilitare SMS come fattore. Prevedere gestione SIM swap, numero riciclato e perdita del canale.

## 6. Scheduler e database queue senza processi residenti

Usare scripts/cpanel-cron.sh col PHP CLI corretto. Il wrapper usa flock comune per schedule, queue, check e backup; il worker termina con coda vuota o limite di tempo, con watchdog esterno. Non avviare altri worker non coordinati, Supervisor o doppie righe cron ereditate.

Modalità da configurare nei cron cPanel con GESTIONALE_ROOT e GESTIONALE_PHP assoluti:

| Frequenza prevista | Modalità | Verifica |
| --- | --- | --- |
| Ogni minuto | schedule | schedule:run, heartbeat e task dovuti |
| Ogni minuto | queue | queue:work database, communications e default, esecuzione limitata |
| Secondo monitoraggio approvato | check | stato operativo/alert, oltre al check già schedulato |
| Giornaliera in finestra concordata | backup | manutenzione + lock, backup locale cifrato; non sostituisce copia offsite |

Task interni: capitazioni 01:15, canoni 01:30, integrità 02:00, retention autorizzata 03:00, promemoria 08:00, dispatch/heartbeat ogni minuto, controlli ogni quindici minuti e anteprime CSV ogni ora (Europe/Rome). Adeguare conversione fuso cron del provider e verificare cambio ora legale; non assumere che cPanel usi Europe/Rome. Lo schedule:list osservato prova la definizione, non l'esecuzione reale.

Registrare durata, exit code e output tecnico in area privata, con rotazione. Verificare heartbeat recente, backlog/failed_jobs, outbox blocked/uncertain e tentativi. Un comando che non produce messaggi economici con opt-in disabilitato non dimostra un guasto. Contenere i recuperi canoni pro capite finché P01 non è risolto.

## 7. Asset, migrazioni e rilascio

Node necessario solo in build: npm ci + npm run build dal lockfile; caricare public/build con manifest e file referenziati, oltre a vendor runtime da composer install --no-dev. Non copiare cache di un altro ambiente o directory di sviluppo. Il browser deve caricare asset locali senza Vite dev server/CDN obbligatori.

Le 14 migrazioni sono reali e la suite le applica sul DB isolato. Per conoscere lo stato dell'hosting usare il registro migrations e, dal gestore autorizzato, migrate:status; non eseguire migrate o rollback come parte dell'audit. Un aggiornamento futuro richiede backup coerente già ripristinato in test, manutenzione/lock e piano di compatibilità. Evitare down distruttivi sulle tabelle finanziarie; il rollback del codice deve preservare dati e storia. Per primo upgrade da prima della fase 8, il suo comando backup non esiste ancora o non ha le tabelle: serve un metodo precedente/provider cifrato verificato prima delle nuove migrazioni.

Config/route/view cache si generano sulla destinazione corretta dopo configurazione; non sono una prova di autorizzazione o readiness. Il preflight `operations:check --deployment` interroga DB/stato e non applica migrazioni, ma --alert scrive outbox: il gestore può eseguire il controllo senza --alert e gestire privatamente gli esiti. In questo audit non è stato lanciato sul server.

## 8. Backup, restore e privacy

Automatizzare backup locale con wrapper e copia cifrata su deposito esterno indipendente: quest'ultima non è fornita dal codice. Catalogo checksum fidato e chiave privata custoditi separatamente; l'archivio decifrabile non autentica da solo l'origine. Conservare APP_KEY/OTP_SECRET e configurazioni/provider in deposito segreti; dump e file privati devono essere ripristinati insieme.

Provare restore su nuovo DB/storage isolati, mai sopra produzione, con confronto saldi, obblighi, credito, allegati e audit. Quarantena obbligatoria prima della riapertura dei vecchi account: sessioni/token/job/assistenze revocati e account disattivati. Definire e provare riattivazione individuale, contatti attuali e diritti correnti; nessun sblocco indiscriminato o invito bootstrap per aggirare il blocco.

Approvare retention, gestione cancellazioni successive allo snapshot, log provider, esportazioni e distruzione sicura dei temporanei decifrati. Stabilire RPO/RTO e monitor indipendente per mancanza backup, cron e consegna OTP. Il progetto non offre un'automazione completa di queste responsabilità.

## Verbale hosting richiesto

Dominio e commit: ______. Provider/piano: ______. PHP web/CLI: ______. MySQL/isolamento: ______.
Document root/privati verificati: ______. HTTPS/cookie/proxy: ______. SMTP/SMS reali: ______.
Cron/queue e limiti: ______. Ultima copia offsite/restore/RPO/RTO: ______. Responsabile privacy e policy: ______.
Esiti negativi, mitigazioni e accettazione del responsabile: ______.
