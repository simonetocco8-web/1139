# Fase 5 — canoni verso sovrastrutture

Il dominio Levies gestisce obblighi delle logge, separati dalle capitazioni personali. Nessuna scadenza registra un movimento o modifica saldi finanziari. La Tesoreria operativa rimane fuori da questa fase: il pagamento è disponibile soltanto quando un adapter reale implementerà il contratto condiviso descritto sotto. Il binding distribuito è indisponibile e non dichiara mai pagamenti eseguiti.

## Programmi, ambiti e validità

Un programma è identificato da obbedienza e codice univoco, con descrizione, livello destinatario nazionale/regionale/provinciale, periodicità mensile/trimestrale/semestrale/annuale, decorrenza e termine facoltativo. Più programmi distinti consentono più canoni indipendenti allo stesso destinatario. Calendario e identità sono immutabili; per cambiare periodicità si sospende il programma precedente con una condizione futura inattiva e se ne crea uno nuovo.

Le condizioni economiche sono versioni append-only: importo fisso o unitario per fratello, criterio di conteggio, scadenza da inizio/fine periodo più 0–365 giorni, IBAN facoltativo, attivazione e motivazione cifrata. Per ogni ciclo si applica, in ordine:

1. Deroga della loggia.
2. Condizione della sovrastruttura destinataria di quel ciclo.
3. Condizione generale dell’obbedienza per il livello del programma.

Si prende la versione più recente di ciascun ambito alla data di riferimento. Se scaduta, si passa all’ambito meno specifico senza ripristinare una vecchia versione dello stesso ambito. Una versione inattiva sospende il canone, senza fallback. La deroga locale segue il programma/livello anche dopo un trasferimento: va limitata con un termine quando l’accordo deve durare soltanto alcuni cicli.

Il Super User definisce programmi e condizioni generali; amministratore/tesoriere locale con `update` definisce deroghe solo nella loggia selezionata. La pagina globale non carica deroghe locali, anagrafiche, conteggi, obblighi o pagamenti. Nessun accesso finanziario implicito al Super User: si applicano gli accessi locali o l’assistenza esplicita preesistente.

Nuovi programmi decorrono da oggi o da una data futura. Le successive condizioni/deroghe iniziano esclusivamente in un ciclo futuro. Il termine delle condizioni deve essere la fine di un ciclo. Il termine iniziale del programma è anche quello della prima condizione e segue lo stesso vincolo. Sono esclusi prorata impliciti e riscritture di periodi già maturati. Non è disponibile una cancellazione o modifica diretta di versioni, obblighi e pagamenti.

## Destinatario e conteggio storico

Riferimento: ore 00:00 Europe/Rome del primo giorno del ciclo, salvato sia come DATE sia come istante UTC nello snapshot. Il calendario è ancorato alla decorrenza originale con mesi senza overflow: 31 gennaio → 28 febbraio → 31 marzo.

Il generatore cerca l’assegnazione territoriale del livello previsto il cui intervallo `[starts_at, ended_at)` comprende quell’istante. Non usa semplicemente la relazione corrente e non richiede livelli intermedi: nazionale e provinciale possono esistere senza regionale. Se manca l’assegnazione del livello richiesto, quel canone non viene generato. Un collegamento effettuato a metà ciclo produce il primo obbligo al successivo riferimento valido. La chiave univoca loggia/programma/inizio periodo non comprende il destinatario: un trasferimento non consente una seconda fatturazione dello stesso ciclo.

Per `per_member` si contano persone distinte con un’iscrizione che comprende il riferimento (estremi inclusivi), stato `active` oppure `ended`; un’iscrizione cessata successivamente al riferimento può quindi essere conteggiata. Le iscrizioni `suspended` sono escluse. Sono configurabili due criteri espliciti:

- `active_at_start`: include le esenzioni amministrative.
- `active_non_exempt_at_start`: esclude le iscrizioni con esenzione amministrativa.

L’esenzione amministrativa non è dedotta dalle condizioni personali delle capitazioni. Ogni snapshot salva criterio, riferimento, unità, importo unitario, identificativi persona/iscrizione, intervallo, stato ed esenzione di ogni iscrizione conteggiata, senza nominativi. Gli stati sono quelli registrati alla generazione; lo storico delle sospensioni precedenti non viene dedotto automaticamente da una situazione anagrafica attuale. Per arretrati non ancora generati occorre prima verificare tali dati. Gli obblighi esistenti conservano sempre il conteggio originale, anche dopo cessazioni, sospensioni o modifiche dei dati.

Si conservano anche versione economica, assegnazione, destinatario e nome storico. L’IBAN è quello esplicito della condizione oppure quello della sovrastruttura al momento della generazione; viene copiato e cifrato nell’obbligo. Nessuna modifica successiva dell’IBAN cambia il destinatario di un obbligo già generato. Un canone fisso ha una unità e nessun conteggio di persone. Zero iscritti produce un obbligo di importo nullo, senza pagamento. Gli importi usano centesimi interi, DECIMAL(15,2) e controllo dell’overflow.

## Generazione e concorrenza

`php artisan levy:generate [--lodge=ID] [--through=AAAA-MM-GG]` genera soltanto cicli iniziati entro oggi. Scheduler giornaliero alle 01:30 Europe/Rome con `withoutOverlapping`; `GenerateLevies` è il job equivalente, ripetibile. L’interfaccia permette la generazione manuale con permesso `create`, conferma e nuova verifica dopo il lock.

Le transazioni bloccano prima l’obbedienza e poi la loggia, come OrganizationActions. L’identificativo immutabile dell’obbedienza viene risolto prima di entrare nella transazione, evitando l’apertura di uno snapshot MySQL REPEATABLE READ prima dell’attesa sul lock. Le modifiche alle iscrizioni e ai ruoli sono serializzate per loggia. Il vincolo UNIQUE resta un’ulteriore protezione. Le Action possiedono la propria transazione: i chiamanti applicativi non devono anteporre transazioni con letture obsolete.

Lo scheduler non deve eseguire la generazione con selettori del browser: ogni job riceve una loggia esplicita, controlla attività e obbedienza e limita tutte le letture delle iscrizioni a quella loggia. L’audit registra attore quando presente, loggia, ID e quantità, senza nominativi o IBAN.

## Pagamenti e Tesoreria condivisa

`DisbursementGateway` espone disponibilità, conti autorizzati e `post(DisbursementInput, ConnectionInterface): PostingReference`. L’input contiene loggia, obbligo, destinatario/IBAN storicizzati, conto, importo in centesimi, data e chiave di idempotenza. L’adapter reale deve registrare l’uscita da banca/cassa sulla stessa connessione e transazione, validare conto/tenant, EUR, periodo e idempotenza, senza commit autonomi o effetti remoti irreversibili. Utilizza lo stesso confine transazionale già predisposto per gli incassi delle capitazioni.

Il servizio permette pagamenti parziali successivi fino al residuo, senza anticipi/sovrapagamenti dell’obbligo. Data non futura e non anteriore alla maturazione. Chiave uguale e dati uguali restituiscono il pagamento esistente; dati differenti con la stessa chiave producono 409. Conto di un altro tenant, importo non valido o superiore al residuo vengono rifiutati prima del gateway. Il riferimento locale viene salvato soltanto dopo il movimento reale confermato. Errori successivi al post annullano sia la scrittura del gateway sia il pagamento. Non esiste un saldo banca/cassa in Levies e non esiste un driver fake attivabile con configurazione nell’applicazione.

`UnavailableDisbursementGateway` è il binding predefinito. Form pagamenti disabilitato e messaggio esplicito; POST forzato non crea dati. I test usano esclusivamente `tests/Support/FakeDisbursementGateway`, che scrive un evento di prova nella connessione ricevuta, senza creare una contabilità alternativa. Nessun pagamento dimostrativo viene inserito dal seeder. Storni finanziari e riconciliazioni appartengono alla futura Tesoreria e non vengono simulati qui.

## Interfaccia e accessi

- `/admin/canoni`: programmi e versioni generali per Super User, senza contenuti finanziari locali.
- `/loggia/canoni`: dashboard con dovuto/versato/residuo, filtro scadenza e stato, generazione manuale.
- `/loggia/canoni/condizioni`: condizioni applicabili e deroghe della sola loggia attiva.
- `/loggia/canoni/scadenze`: scadenzario con `reports`.
- `/loggia/canoni/pagamenti`: storico delle uscite per data effettiva, con `reports`.
- `/logge/{lodge}/canoni/{obligation}`: dettaglio, conteggio originale e pagamenti parziali.

Le pagine locali richiedono lettura completa, tenant selezionato e sessione OTP valida. Il ruolo Fratello non legge obblighi o conteggi aggregati della loggia. Permessi `create` per generazione/pagamento, `update` per deroghe; assistenza solo con gli ambiti autorizzati. I report non autorizzano accessi trasversali. ID dell’URL sempre filtrati per tenant; cambio della selezione invalida il vecchio contesto. Middleware privato/no-store, form CSRF, conferme lato server, validazione e rate limit; nessun nuovo endpoint Livewire privo delle protezioni persistenti esistenti.

## Migrazione e dati fittizi

Migrazione `2026_10_15_000001_create_levies.php`: `levy_programs`, `levy_conditions`, `levy_obligations`, `levy_payments`; indice composito aggiuntivo allo storico territoriale. FK RESTRICT e composite assicurano obbedienza/programma, obbedienza/loggia, obbedienza/struttura, condizione del programma, assegnazione/destinatario della stessa loggia e pagamento/obbligo nello stesso tenant. CHECK MySQL per importi, date e ambiti; indici su scadenze e date pagamento. Rollback elimina soltanto le tabelle di questa fase e il relativo indice.

`php artisan db:seed --class=LevyDemoSeeder` è limitato a locale/testing, idempotente e senza account. Crea un’obbedienza `DEMO_LEVY`, due logge, nazionale/provinciale senza regionale, persone interamente fittizie e due programmi; storia dimostrativa dall’inizio dell’anno e nessun pagamento. Non riutilizza né modifica le assegnazioni delle logge operative. Conservare APP_KEY nei backup per IBAN e motivazioni cifrati. Verifiche e schermate in [LEVIES-VERIFICATION.md](LEVIES-VERIFICATION.md).
