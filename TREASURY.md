# Fase 6 — Tesoreria indipendente per loggia

La Tesoreria registra incassi e uscite effettivi, conti bancari e casse contanti separati per tenant. Il movimento è una registrazione dell’evento finanziario avvenuto: il gestionale non invia bonifici alla banca e non preleva denaro tramite API. Non sono implementate fasi successive.

## Modello finanziario

`treasury_operations` contiene identificativo ULID, loggia/obbedienza, tipologia, data contabile locale, inserimento UTC con microsecondi, attore, causale cifrata, categoria, eventuale persona/sovrastruttura, origine dello storno, chiave di idempotenza e fingerprint. Ogni operazione è contabilizzata atomicamente con almeno due `treasury_lines` la cui somma è zero. Operazioni e righe sono immutabili e non cancellabili nei modelli; FK RESTRICT conservano i collegamenti storici.

Le righe distinguono quattro ambiti:

| Evento | Riga conto/cassa | Contropartita |
|---|---|---|
| Saldo iniziale | Importo iniziale con segno | Apertura/patrimonio, importo opposto |
| Entrata/incasso capitazione | Positivo | Entrata economica, negativo |
| Spesa/pagamento canone | Negativo | Spesa economica, positivo |
| Giroconto interno | Origine negativa | Destinazione positiva |
| Rettifica autorizzata | Importo con segno | Rettifica/patrimonio, opposto |
| Storno | Inversione esatta di tutte le righe originali | Stesse contropartite invertite |

Saldo conto = somma delle sole righe `financial` del conto. Consolidato = somma dei saldi di tutti i conti/casse della loggia. Non esistono campi di saldo corrente modificabili. Aperture, giroconti e rettifiche patrimoniali non confluiscono nei totali economici di entrata/spesa. I riepiloghi economici mostrano importi netti degli storni nel periodo selezionato: un rimborso/storno di entrata può quindi produrre entrate nette negative nel periodo in cui avviene.

Le obbligazioni non pagate non hanno righe finanziarie e non modificano alcun saldo. Gli importi sono in centesimi interi con DECIMAL(15,2); somme con controllo dell’overflow, nessun calcolo monetario applicativo in virgola mobile. La valuta è **EUR**, coerentemente con l’architettura del progetto; non sono introdotti cambi o consolidamenti fra valute diverse.

## Conti, casse e operazioni

Ogni loggia può aprire più conti/casse, con nome univoco locale, tipo `bank`/`cash`, IBAN facoltativo solo per banca, valuta, saldo iniziale e data di apertura. La creazione e la registrazione di apertura avvengono nella stessa transazione. Anagrafica e importo originario del conto sono immutabili; eventuali errori finanziari si correggono mediante rettifiche o storno dell’apertura, preservando l’originale. Il saldo bancario può essere negativo; la cassa contanti non può esserlo.

`Ledger::post` accetta entrata, spesa, giroconto banca/cassa o tra conti, rettifica con segno. Entrate/spese richiedono una categoria dello stesso tenant e del tipo corretto. Capitazioni e canoni usano i propri servizi dedicati. Creazione categorie con `update`, conti con `administration`, movimenti con `create`, rettifiche/storni con `reverse`. Tutti i percorsi richiedono anche lettura completa e sessione OTP valida.

Operazioni future, anteriori all’apertura del conto, importi nulli, categoria incompatibile e trasferimenti sullo stesso conto sono rifiutati. Il saldo di cassa viene ricostruito per data contabile e ordine d’inserimento anche dopo una registrazione retrodatata: se un qualsiasi punto della storia diventa negativo, tutta la transazione viene annullata. Lo stesso vincolo vale per gli storni e non ha un bypass nell’interfaccia.

Lo storno è integrale, autorizzato e motivato, datato non prima dell’originale, collegato una sola volta all’operazione originaria. Non si storna uno storno; per correggere una registrazione si conserva la catena originale/storno/nuova registrazione. È possibile stornare l’apertura senza alterare il saldo iniziale originario memorizzato nel conto. Operazioni già riconciliate richiedono prima il rilascio esplicito dell’abbinamento.

## Transazioni e integrazione con capitazioni/canoni

Gli adapter reali `LedgerReceiptGateway` e `LedgerDisbursementGateway` sostituiscono i binding indisponibili delle fasi 4 e 5. Non occorre attivare un driver fake né configurare provider bancari. Ogni adapter richiede la stessa `ConnectionInterface` della transazione chiamante. Nessun commit autonomo o effetto finanziario remoto.

L’incasso capitazione registra la riga finanziaria, la ricevuta e le imputazioni alle rate atomicamente; `treasury_source_links` collega l’operazione alla ricevuta. Imputare successivamente credito già incassato non crea un’altra entrata. Il pagamento canone registra uscita, pagamento e collegamento atomici; i parziali successivi consumano solo il residuo. Il dettaglio finanziario collega il prospetto personale o il canone, dai quali sono ricostruibili rate e imputazioni.

Lo storno di un incasso capitazione inverte tutte le imputazioni ancora attive, crea un record immutabile `capitation_receipt_voids` e rende nullo il credito residuo della ricevuta annullata. Le rate si riaprono; non è consentito riutilizzare il credito dello stesso incasso. Lo storno di un pagamento canone crea `levy_payment_voids`, escluso dal calcolo del versato; l’obbligazione torna esigibile. Originali, imputazioni, annullamenti e movimenti rimangono nello storico, con indicazioni di storno nelle schermate dei moduli precedenti.

Le transazioni usano lock obbedienza → loggia, uniformati anche nei servizi di pagamento delle capitazioni. L’obbedienza immutabile viene risolta prima della transazione, evitando snapshot MySQL REPEATABLE READ anteriori all’attesa sul lock. Le Action possiedono le proprie transazioni: non anteporre transazioni applicative con letture obsolete. UNIQUE sulle chiavi locali, sulle origini degli storni e sui collegamenti di pagamento completano la serializzazione. Stessa chiave/dati restituisce l’esistente; chiave riutilizzata con dati diversi produce conflitto. Namespace diversi separano apertura, movimenti manuali, incassi, canoni e storni.

La migrazione non crea incassi da scadenze, non ricontabilizza ricevute pregresse e non inventa saldi. Un’eventuale installazione con integrazioni finanziarie personalizzate precedenti deve stabilire il saldo e la data di apertura del nuovo registro prima di importare o riconciliare dati storici.

## Riconciliazione e importazione CSV

La riconciliazione è disponibile solo sui conti bancari. `treasury_statement_entries` contiene righe reali dell’estratto conto e rimane separata dal registro finanziario. Inserire/importare una riga non cambia saldi né obbligazioni.

Formato CSV UTF-8, separatore punto e virgola, massimo 256 KiB/200 righe:

```csv
booked_on;amount;description;reference
2026-10-15;30.00;Contributo bancario interamente fittizio;FITTIZIO-001
2026-10-15;-10.00;Spesa bancaria interamente fittizia;FITTIZIO-002
```

Data AAAA-MM-GG, importo con segno e massimo due decimali, descrizione e riferimento univoco della banca obbligatori. Non si inferiscono intestazioni, segni o date da formati ambigui. Il riferimento è cifrato con un digest per il vincolo univoco conto/riferimento. Stesso riferimento e contenuto: duplicato escluso; stesso riferimento con dati diversi: conflitto, nessuna sostituzione. La fingerprint del contenuto segnala righe simili; riferimenti distinti restano righe distinte, perché possono rappresentare eventi legittimi uguali per data/importo/causale.

Anteprima cifrata in `treasury_imports`, token casuale digerito, binding a sessione/attore/tenant, scadenza 15 minuti, errori per riga e conferma esplicita. Tutti i dati sono ricontrollati nella transazione di conferma; errori impediscono l’intera importazione. Conferma ripetuta restituisce il conteggio già salvato. Duplicati nel file o entrati nel frattempo sono ricontrollati sotto lock. Pruning orario delle anteprime scadute da oltre un giorno; non elimina righe bancarie o registrazioni finanziarie.

Le azioni possibili su una riga bancaria sono:

1. **Abbina:** sceglie una riga finanziaria dello stesso conto/tenant e importo con segno, non già riconciliata e non stornata. Data contabile e bancaria possono differire. Non crea movimenti.
2. **Approva nuova registrazione generica:** con `create` e `update`, categoria coerente e conferma. Se esiste una registrazione candidata per conto/importo, anche con data diversa, il server blocca la creazione per richiedere la verifica dell’esistente. Per un evento realmente distinto con lo stesso importo si registra prima la nuova operazione nel registro e poi la si abbina. Capitazioni e canoni passano prima dai moduli dedicati.
3. **Rilascia abbinamento:** con `reverse` e motivazione, chiude la riconciliazione una sola volta; non cancella lo storico né cambia il saldo. Sono poi possibili nuovo abbinamento o storno del movimento.

Vincoli univoci sugli abbinamenti attivi impediscono di usare due volte la stessa riga bancaria o finanziaria. Lo storico rimane in `treasury_reconciliations`, con attore, inserimento e rilascio motivato.

## Allegati ed esportazioni

PDF/JPG/PNG fino a 5 MiB, MIME validato sul server, percorso casuale sotto `storage/app/private/treasury`, contenuto cifrato, nome cifrato, hash SHA-256 e audit. Download soltanto tramite endpoint autorizzato sul tenant, verifica hash, `Content-Disposition: attachment`, `nosniff` e risposta privata/no-store. Nessun link pubblico o symlink. Salvataggio file fallito o transazione rifiutata non lascia un allegato scaricabile. Conservare APP_KEY e storage privato nei backup.

CSV UTF-8 con separatore `;` e protezione dalle formule per i campi testuali. PDF generato sul server da Dompdf 3.1.6, font Unicode, nessun caricamento remoto, PHP o JavaScript; cache font/temp privata e nessun PDF persistito. Esportazioni richiedono `export` e `reports`, rispettano tutti i filtri e registrano l’audit. Massimo 2.000 operazioni CSV e 250 PDF per richiesta; oltre il limite si richiede una selezione più stretta, senza troncamenti silenziosi. I trasferimenti esportano le due righe con lo stesso identificativo.

## Interfacce e privacy

- `/loggia/tesoreria`: registro cronologico, saldi distinti/consolidato, entrate/uscite nette, filtri data/tipologia/categoria/conto.
- `/loggia/tesoreria/conti?type=bank` e `?type=cash`: registri conti/casse e apertura autorizzata.
- `/loggia/tesoreria/nuova`: entrate/spese, trasferimenti, rettifiche e categorie.
- `/logge/{lodge}/tesoreria/operazioni/{operation}`: righe, attore, inserimento, collegamenti economici, allegati e storni.
- `/logge/{lodge}/tesoreria/banche/{account}`: righe bancarie, anteprima CSV, abbinamenti e approvazione.
- `/loggia/tesoreria/esporta/{csv|pdf}`: esportazioni autorizzate.
- `/loggia/tesoreria/riepilogo`: soli totali aggregati banca/cassa/consolidato per il Fratello quando `brothers_treasury_visible` è esplicitamente attivo; nessun nome conto, movimento, allegato o elenco persone. La propria posizione economica continua a usare le capitazioni locali.

Sessione OTP e tenant selezionato sono rivalidati in ogni Action, anche dopo i lock. URL e relazioni vincolati al tenant, nessun accesso implicito per Super User. L’assistenza usa soltanto gli ambiti espliciti esistenti; amministrazione conti non disponibile per assistenza. Conferme server-side, CSRF, rate limiting, middleware privato e protezioni Livewire persistenti esistenti. I form nuovi sono Blade.

## Migrazione, demo e distribuzione

`2026_10_15_010000_create_treasury.php` crea conti, categorie, operazioni, righe, collegamenti alle sorgenti, annullamenti di ricevute/pagamenti, allegati, righe bancarie, riconciliazioni e anteprime. Aggiunge il vincolo tenant/ID ai pagamenti canoni. FK composite su loggia/obbedienza/struttura, persona locale, conti, operazioni, sorgenti, allegati e riconciliazioni; indici per date/filtri/duplicati, CHECK MySQL per valuta, contropartite, sorgenti e cassa iniziale. Le migrazioni precedenti non sono modificate.

```sh
composer install --no-dev --optimize-autoloader
php artisan migrate --force
# Solo locale/testing, senza creare account di accesso:
php artisan db:seed --class=TreasuryDemoSeeder
```

Build Vite aggiornata inclusa per cPanel. Nessun Redis, worker residente, provider bancario o servizio esterno necessario; cron e storage privato seguono la configurazione esistente. Il seeder usa esclusivamente l’obbedienza fittizia `DEMO_LEVY`, crea due conti per loggia e operazioni dimostrative bilanciate/idempotenti, senza pagamenti reali o credenziali. L’attore nullo è limitato alle registrazioni dimostrative create dal seeder; le Action applicative registrano sempre l’utente verificato.

[Verifiche](TREASURY-VERIFICATION.md) e [20 schermate desktop/mobile](docs/screenshots/treasury/README.md).
