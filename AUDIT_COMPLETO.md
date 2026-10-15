# Audit completo del gestionale multi-loggia

Data: 15 ottobre 2026. Baseline esaminata: `d2bba13cf6a055e60811fc939636d6acfc1d9fd4`, coincidente con `main` remoto al controllo. L'utente riferisce l'avvenuta pubblicazione: **versione e configurazione effettive del server non verificate**. Nessun accesso al dominio o al database di produzione, nessuna modifica applicativa e nessuna migrazione su produzione.

## Valutazione

Le fasi 0–8 contengono implementazioni reali e integrate; il progetto non è una raccolta di schermate dimostrative. La suite preesistente passa, ma due prove aggiuntive di audit falliscono su conteggio storico dei canoni e riconciliazione degli storni. La protezione contro l'esecuzione accidentale dei test sul database sbagliato è incompleta. **Non è possibile concedere un via libera complessivo all'uso sicuro di dati reali** prima degli interventi ad alta priorità e del collaudo dell'installazione.

Non è stato riprodotto un bypass OTP o un accesso fra tenant nei casi esaminati. Questa constatazione è limitata al codice, ai test e ai percorsi provati; non equivale a un pentest esaustivo né a una certificazione privacy.

## Metodo e stati

Fonti: richiesta originaria allegata, richieste delle fasi 1–8, REQUIREMENTS.md, ARCHITECTURE.md, codice e migrazioni effettivi. Le dichiarazioni nei documenti precedenti sono state confrontate con l'implementazione, non assunte come prova.

- **Implementato**: percorso applicativo reale individuato e controllato, con copertura automatica o verifica indicata. Non significa copertura di ogni possibile caso.
- **Parziale**: requisito coperto con lacune, vincoli o difetti descritti.
- **Assente**: funzione richiesta non individuata nel modello e nei percorsi applicativi.
- **Non verificato**: servono ambiente, accesso o evidenze non disponibili.

## Struttura reale

Monolite Laravel 13, Fortify, Livewire 4, Blade/Tailwind e Vite; PHP dichiarato ^8.3. Inventario: **160 file PHP in app, 14 migrazioni, 124 route totali di cui 110 con nome** al momento del controllo. Le route comprendono anche endpoint del framework: il numero non prova il funzionamento di tutte le combinazioni di parametri.

Domini: Organization, Identity, Membership, Capitations, Levies, Treasury, Reports, Notifications, Operations e Privacy. Audit implementato in `app/Domains/Identity/Audit.php` e registro organizzativo, non in un dominio Audit separato. Controller per moduli locali, Livewire per organizzazione/accessi/sicurezza. Scheduler in `routes/console.php`, wrapper cPanel in `scripts/cpanel-cron.sh`. CSV/PDF sono generati realmente, senza un servizio remoto obbligatorio.

Le autorizzazioni sono distribuite tra `TenantContext`, `TenantAccess`, classi Access, Action e Policy organizzative. Non esiste uno scope Eloquent globale che renda sicura qualunque query nuova: i servizi interni Sources/Generator presuppongono chiamanti controllati. I vincoli compositi aggiungono protezione sulle relazioni; estensioni future devono conservare entrambi i livelli.

## Matrice delle fasi 0–8

| Fase / requisito | Stato | Evidenza e limite |
| --- | --- | --- |
| 0: stack, struttura modulare, locale italiano/EUR/Europe-Rome | Implementato | composer.json, config/app.php, domini, asset compilati; build e piattaforma controllate |
| 0: homepage, health, assenza registrazione pubblica | Implementato | routes/web.php, bootstrap/app.php, FoundationTest; testi pubblici obsoleti P11 |
| 1: obbedienze, riti e compatibilità | Implementato | OrganizationActions, Manager, modelli e FK; modifica/disattivazione, ricerca |
| 1: livelli nazionali/regionali/provinciali opzionali, gerarchie | Implementato | validazione parent, stessa obbedienza, prevenzione cicli e catene incoerenti |
| 1: logge, numero univoco per obbedienza | Implementato | UNIQUE composita e validazione; stesso numero in altra obbedienza ammesso |
| 1: trasferimenti territoriali senza perdita dello storico | Implementato | TerritorialAssignment versionato, microsecondi, FK RESTRICT; snapshot canoni |
| 1: divieto cancellazione strutture storiche | Implementato | modelli rifiutano delete e relazioni RESTRICT; interfaccia usa disattivazione |
| 1: selettore loggia attiva | Implementato | ActiveLodge, LodgeSelector; controllo di membership/assistenza a ogni risoluzione |
| 2: inviti e primo Super User | Implementato | identity:invite-superuser, token digest, 24 ore, lock iniziale, SMTP; nessuna password predefinita |
| 2: ruoli locali e permessi | Implementato | lodge_user / lodge_user_roles, sette permessi; matrice configurabile per ruolo, non editor di ACL individuali |
| 2: password + OTP prima della sessione completa | Implementato | pipeline Fortify personalizzata, OtpService, VerifiedSession; casi di bypass e consumo concorrente coperti |
| 2: recupero password, contatti, recupero fattore, revoca sessioni | Implementato | password + OTP recente per azioni sensibili; codici offline monouso, OTP nuovo recapito; nessun login da reset |
| 2: Super User senza accesso locale automatico, assistenza | Implementato | consenso di amministratore distinto, scope, max 60 minuti, revoca e audit |
| 2: SMTP e SMS configurabile | Parziale | SMTP reale e adapter HTTPS reali; fake solo testing. SMS richiede contratto POST JSON specifico e integrazione verificata col provider P08 |
| 3: persona, iscrizione, account e ruoli distinti | Implementato | member_people, member_enrollments, users, membership/ruoli; persona locale associabile allo stesso account su più logge |
| 3: elenco, ricerca, stati, date, esenzioni, storico, inviti | Implementato | Members, MemberController, storico cifrato; chiusura iscrizione non revoca automaticamente i ruoli, scelta documentata |
| Requisito originario: note amministrative generiche facoltative | Assente | né campo dedicato né form; exemption_reason e motivazioni economiche non equivalgono a note anagrafiche P12 |
| 3: importazione CSV con anteprima/conferma ed export | Implementato | validazione, anteprima cifrata session-bound, atomica, limiti e formule neutralizzate |
| 3: impostazioni loggia e responsabili | Implementato | anagrafica/preferenze editabili localmente; obbedienza/rito/territorio visibili, modificabili dal Super User; testi obsoleti P11 |
| 4: piani e quattro periodicità, scadenze e prorata esplicito | Implementato | Calendar, Plans, Generator, versioni; centesimi interi e DECIMAL(15,2) |
| 4: esenzioni/sconti/importi individuali, parziali, multi-rata e credito | Implementato | Payments, Allocation, Correction; credito imputato senza creare un secondo movimento |
| 4: cessazioni e variazioni durante rate già generate | Parziale | snapshot conservato; eventuale conguaglio richiede rettifica esplicita, non ricalcolo automatico. Verificare regola operativa col titolare |
| 4: scadenzario, morosi, versamenti e prospetto personale | Implementato | route/controller e test; storico versamenti caricato integralmente nella scheda P10 |
| 5: regole per obbedienza/struttura/loggia e validità | Implementato | Program/Condition append-only, priorità loggia > struttura > obbedienza, confini di ciclo |
| 5: conteggio pro capite ricostruibile | Parziale | criterio/data/snapshot salvati per obblighi generati; arretrati creati dopo variazioni usano stato/esenzione correnti P01 |
| 5: destinatario storico, unicità, parziali e saldo senza uscite anticipate | Implementato | assegnazione storica, UNIQUE per programma/loggia/periodo; gateway reale transazionale |
| 6: banche/casse, aperture, incassi/spese/giroconti/rettifiche/storni | Implementato | Ledger e righe bilanciate; saldi derivati, idempotenza, cassa negativa storica bloccata |
| 6: integrazione con capitazioni e canoni | Implementato | binding reali ReceiptGateway/DisbursementGateway; source links e void per storni |
| 6: allegati privati, registri e CSV/PDF | Implementato | cifratura, download autorizzato, MIME/dimensione, export privati; antivirus non presente |
| 6: import estratti conto e riconciliazione | Parziale | anteprima, riferimenti univoci, approvazione e abbinamento reali; storni e originali stornati esclusi P02 |
| 7: dashboard piattaforma / staff / Fratello | Implementato | Runtime tecnico minimizzato, staff finanziario, proprietà locale del Fratello; nessuna lettura economica automatica del Super User |
| 7: report di periodo, categorie, saldi, capitazioni, arretrati, canoni, annuale | Implementato | Builder/Document/Sources condivisi con export, snapshot MySQL; obbligazioni mostrano residuo attuale, non saldo storico alla data |
| 7: tesoreria visibile al Fratello secondo preferenze | Parziale | sola vista aggregata esplicita; non esiste condivisione selettiva di singoli movimenti col ruolo brother |
| 7: responsive, date/euro e feedback | Parziale | controlli browser indicati sotto, card e filtri; importi con punto in parti del prospetto personale, WCAG completo non verificato P11 |
| 8: sei tipi notifiche, preferenze, storico, tentativi/deduplica | Implementato | outbox reale, canale verificato ricontrollato, contenuti esterni neutri; accepted non certifica consegna finale |
| 8: scheduler e database queue senza daemon | Implementato | generatori accodabili, prepare/dispatch/heartbeat/integrity/prune; flock e timeout wrapper |
| 8: hardening applicativo | Implementato | HTTPS produzione, cookie, CSRF, escaping, session version, file privati, errori minimizzati; rischi residui P07/P09 |
| 8: privacy export/rettifica/cancellazione/conservazione | Parziale | richiesta, export locale, rettifica versionata, cancellazione non finanziaria condizionata; account globale/allegati/backup e fine conservazione contabile richiedono procedura P06 |
| 8: backup cifrato e ripristino | Parziale | dump reale, stream sodium, digest fidato esterno, restore verificato; copia esterna automatica e riattivazione post-quarantena non automatizzate P04 |
| 8: documentazione deployment/rollback e checklist | Implementato | DEPLOYMENT.md, SECURITY.md, OPERATIONS.md, ACCEPTANCE-CHECKLIST.md |
| Installazione effettiva su cPanel | Non verificato | versione distribuita, document root, segreti, TLS, SMTP/SMS, cron, isolamento MySQL e copia esterna non ispezionati P05 |

## Controlli di sicurezza e integrazione

Il tenant viene risolto dalla sessione e dalle associazioni autorizzate, poi confrontato con gli identificativi URL; il cambio loggia invalida le azioni montate sul tenant precedente. Letture personali vincolate a persona.user_id e lodge_id. Export/file hanno controlli server; le protezioni Livewire persistono e i componenti rivalidano i permessi. Nessun Gate::before globale per Super User.

OTP: random_int su sei cifre, HMAC con separazione di dominio e segreto server, durata cinque minuti, cinque tentativi, sessanta secondi tra reinvii, limiti account/IP e invalidazione precedente. La challenge è consumata e committata prima di Auth::login; sessione rigenerata e attestata con versione credenziali. Challenge fittizia e risposte uniformi per credenziali errate; canale mancante non dichiarato spedito. Email/SMS restano fattori non resistenti al phishing: P07.

SQL dinamico osservato usa parametri/allowlist e clausole fisse; non è stata individuata concatenazione di input utente in SQL eseguibile. Form POST sotto web/CSRF, Blade con escaping; CSP ammette inline/eval per Livewire. Mass assignment protetto nei servizi tramite validazione/allowlist: alcuni modelli interni sono intenzionalmente permissivi, non riutilizzarli direttamente su request->all(). PDF con risorse remote/PHP/JS disabilitati; CSV con neutralizzazione delle celle testuali. Nessuna route placeholder 501 individuata. Le route registrate e le viste compilano, ma non è stata provata ogni route con ogni ruolo e ogni parametro.

I vecchi adapter UnavailableReceiptGateway/UnavailableDisbursementGateway restano nel codice per il contratto fail-closed e i test: **non sono i binding operativi**. FakeTransport accetta soltanto testing. TOTP/passkey e connettori bancari automatici non sono implementati; TOTP/passkey erano predisposizioni future, non obblighi delle fasi concluse.

La contabilità usa operazioni e righe, non un campo saldo liberamente editabile. Le transazioni condivise includono incasso/pagamento e obbligazione; allocazioni non sono nuovi incassi. Gli storni sono nuovi fatti e riaprono residui, senza delete. Giroconti hanno sole righe finanziarie, quindi nessuna entrata/uscita economica consolidata. Integrità e report sono coerenti nelle fixture testate; restano i difetti di integrazione e ricostruzione P01/P02.

## Esecuzioni di questo audit

Snapshot di soli file Git in `/workspace/work/audit9/repo`, senza copiare .env, database o storage operativi; dipendenze copiate e autoload rigenerato nella sandbox. Ambiente esplicito testing, SQLite in memoria, mail array e session/cache isolate per suite; MySQL 8.0.46 in container effimero su loopback 3319, database `audit9_test`, credenziali casuali di test. Le migrazioni distruttive del restore test hanno interessato esclusivamente questo database sacrificabile. Nessuna credenziale produzione utilizzata. Browser con SQLite separato e soli account/dati fittizi, SMTP sink loopback 2599. Docker e Node non sono dipendenze runtime dell'hosting.

| Prova | Esito effettivo |
| --- | --- |
| composer test: Pint + Larastan livello 7 + SQLite | **Superato: 320 test, 1.490 asserzioni**, Larastan 207 file, zero errori |
| PHPUnit MySQL completo | **Superato: 336 test, 1.635 asserzioni**, 3m21s circa |
| Prova aggiuntiva conteggio canone storico | **Fallita: 1 test / 1 asserzione**, atteso 10.00, ottenuto 0.00; P01 |
| Prova aggiuntiva riconciliazione storno | **Fallita: 1 test / 1 asserzione**, storno non candidato; P02 |
| Prova configurazione connessione dei test | **Anomalia riprodotta senza connessione**: guard DB_DATABASE=audit9_test, database risolto da DB_URL=operational_sentinel; P03 |
| Composer validate --strict, check-platform-reqs | Superati su PHP 8.4.26, inclusa sodium |
| Pint con nuova cache, build Vite, sintassi wrapper/front controller | Superati; asset prodotti con gli stessi nomi della baseline |
| route:list, schedule:list, route:cache, view:cache | Superati nella copia isolata; 124 route e dieci task schedulati |
| npm audit attuale | Zero vulnerabilità segnalate |
| composer audit nativo locale | **Non eseguibile fino al risultato**: proxy rifiuta packagist.org con HTTP 403; non dichiarato superato localmente |
| CI precedente dello stesso identico commit | [Successo verificato su GitHub](https://github.com/simonetocco8-web/1139/actions/runs/38748847720), PHP 8.3 e MySQL 8, inclusi audit Composer/npm; evidenza distinta dai nuovi test locali |

I due test aggiuntivi sono sonde temporanee esterne al repository applicativo, basate sulle fixture esistenti: non sono stati aggiunti alla suite né corretti in questa fase. Non sommare 320 e 336 come casi indipendenti: la suite MySQL riesegue quella funzionale e aggiunge sedici casi. I primi tentativi di avvio dell'audit si sono fermati per socket PHPStan vietata e APP_KEY mancante nel nuovo ambiente; risolti nella sola sandbox prima degli esiti sopra.

## Browser, mobile e copertura residua

Chromium/Playwright su 1440×1000 e 320×740, login password + OTP consegnato al sink locale. Tre identità per viewport: amministratore, Fratello, Super User; tenant distinti tra desktop e mobile. Percorsi: dashboard, elenco fratelli, impostazioni/accessi, capitazioni, canoni, registro/conti/nuova operazione, report finanziario, notifiche/dettaglio/preferenze, richieste privacy/export personale e quattro cataloghi organizzativi più regole globali canoni. Verifica di overflow, errori JS/5xx e richieste esterne. **Sei sessioni concluse senza overflow orizzontale, errori JS/5xx o richieste esterne; 52 screenshot di evidenza salvati nella sandbox.** Risultati da leggere insieme alle limitazioni: non prova completa di tutti i form contabili né delle route con ogni ruolo; tesoriere coperto dai test server, non da una sessione browser dedicata.

Non misurati: copertura linee/rami (nessun report coverage), carico e durata cron su volumi reali, tastiera/screen reader e WCAG 2.2 AA completi, browser Safari/iOS/Android reali, autenticazione dietro proxy reale, consegna SMTP/SMS reale, offsite e ripristino dal deposito operativo, patch del provider, scansione vulnerabilità indipendente e conformità giuridica. L'assenza di test falliti nella suite originale non chiude queste lacune.

## Condizioni per l'utilizzo sicuro

Correggere P01–P03 con regressioni; verificare eventuali obblighi già creati senza riscriverli; completare P04–P06 con prove operative e approvazioni. Confrontare il commit distribuito con quello auditato e ripetere il collaudo se diverso. Accettare esplicitamente i rischi OTP, CSP, file e audit privilegiato. Vedere [PROBLEMI_RILEVATI.md](PROBLEMI_RILEVATI.md), [PIANO_INTERVENTI.md](PIANO_INTERVENTI.md), [CHECKLIST_COLLAUDO.md](CHECKLIST_COLLAUDO.md) e [CONFIGURAZIONE_HOSTING.md](CONFIGURAZIONE_HOSTING.md).
