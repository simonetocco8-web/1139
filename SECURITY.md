# Sicurezza — revisione della fase 8

Revisione del codice e test automatizzati al 15/10/2026. Non è un pentest indipendente né una certificazione. **Nessuna dichiarazione di idoneità alla produzione** prima del collaudo su hosting, servizi reali e politiche approvate. Esiti riproducibili e limiti in [OPERATIONS-VERIFICATION.md](OPERATIONS-VERIFICATION.md); controlli di rilascio in [ACCEPTANCE-CHECKLIST.md](ACCEPTANCE-CHECKLIST.md).

## Confini verificati

| Area | Protezioni e verifica |
| --- | --- |
| Tenant isolation | TenantContext risolve solo logge autorizzate e selezionate; URL e proprietario rivalidati sul server. Query locali con lodge_id e join/FK compositi. Test di URL manipolati, cambio tenant, account multi-loggia e proprietà personale. |
| Autorizzazioni | Ruoli per loggia; amministrazione piattaforma distinta dai contenuti. Nessun bypass globale del Super User. Assistenza esplicita/scadibile/revocabile e audit; non usata come titolo di ricezione delle notifiche di background. Export richiede i permessi della schermata e quelli di esportazione. |
| CSRF | Route mutanti sotto middleware web, form con token e conferme server-side. Test reale 419 con bypass PHPUnit disattivato tramite ambiente production; le nuove azioni non sono GET mutanti. Gli export GET producono solo documenti/audit. |
| XSS | Blade esegue escaping dei dati e dei testi amministrativi; test con script nel corpo cifrato. PDF senza JavaScript, PHP o risorse remote. X-Frame-Options DENY, CSP con frame-ancestors/object-src/base-uri/form-action, nosniff e referrer policy. |
| Injection | Query builder e binding; SQL raw finanziario con espressioni costanti. Filtri, date, formati e ID validati. Nessuna concatenazione di input HTTP in SQL/shell. Client MySQL via array Process e credenziali temporanee 0600, non password negli argomenti. CSV protegge celle testuali da formula injection. |
| Mass assignment | Whitelist nelle Action e nelle validazioni; User accetta soltanto name. Nessun form decide user_id/tenant/flag Super User/saldo. Nuove outbox e richieste sono costruite da campi espliciti server-side. |
| File privati | Percorsi casuali del server, MIME/dimensione e contenuti validati, cifratura, digest e download autorizzato per tenant. Nessun public/storage. Allegati forzati al download. Restore rifiuta traversal, duplicati, directory esistenti, chiave errata, alterazioni e troncamenti. Chiavi/restore non possono essere creati sotto public anche tramite alias simbolici. |
| OTP e recupero | Fortify verifica password prima della challenge; solo OTP monouso valido crea sessione completa. Sei cifre CSPRNG, digest HMAC con segreto server, cinque minuti, cinque tentativi, reinvio dopo 60 secondi e invalidazione precedente. Rate limit account/IP, controllo versione e recapito. Password reset non autentica; cambio fattore richiede password, secondo fattore recente e verifica del nuovo canale. Test esistenti di replay, scadenza, concorrenza e bypass mantenuti. |
| Tesoreria/audit | Operazioni e righe immutabili, transazioni condivise, chiavi uniche, lock e storni append-only. Niente cancellazione di movimenti da privacy/manutenzione. Controllo di bilanciamento, cassa storica, residui, credito, sorgenti, storni e audit dei posting; snapshot concorrenti e ripristino dei saldi su MySQL. |
| Esportazioni | Tenant/proprietario/permessi come pagine, no-store/private/nosniff, limiti senza troncamenti silenziosi. Nessun file esportato in public. JSON privacy personale separato da report gestionali; niente credenziali/dati altrui. I documenti con terzi richiedono revisione manuale. |
| Backup | Stream cifrato con sodium, chiave privata offline, hash dei file e tag finale; directory 0700/file 0600. Dump MySQL coerente solo in manutenzione e con lock comune, niente SQL temporaneo in chiaro. Checksum esterno fidato obbligatorio nel comando restore; test di manomissione, chiave errata e ripristino reale. |
| Segreti | .env fuori dal web root, escluso dal repository/pacchetto; segreti da deposito protetto. APP_KEY/OTP_SECRET nel bundle cifrato; nessuna password/token in console o job notifiche. Chiavi backup private e archivi esclusi dal versionamento. |
| Errori e log | Produzione con debug forzato false; eccezioni applicative riportate con sola classe, query con solo SQLSTATE, niente messaggi/trace/valori SQL nei log applicativi standard. Errori di trasporto notifiche diventano codici neutrali. Non pubblicare failed_jobs, access log o output cron. |
| Cookie/HTTPS | Produzione forza URL HTTPS, cookie Secure/HttpOnly/SameSite=Lax, sessioni cifrate configurate e controllate dal preflight, revoca versionata a ogni richiesta; HSTS sulle risposte HTTPS. HTTP web rifiutato. Header dei proxy non fidati automaticamente. |

Il flag di piattaforma non autorizza letture economiche locali. Il centro globale espone soltanto conteggi/codici tecnici; gli eventi finanziari, le persone e i payload della coda non diventano automaticamente visibili agli amministratori di piattaforma.

## Modifiche della fase 8

- Notifiche esterne neutrali senza posizioni di altri iscritti; lettura dei dettagli solo dopo login e autorizzazione. Invii su canale verificato corrente, senza snapshot di indirizzi nel job. Preferenze/ruoli/tenant/residuo ricontrollati al consumo.
- Deduplica DB e claim serializzato fra worker; accodamento atomico sulla stessa connessione. L'invio è marcato in corso **prima** dell'I/O esterno. Eccezioni/interruzioni ambigue non causano reinvii automatici; eventuali nuovi tentativi richiedono conferma e audit.
- Redirect HTTP del gateway SMS disabilitati; l'endpoint iniziale deve essere HTTPS. In produzione MAIL_URL è rifiutato, così una URL alternativa non scavalca schema/require_tls espliciti.
- Header di sicurezza, log di produzione minimizzati, protezione dei percorsi operativi e denylist di difesa aggiuntiva nella directory pubblica.
- Backup cifrato e restore solo in destinazione nuova; digest atteso da catalogo esterno fidato. Dopo restore, account disattivati e token/sessioni/job/assistenze del vecchio snapshot invalidati. Nessuna riattivazione automatica di recapiti o ruoli potenzialmente revocati dopo lo snapshot.
- Privacy con approvazione documentata, anteprima della retention e default senza cancellazioni. Cancellazione anagrafica esplicita bloccata da riferimenti finanziari, inclusi snapshot pro capite. Registro minimo cifrato delle cancellazioni per riconciliare un vecchio restore.

## Rischi residui OTP email e SMS

Entrambi i canali sono **non resistenti al phishing**: un sito falso o un attaccante in tempo reale può sottrarre password e codice. L'email spesso è usata anche per recupero password; comprometterla riduce l'indipendenza dei fattori. Restano rischi di inoltro, recupero casella debole, sessioni della casella condivise e dispositivi compromessi.

SMS: SIM swap/portabilità fraudolenta, riciclo numeri, SS7/intercettazione, anteprime sul dispositivo, malware e accesso degli operatori/provider. Non preferirlo automaticamente all'email. SMTP/SMS espongono metadati e codici ai provider: TLS non è cifratura end-to-end né garanzia di riservatezza dopo la consegna. Verificare contratti e conservazione dei fornitori. Nessun OTP deve comparire in job, audit, log o ticket.

Passkey/WebAuthn e fattori resistenti al phishing non sono implementati. Per dati e ruoli ad alto rischio, il titolare deve valutare un fattore più forte prima del rilascio; questa fase non introduce un bypass o un fattore presentato come equivalente. La perdita di entrambi i canali e dei codici offline richiede una procedura umana approvata, non un login alternativo senza secondo fattore.

## Rischi operativi e privacy residui

- Chi controlla l'utente PHP/hosting può accedere a DB e chiavi: la cifratura applicativa non difende da esecuzione sul server. Database amministratore e operatore di backup sono privilegiati. Audit applicativo e hash non sono un registro WORM contro un amministratore DB malevolo; conservare copie esterne immutabili e accessi separati.
- L'AEAD rileva alterazioni dei frame, ma una chiave pubblica consente a chiunque la possieda di cifrare un nuovo archivio: l'origine del backup dipende dal **catalogo SHA-256 esterno fidato**, non dalla sola possibilità di decifrare. La chiave privata di restore non deve risiedere nell'hosting ordinario.
- Una revoca non può richiamare un messaggio già in volo o accettato dal provider; i controlli prima del claim e il corpo neutro limitano il rischio residuo.
- SMTP/SMS non partecipano alla transazione DB e non espongono, nel contratto corrente, idempotenza end-to-end o delivery receipt. Accettazione non è consegna; un esito incerto richiede controllo umano. Non esiste un “exactly once” dichiarato falsamente.
- CSP conserva unsafe-inline/unsafe-eval per compatibilità con lo stack Livewire/Flux. Non sostituisce escaping e controlli; una CSP rigorosa con nonce richiede ulteriore collaudo del frontend. Header statici, proxy, TLS e .htaccess dipendono dall'hosting effettivo.
- Log del web server, cPanel, SMTP, provider e failed_jobs possono avere dettagli propri. Sono privati ma vanno protetti, limitati e regolati con policy; la minimizzazione del logger Laravel non li bonifica automaticamente. Gli errori di connessione/CLI non vanno inoltrati dalle email automatiche del cron.
- Download autorizzati lasciano il perimetro applicativo: l'applicazione non controlla copie sui dispositivi. Allegati PDF/immagini non sono stati sottoposti a un antivirus/sandbox: mantenere visualizzatori aggiornati, non servire inline contenuti attivi e valutare scansione dedicata.
- I dati di appartenenza possono rivelare convinzioni/associazioni: il titolare deve valutare categorie particolari, basi giuridiche, eventuale DPIA e accordi con i fornitori. Il software non determina la liceità del trattamento.
- Richieste/esiti privacy, account globale, contenuti liberi, allegati e copie precedenti richiedono valutazioni separate. Nessuna promessa di cancellazione totale da un singolo pulsante. La cancellazione automatica della contabilità e la cancellazione indiscriminata dell'account globale non sono implementate. La riabilitazione degli account dopo disaster recovery è umana, con identità/recapiti/ruoli rivalidati; il runtime ripristinato resta chiuso finché non completata.
- Monitoraggio esterno, replica backup, RPO/RTO, escrow, restore del provider, quota e prestazioni con dati effettivi non sono configurati né collaudati. La disponibilità di cron/DB/SMTP non può essere garantita dai soli alert dell'applicazione.

## Dipendenze e revisione indipendente

Lockfile Composer/npm preservati; nuova dipendenza di piattaforma ext-sodium. `npm audit` ha restituito zero vulnerabilità segnalate. L'audit Composer nativo non ha potuto raggiungere `packagist.org/api/security-advisories` perché il proxy della sessione lo blocca: **non viene dichiarato superato**. Controllo alternativo sull'API GitHub Advisory Database delle 138 versioni Composer bloccate, incluse dev: zero advisory restituiti; controllo positivo con una vecchia versione vulnerabile conferma il filtro. È una fonte alternativa, non la prova dell'assenza di vulnerabilità o la copertura di advisory non presenti nel catalogo. Ripetere l'audit nativo da rete autorizzata e mantenere una procedura di aggiornamento.

Rimangono da eseguire pentest indipendente, audit completo WCAG, verifica configurazione/isolamento del provider e approvazione delle politiche prima di dati reali. Non sono stati osservati nei test problemi critici irrisolti di isolamento o calcolo; questo non equivale a un via libera alla produzione. Un errore di `operations:integrity`, un'esposizione di .env/storage o un accesso cross-tenant è un **blocco al rilascio**, non un avviso da accettare.

## Gestione delle segnalazioni

Concordare con il titolare un canale privato autorizzato per incidenti; non è stato fornito un indirizzo reale e non ne viene inventato uno. Segnalare commit, comportamento riproducibile e impatto minimo, senza dati personali, OTP o segreti. Sospendere il servizio quando necessario, preservare prove protette e seguire il piano incidenti/obblighi di notifica approvati. Nessuna comunicazione esterna è stata inviata per conto del titolare.
