# Gestione operativa

Consultare prima [DEPLOYMENT.md](DEPLOYMENT.md), [SECURITY.md](SECURITY.md) e la checklist. I comandi privilegiati sono **solo CLI del server**, non API o bypass di accesso. Non eseguire operazioni di prova su database reali.

## Notifiche e preferenze

Centro locale `/logge/{lodge}/notifiche`, centro tecnico `/admin/notifiche`. Il Super User vede soltanto gli alert tecnici globali; membership e assistenza non conferiscono automaticamente diritto a ricevere notifiche locali. I destinatari di background devono avere un ruolo **ordinario** attivo, account attivo/attivato e email verificata; l'assistenza temporanea non è un'iscrizione alle comunicazioni.

Tipi: capitazione prossima/scaduta, canone prossimo/non pagato, comunicazione amministrativa e alert tecnico. `COMMUNICATIONS_ENABLED=false` per default. Prima dell'abilitazione globale verificare consegna e policy; poi l'amministratore locale abilita le singole tipologie per la loggia. Ruoli ammessi in `config/communications.php`: Fratello riceve solo proprie capitazioni e comunicazioni essenziali; Amministratore/Tesoriere anche canoni se conservano `reports`. Nessun invio a email amministrative generiche prive di account/autorizzazione.

Ogni utente può disabilitare un tipo nel proprio ambito o scegliere email/SMS su recapito verificato; non può abilitare tipi esclusi dal ruolo o dalla loggia. I giorni di preavviso (0–30) si configurano nelle Impostazioni della loggia. `COMMUNICATIONS_OVERDUE_INTERVAL_DAYS` (1–30, default 7) definisce finestre ancorate al primo giorno dopo la scadenza. Un avviso prossimo per obbligazione/destinatario/scadenza; uno scaduto per finestra. Variazioni di importo non generano duplicati dello stesso avviso. Le obbligazioni sono rilette prima dell'invio: saldo, revoca, disattivazione, preferenza o proprietario cambiati possono annullarlo.

Email/SMS: oggetto e corpo neutrali, solo accesso alla dashboard tramite APP_URL fidato; nessun nome di loggia, elenco di persone, importo o testo amministrativo. I dettagli economici rimangono nelle pagine autorizzate. Il testo delle comunicazioni amministrative è cifrato nel DB e mostrato solo al destinatario o amministratore ordinario locale; il compositore avverte di non inserire dati di terzi. Invii amministrativi richiedono conferma e chiave di idempotenza. L'autore rimane responsabile del contenuto essenziale: non esiste una classificazione automatica capace di riconoscere ogni dato personale nel testo libero.

## Outbox, esiti e concorrenza

Outbox univoca per tenant/tipo/sorgente/destinatario/periodo. Il job contiene soltanto l'ID dell'avviso, mai recapiti, testo, OTP o importi. Il pump registra job e lease nella **stessa transazione/connessione** del database applicativo; database queue con `after_commit=false`, tabella `jobs`, niente connessione di coda separata. Lease di accodamento 10 minuti evita di saturare la coda con copie durante un normale arretrato; anche dopo una riaccodatura, il claim serializzato impedisce due invii dello stesso avviso.

| Stato | Significato e intervento |
| --- | --- |
| pending | In attesa di elaborazione |
| sending | Tentativo preso in carico e committato prima del trasporto |
| accepted | Il server SMTP/provider ha accettato; **non** certifica consegna o lettura |
| blocked | Canale non configurato/recapito mancante; nessuna accettazione simulata |
| uncertain | Possibile accettazione prima dell'errore/interruzione; verificare il provider |
| cancelled | Non più autorizzato, non più dovuto, scaduto o annullato dall'operatore |

Disponibilità mancante: massimo tre tentativi automatici, distanziati almeno 15 minuti. Un normale duplicato di job non aggiunge tentativi. Eccezione del trasporto o worker fermato dopo il claim: stato incerto, **nessun reinvio automatico**. Un claim in corso da più di cinque minuti viene riconosciuto come interrotto. Gli avvisi non presi in carico scadono dopo 24 ore; il successivo ciclo valuta nuove scadenze, senza spedire indiscriminatamente un arretrato di messaggi vecchi.

L'amministratore ordinario locale, o il Super User per soli alert tecnici, può autorizzare un altro tentativo da storico, dopo verifica del provider e conferma del rischio di duplicazione. Permessi, preferenze, destinatario e residuo sono rivalidati; il consenso autorizza un tentativo ulteriore anche dopo tre blocchi, fino a dieci tentativi totali. Non si azzera lo storico. Un invio già accettato non si ripete tramite questo comando. L'esito è registrato senza recapiti o errori grezzi. Non è possibile garantire “exactly once” tra DB e SMTP/SMS: manca una transazione distribuita e il contratto del provider non espone ricevute/idempotenza. Lo stato incerto rende questa limitazione esplicita.

## Scheduler e coda limitata

| Processo | Frequenza Europe/Rome |
| --- | --- |
| Generazione capitazioni | 01:15, job database per loggia |
| Generazione canoni | 01:30, job database per loggia |
| Verifica finanziaria | 02:00, sola lettura e alert su anomalie |
| Conservazione approvata | 03:00; disabilitata finché manca la politica |
| Preparazione promemoria | 08:00 |
| Pump comunicazioni e heartbeat | Ogni minuto |
| Alert tecnici | Ogni 15 minuti |
| Anteprime CSV temporanee | Ogni ora, TTL di sicurezza dei moduli esistenti |

Tutti i task schedulati hanno lock contro sovrapposizioni; generatori con lock obbedienza/loggia e chiavi uniche già verificati su MySQL. I job di generazione si possono ripetere senza nuove rate duplicate o movimenti finanziari. Il wrapper cPanel usa un lock comune a scheduler, worker e backup, attende al massimo 55 secondi e limita esternamente il worker a 50 secondi (più cinque per terminazione forzata), anche senza PCNTL. `--max-time=35`, `--timeout=30`, `retry_after=90`: verificare queste durate e i limiti effettivi dell'hosting. Se il watchdog interrompe un invio, l'outbox lo considera incerto; se interrompe una transazione di generazione, il DB la annulla e il job può essere ripetuto.

Comandi manuali senza inviare denaro:

```sh
php artisan capitation:generate --queued
php artisan levy:generate --queued
php artisan communications:prepare
php artisan communications:dispatch
php artisan operations:check --deployment
php artisan operations:integrity --files
```

Eseguire con il lock del wrapper quando il sistema è in uso. Il worker deve leggere **communications,default**, non solo `default`. Per un guasto controllare stato delle code e cron; correggere la causa prima di `queue:retry`. Non ritentare alla cieca invii incerti o tutti i failed_jobs. I job finanziari ripetibili non autorizzano nuovi pagamenti: generano solo obbligazioni.

## Alert e monitoraggio

`operations:check --alert` segnala heartbeat oltre cinque minuti/assente, failed_jobs, job in attesa da oltre 15 minuti (configurabile), invii incerti, backup assente/oltre 26 ore (configurabile) o backup recente fallito/in corso. Deduplica per problema/destinatario/giorno. Il centro piattaforma espone codici descritti, non payload, stack trace o dati delle logge. `operations:integrity --alert` aggiunge un avviso neutro se falliscono gli invarianti.

Il comando `--deployment` controlla anche ambiente production, URL HTTPS, debug, driver DB/session/cache/queue, cookie, chiavi, SMTP configurato, strumenti host, migrazioni, asset, cartella pubblica, politica privacy e amministratore attivo. È un controllo **locale**, non una prova di consegna SMTP/SMS o raggiungibilità da Internet. `operations:integrity` verifica bilanciamento delle operazioni, saldi dei conti/report, residui e credito non negativi, collegamenti/importi degli incassi/pagamenti, storni, cassa storica e audit dei posting; `--files` aggiunge decifratura, dimensione e digest degli allegati. Non modifica saldi o obbligazioni e non sostituisce una revisione contabile.

Se DB, cron, hosting o SMTP sono indisponibili, la coda non può garantire i propri alert. Serve un monitor **esterno** su disponibilità HTTPS, exit status dei cron, quota disco, backup/copia esterna e heartbeat, con finestre di manutenzione approvate. `/up` indica solo vita PHP, non salute DB/queue/mail. Nessun alert esterno è dichiarato configurato in questa consegna.

## Backup automatico cifrato

1. Su macchina fidata/offline con il codice e vendor installati generare:

```sh
php artisan operations:backup-keygen /percorso/nuovo/offline
```

   Conservare `backup-secret.key` offline con escrow protetto. Copiare sul server **solo** `backup-public.key`. Impostare BACKUP_PUBLIC_KEY_FILE e BACKUP_DIRECTORY privati, fuori da public e fuori da storage/app/private.
2. Configurare il cron `backup` del wrapper. Esso ferma il normale accesso con manutenzione e lock comune; non sovrapporre comandi manuali, altri worker o writer esterni. `operations:backup` rifiuta il sistema non in manutenzione, DB non MySQL o tabelle non InnoDB. Usa mysqldump 8 `--single-transaction --quick`, senza CREATE DATABASE/USE né privilegi del server; file credenziali temporaneo 0600, rimosso anche su errore.
3. Il dump passa direttamente al flusso cifrato, senza SQL temporaneo in chiaro. L'archivio contiene database, file privati e bundle APP_KEY/OTP_SECRET; **non** copia tutto `.env`, vendor o i segreti SMTP/SMS. Tenere configurazione e credenziali recuperabili nel deposito segreti separato. Ogni file ha hash/dimensione autenticati; stream XChaCha20-Poly1305 con tag finale e chiave casuale sigillata al destinatario X25519. Scrittura `.partial`, sincronizzazione e rename finale; solo dopo viene registrato completed con SHA-256/fingerprint.
4. Un secondo backup ordinario dello stesso giorno UTC restituisce quello già completato se file/digest/chiave coincidono. Per uno snapshot nuovo prima di un deploy usare modalità wrapper **backup-force**. Mancanza di client, chiave, quota, privilegi o file rende l'esito fallito, mai simulato.
5. Copiare **automaticamente gli archivi già cifrati** su storage esterno controllato, con versioni/immutabilità e verifiche di hash secondo la policy. La destinazione e le credenziali non sono state fornite: la replica remota non è configurata né collaudata. Mantenere un catalogo esterno fidato di data, commit, SHA-256, fingerprint e stato della prova di restore. La cifratura alla chiave pubblica protegge la riservatezza, non autentica da sola chi ha creato l'intero archivio: il digest deve provenire da questo catalogo separato e attendibile.

Non cancellare automaticamente backup o log in base a durate inventate. Definire RPO/RTO, retention, escrow/rotazione delle chiavi e monitoraggio quota con il titolare. Perdere la chiave privata rende inutilizzabili i backup; perderne una versione impedisce di ripristinare gli archivi associati. Un backup sullo stesso hosting non protegge dal suo completo guasto o compromissione.

## Prova e procedura di ripristino

Eseguire su macchina **isolata**, con invii disabilitati e DB dedicato senza privilegi su altre basi. Prima del ripristino verificare versione MySQL/PHP, commit e disponibilità della chiave privata corretta. Il test automatico ha ricreato da zero il DB MySQL sacrificabile e confrontato saldi, report e allegato: non è una prova dei tempi/limiti del futuro hosting.

```sh
php artisan operations:restore-check /archivio/backup.mlb /directory/nuova/privata --secret-key-file=/offline/backup-secret.key --sha256=DIGEST_DAL_CATALOGO_FIDATO
```

Il comando verifica digest esterno, cifratura, tag finale, percorsi e hash dei file; non sovrascrive directory preesistenti e non importa DB. I file estratti sono privati ma **in chiaro**: proteggerli e rimuoverli dopo il collaudo. Il limite di estrazione è 50 GiB, senza decompressione. Importare `database.sql` con un client `mysql` fidato, `--binary-mode=1 --local-infile=0`, credenziali da file 0600, destinazione isolata selezionata esplicitamente; mai password nella riga di comando o database operativo per la prova. Ripristinare private/ in storage/app/private, APP_KEY e OTP_SECRET dal bundle nel deposito privato del ripristino e ricostruire cache. Gli altri segreti vengono riconfigurati dal password manager.

**Prima di abilitare login, cron o invii sul DB ripristinato**, in manutenzione:

```sh
php artisan operations:quarantine-restored --confirm-restore
php artisan operations:integrity --files
```

La quarantena disattiva **tutti gli account**, incrementa la versione credenziali, rimuove sessioni/reset/job recuperati, revoca inviti e assistenza, invalida OTP/codici offline e annulla comunicazioni pendenti; quelle in invio restano incerte. Conserva persone, registrazioni finanziarie e audit. È necessario: una vecchia copia può contenere password, recapiti o ruoli successivamente revocati. Non riattivare in massa gli account o fidarsi automaticamente dei recapiti/ruoli del backup. La riabilitazione richiede verifica umana documentata dell'identità, recapiti attuali, credenziali e autorizzazioni con il titolare e l'operatore privilegiato; **non è automatizzata da un endpoint o da un bypass CLI**. Fino alla conclusione della procedura di recupero approvata il ripristino resta chiuso.

Dopo ogni cancellazione autorizzata esportare il registro minimo cifrato:

```sh
php artisan privacy:erasure-register /archivio/esterno-preparato/registro-nuovo.mlb
```

Conservare l'ultima versione all'esterno, separatamente dai vecchi backup. Si può decifrare offline con `operations:restore-check ... --purpose=erasure-register --secret-key-file=... --sha256=...`. Contiene solo ID locali/richiesta, riferimento della policy e data, non nomi/recapiti. Prima di usare un vecchio restore, riconciliare questo registro e le rettifiche/limitazioni successive allo snapshot, riapplicando le decisioni approvate attraverso la procedura privacy. Anche le copie di backup seguono la retention approvata; una cancellazione dal DB corrente non cancella magicamente gli archivi precedenti.

Confrontare saldi per conto, cronologia, totali rate/canoni, parziali/credito/storni e allegati con il catalogo di verifica. Registrare tempi, commit, data snapshot e perdita massima degli eventi successivi. Riprendere servizio e cron solo dopo sign-off; creare nuovo backup verificato. Non copiare la chiave privata di restore sull'hosting ordinario.

## Privacy e conservazione

Pagina `/logge/{lodge}/privacy`: interessato vede solo i propri soggetti/richieste locali, amministratore ordinario gestisce la propria loggia. Richieste/esiti cifrati; conferme e audit. JSON personale autenticato disponibile al titolare dei dati anche senza permesso di esportazione gestionale; l'amministratore deve avere anche `export`. Esclude credenziali, altri tenant e documenti con dati di terzi; questi richiedono revisione e consegna separata.

Rettifica: usare la scheda autorizzata con versioni/storico, poi registrare la valutazione/esito della richiesta. Non riscrivere movimenti contabilizzati; correggerli con storno/rettifica dei moduli finanziari. Account globale, iscrizione e permessi sono separati: una richiesta locale non autorizza modifiche alle altre logge.

Configurare solo dopo approvazione:

| Variabile | Effetto |
| --- | --- |
| PRIVACY_POLICY_REFERENCE | Identificativo documentale approvato, non nominativi o testo sensibile |
| PRIVACY_TECHNICAL_RETENTION_DAYS | OTP scaduti, reset e sessioni già scadute; zero disabilita |
| PRIVACY_COMMUNICATION_RETENTION_DAYS | Storico accettato/annullato e testo senza destinatari residui; zero disabilita, minimo 31 giorni per deduplica |
| PRIVACY_PERSON_RETENTION_DAYS | Soglia dopo fine di tutte le iscrizioni per cancellazione umana esplicita, mai automatica; zero disabilita |

`php artisan operations:prune` è **sola anteprima**. `--apply` richiede riferimento approvato e applica soltanto durate positive; nessun movimento, pagamento, obbligo o audit finanziario viene eliminato. Invii incerti restano conservati per la verifica, non vengono “ripuliti” nascondendo il problema. TTL delle anteprime temporanee CSV resta quello di sicurezza dei moduli, distinto dalla conservazione dei fatti contabili.

La cancellazione locale elimina persona, iscrizioni concluse, storico anagrafico e relativi inviti solo se il periodo approvato è decorso e non esistono riferimenti economici, incluse regole individuali e snapshot pro capite dei canoni. Lock condivisi e FK proteggono da corse con i generatori. Se esistono riferimenti, il sistema rifiuta l'operazione: registrare motivazione, limitare gli accessi e conservare quanto necessario. Account globale, richieste/audit necessari alla prova, contenuti liberi e allegati richiedono valutazione separata; non si dichiara “cancellazione totale” di ogni copia o sistema esterno. Non è implementata una distruzione automatica della contabilità a fine conservazione, né la cancellazione indiscriminata dell'account globale: servono archiviazione/dismissione approvate e verifica dei diversi ambiti.

## Incidenti e segreti

Sospendere invii, isolare accessi, preservare prove private e coinvolgere responsabili indicati nella policy. La schermata account permette revoca sessioni; revocare assistenze e ruoli locali se necessario. In caso di sospetta compromissione dell'hosting, considerare esposti DB, APP_KEY e credenziali dei provider: la sola cifratura applicativa non protegge dall'esecuzione sul server. Ruotare segreti secondo piano, non rigenerare APP_KEY cancellando l'accesso alla storia.

Log, failed_jobs, dump decifrati e backup non vanno incollati in email/ticket non autorizzati. I log di produzione omettono messaggi/trace delle eccezioni applicative e valori SQL; conservare output dei cron solo in area privata. Provider/cPanel/access log possono avere proprie registrazioni: configurarli, limitarli e approvarne la retention. Non eseguire pruning globale di cache o queue a caso; dopo correzione usare le procedure specifiche e verificare gli invarianti finanziari.
