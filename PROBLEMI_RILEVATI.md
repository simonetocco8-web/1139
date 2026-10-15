# Problemi rilevati e rischi residui

Baseline `d2bba13`, audit 15 ottobre 2026. Nessuna correzione applicativa eseguita. **Nessuna criticità massima dimostrata** (es. bypass OTP o lettura fra tenant), ma ciò non esclude vulnerabilità non coperte. Le priorità sotto distinguono difetti dimostrati, funzioni parziali e verifiche mancanti. Alta = blocco del processo interessato o del via libera; media = completamento/mitigazione pianificata; bassa = correzione di interfaccia o requisito secondario.

## Priorità alta

### P01 — Canoni pro capite arretrati calcolati con stato/esenzione correnti

**Difetto funzionale riprodotto.** `app/Domains/Levies/Actions/Generator.php:72` filtra status corrente active/ended ed esenzione corrente; Members aggiorna la stessa iscrizione. Non risolve tali attributi alla reference_on. Lo snapshot rende ripetibile il risultato generato, ma non lo rende corretto rispetto al passato.

Prova isolata: al 1 gennaio piano mensile 10,00 €/iscritto e persona attiva; nessuna generazione. Il 1 febbraio l'amministratore sospende la persona tramite Members; generazione con through=1 gennaio. **Atteso gennaio 10,00 €, ottenuto 0,00 €**. La sonda PHPUnit fallisce. Una variazione successiva dell'esenzione ha lo stesso rischio strutturale, non sottoposta a seconda prova separata. LEVIES.md:32 già dichiara il limite: la documentazione non impedisce al job di produrre l'obbligo.

Impatto: importi errati e snapshot apparentemente definitivo dopo indisponibilità dei job o recuperi storici. Gli obblighi già generati non vengono alterati automaticamente. Contenimento: sospendere recuperi pro capite non validati e confrontare le posizioni create in ritardo con evidenze anagrafiche. Intervento: stati/esenzioni con validità temporale e resolver storico, oppure blocco esplicito del backfill ambiguo con approvazione documentata. Eventuali correzioni contabili devono essere append-only; manca un percorso dedicato di rettifica dell'importo dell'obbligo canone già maturato, da definire insieme alla correzione.

### P02 — Storni bancari non riconciliabili con le righe già contabilizzate

**Difetto di integrazione riprodotto.** `app/Domains/Treasury/Actions/Banking.php:77` esclude sia operazioni reversal sia originali con reversal. Ledger richiede anche di rilasciare un abbinamento esistente prima dello storno.

Prova: incasso bancario 100,00 €, storno -100,00 €, riga estratto conto effettiva -100,00 €. La riga finanziaria dello storno esiste e il saldo è corretto, ma **non compare fra i candidati**; sonda PHPUnit fallita. `approve()` controlla soltanto quei candidati, quindi il suo blocco anti-doppia registrazione non intercetta lo storno escluso. Non è stata creata una seconda uscita nella prova: il rischio deriva dal percorso approva osservato nel codice.

Impatto: rimborso/annullamento reale non abbinabile e possibile seconda registrazione se l'operatore usa Approva. Contenimento: non approvare come nuova entrata/spesa le righe corrispondenti a storni già presenti. Intervento: distinguere correzione contabile senza movimento bancario e rimborso effettivo, permettere gli abbinamenti coerenti e mantenere vincoli di unicità. Testare anche incasso originale più rimborso, rilascio, ripetizione e giroconto stornato.

### P03 — Guard dei test controlla il nome dichiarato, non la connessione effettiva

**Anomalia riprodotta senza rete/connessione DB.** `tests/mysql-bootstrap.php:6` controlla solo getenv(DB_DATABASE) e suffisso _test. La configurazione MySQL accetta DB_URL; i valori env dei due XML non hanno force=true e possono restare sovrascritti dall'ambiente esterno. Una cache di configurazione può aggiungere un'ulteriore divergenza.

Prova puramente di risoluzione: APP_ENV=testing, DB_DATABASE=audit9_test e DB_URL con host fittizio database.example.invalid / database operational_sentinel. Bootstrap accetta, DB::connection()->getDatabaseName() restituisce **operational_sentinel**, senza aprire il PDO. Nessuna query o migrazione su tale destinazione è stata eseguita.

Impatto: un operatore che lanciasse i test sul server con variabili ereditate potrebbe indirizzare RefreshDatabase/migrate:fresh altrove; **non è una prova di esposizione HTTP**. Contenimento immediato: test solo in sandbox con credenziali incapaci di raggiungere database operativi. Intervento: guard comune prima di qualsiasi migrazione, ambiente testing obbligatorio, assenza cache/DB_URL inattesi, controllo connessione risolta/host/database e allowlist esplicita di destinazione sacrificabile. Aggiungere test del guard senza connessioni e prove negative su env/cached config. Non provare mai il rifiuto usando un database di produzione reale.

### P04 — Recupero dopo disastro e copia esterna non completati come processo operativo

**Parziale nel repository; stato del server non verificato.** Backup locale cifrato e restore isolato passano su MySQL. Non esiste un trasferimento offsite automatico configurato nel progetto. Il codice non fornisce un flusso completo di riattivazione sicura dopo `operations:quarantine-restored`: tutti gli account vengono disattivati e i vecchi token revocati, intenzionalmente.

Mancano evidenze di deposito esterno, monitor indipendente, catalogo digest fidato, custodia chiave privata, retention backup, RPO/RTO e prova della procedura di rivalidazione di identità/recapiti/ruoli. L'hosting potrebbe offrire già parte di ciò: non è stato controllato. Definire e collaudare una procedura approvata; non riattivare in massa gli account del vecchio snapshot e non aggiungere bypass OTP.

### P05 — Installazione pubblicata non verificata

**Non verificato, non difetto dimostrato dell'hosting.** Non sono stati forniti URL, commit distribuito, configurazione redatta, log cron o attestazioni del provider. Non si può confermare isolamento public/private, APP_ENV=production, HTTPS, cookie, SMTP/TLS, MySQL 8/InnoDB/REPEATABLE READ, queue, cron, permessi o chiusura dei file sensibili. Liveness /up non certifica questi aspetti. Completare CONFIGURAZIONE_HOSTING.md e registrare le evidenze prima del via libera.

## Priorità media

### P06 — Privacy operativa coperta solo in parte

**Limite confermato nel codice.** `app/Domains/Privacy/Cases.php` esporta la persona locale, iscrizioni, rate e versamenti, non tutto l'account globale, storico libero, allegati con terzi o copie esterne. Erasure blocca persone con riferimenti finanziari e non implementa dismissione della contabilità a fine conservazione. È corretto non eliminare arbitrariamente tali dati, ma non è un workflow completo di esercizio dei diritti.

Servono basi giuridiche, valutazione di dati potenzialmente particolari, ruoli/responsabili e fornitori, politiche approvate, gestione richieste di ex iscritti senza accesso e procedure per account globale, contenuti liberi, restrizione e vecchi restore. Durate zero non sono una policy: disabilitano la cancellazione. Valutazione giuridica/DPIA ove applicabile demandata al titolare, non certificata dal codice.

### P07 — Email/SMS OTP: rischi residui e recupero senza codici offline

**Rischio del metodo, non bypass riprodotto.** Email OTP non protegge da compromissione della casella o phishing in tempo reale; reset password sulla stessa casella riduce l'indipendenza dei fattori. SMS espone a SIM swap, riassegnazione numero e operatori. Password + recovery code offline + OTP sul nuovo recapito è implementato; se mancano anche i codici, non c'è un recupero amministrativo automatizzato. Richiedere procedura di identità sicura e formazione, senza scorciatoie. TOTP/passkey restano futuri, non placeholder delle funzioni operative.

### P08 — Contratto SMS specifico, consegna reale non collaudata

**Adapter reale ma generico/parziale.** TechnicalDelivery invia JSON to/text/sender con Bearer e richiede risposta accepted:true; un provider qualsiasi potrebbe non rispettarlo. Anche SMTP accepted non equivale a consegna nella casella. Mancano prove reali del provider, bounce/delivery receipt automatici e idempotenza esterna end-to-end. Gli esiti incerti richiedono verifica umana, non retry cieco. Non configurare SMS come secondo fattore prima del test reale.

### P09 — Hardening residuo di browser, allegati e audit privilegiato

**Limiti confermati, nessun exploit riprodotto.** PrivacyHeaders consente unsafe-inline/unsafe-eval per compatibilità Livewire; non è una CSP forte contro XSS. Allegati controllati per MIME/taglia e cifrati, ma senza scansione antimalware. Audit nel medesimo DB non è WORM e non resiste a un amministratore DB malevolo. Nomi/email/dati necessari alle ricerche non sono tutti cifrati a livello applicativo. Chi controlla PHP/APP_KEY può leggere i dati.

Valutare CSP con nonce/integrazione Livewire, trattamento sicuro dei file, protezione del provider e copie audit esterne con accessi separati. Log di cPanel/proxy/provider/failed_jobs hanno protezioni e retention proprie, da verificare. Non presentare queste misure raccomandate come obblighi automaticamente già assolti.

### P10 — Caricamento integrale dei versamenti e carico cron non misurato

**Scelta di codice confermata; impatto su scala non verificato.** `app/Http/Controllers/Capitations/Controller.php:119` carica tutti i versamenti e imputazioni della persona con get(), mentre pagina solo le rate. Generatori lavorano una loggia in una transazione e il wrapper limita il worker; non è stato misurato il massimo volume compatibile con il piano cPanel. Aggiungere paginazione/aggregazioni e benchmark realistici, senza spezzare impropriamente le transazioni finanziarie. Nessun timeout su dati reali è stato osservato.

## Priorità bassa

### P11 — Testi obsoleti e formattazione monetaria non uniforme

**Difetto confermato.** `resources/views/welcome.blade.php:16` annuncia anagrafiche/capitazioni/canoni/tesoreria come futuri. `resources/views/membership/settings.blade.php:5` dichiara tesoreria assente e preferenze non operative, mentre reminder_days e visibilità sono usati. `resources/views/capitations/person.blade.php:12` mostra alcuni importi raw con punto e EUR, anziché virgola italiana uniforme. Aggiornare contenuti e usare Money::display anche per ricevute/imputazioni. Non influisce sui centesimi contabilizzati.

### P12 — Note amministrative generiche degli iscritti assenti

**Requisito originario non implementato.** Nessun campo/form dedicato in migrazione anagrafica e Members; esistono motivazioni di esenzione, rettifiche e casi privacy, che non devono essere abusati come note generiche. Concordare necessità/minimizzazione, autorizzazioni, cifratura, storico e retention prima dell'aggiunta.

## Precisazioni

Le due sonde fallite non sono guasti inventati della suite esistente: quest'ultima ha 320/336 casi superati. L'audit Composer locale è rimasto bloccato dal proxy; la CI già eseguita sulla baseline lo ha superato. WCAG/pentest/carico e configurazioni reali sono lacune di verifica, non vulnerabilità dimostrate. Non sono stati corretti codice, test del progetto o database operativo durante questo audit.
