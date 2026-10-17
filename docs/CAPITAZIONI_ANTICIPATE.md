# Rate anticipate, esenzioni e aggiornamento interfaccia

## Uso

In **Capitazioni → Piani di capitazione**, ogni piano offre **Genera rate anticipate**. Scegliere un ciclo futuro del piano (mensile, trimestrale, semestrale o annuale) entro cinque anni e aprire l’anteprima. Per un piano semestrale con decorrenza 1 gennaio, scegliere **01/01/2027 — 30/06/2027** per il primo semestre 2027.

L’anteprima mostra gli iscritti interessati, competenza, importi e scadenze, distinguendo rate nuove, già esistenti e condizioni cambiate. La conferma richiede una spunta esplicita. Il server rilegge i dati sotto lock della loggia, verifica il tenant, l’utente, i permessi e l’impronta dell’anteprima. L’anteprima scade dopo 15 minuti; una variazione dei dati richiede una nuova anteprima. Ogni generazione è transazionale; la chiave unica loggia/piano/persona/ciclo e i lock impediscono duplicati anche con richieste concorrenti o con il job giornaliero.

**Rate future non maturate** elenca separatamente le previsioni. Fino all’inizio della competenza, non entrano nei debiti maturati, nei morosi, nei report economici delle capitazioni o nei promemoria. Restano visibili, contrassegnate, nella scheda individuale. Le imputazioni anticipate sono possibili, ma solo un incasso effettivo crea un movimento finanziario. La generazione anticipata non crea incassi, ricevute, movimenti o trasferimenti di denaro. Il job giornaliero continua a generare soltanto cicli iniziati entro oggi e riconosce quelli già generati anticipatamente.

## Calcolo e variazioni

Calcolatore condiviso con la generazione giornaliera: versioni economiche valide all’inizio del ciclo, durata effettiva dell’iscrizione, termine del piano, esenzioni amministrative, regole individuali, sconti, importi personalizzati e prorata esplicito. Con prorata disattivato, una porzione eleggibile del ciclo comporta quota intera; non si addebitano cicli precedenti all’iscrizione. Con prorata giornaliero si considerano solo i giorni non esenti, con arrotondamento in centesimi.

Le modifiche applicative a iscrizioni/anagrafiche, condizioni del piano o regole individuali ricalcolano le sole rate anticipate **non ancora maturate**. L’importo originario e lo snapshot restano immutati; rettifiche aggiuntive conservano autore, data, importo e impronta dei nuovi dati. Anche una riconferma delle condizioni senza differenza monetaria può produrre una registrazione tecnica di importo zero. La scadenza di una previsione può essere aggiornata sotto lo stesso lock: la scadenza iniziale rimane nello snapshot e il cambiamento nell’audit.

Se il nuovo dovuto è inferiore alle somme imputate, le imputazioni della rata vengono annullate con registrazioni inverse e le somme tornano a credito disponibile sulle ricevute originali. Nessuna ricevuta o movimentazione bancaria viene cancellata o ricreata; il credito può essere nuovamente imputato. Il riepilogo della rettifica e l’audit permettono di ricostruire la variazione. Le rettifiche manuali sulle previsioni vanno ricontrollate quando cambiano le condizioni sorgenti: il ricalcolo applica le nuove condizioni e conserva comunque tutte le rettifiche precedenti.

Le rate già maturate non vengono ricalcolate automaticamente: richiedono le rettifiche motivate già disponibili. Eventuali scritture fuori dai servizi applicativi non sono supportate; un confronto dei dati con lo snapshot segnala le divergenze nelle previsioni. Le modifiche SQL manuali possono saltare audit e riconciliazione e non devono essere usate per gestire anagrafiche o piani.

## Esenzione amministrativa

Un’iscrizione esente non contribuisce ai giorni imponibili dei periodi di esenzione. **In Sonno** e **Depennato** impongono l’esenzione lato server, anche se un client tenta di disabilitarla. Per un’iscrizione nuova, l’esenzione parte dalla decorrenza; per una variazione successiva parte dalla data dell’operazione. Il depennamento chiude l’iscrizione alla data indicata: non sono dovute competenze successive alla cessazione. Le rate già maturate rimangono conservate.

La tabella `member_exemption_periods` mantiene gli intervalli: alla revoca l’intervallo si chiude al giorno precedente. Il ripristino dello stato Attivo non revoca automaticamente un’esenzione esplicita: occorre disabilitarla volontariamente. Una successiva generazione non recupera automaticamente quote dei periodi esenti. I periodi rientrano nell’esportazione GDPR; si eliminano soltanto nella procedura di cancellazione approvata quando nessun vincolo contabile richiede conservazione.

## Migrazione e rilascio

Nuova migrazione: `2026_10_17_100000_track_membership_exemption_periods`. Crea la tabella con chiave univoca e foreign key composta per loggia/iscrizione, normalizza il flag delle iscrizioni In Sonno/Depennato e inizializza gli intervalli noti. **Non modifica rate o movimenti esistenti.** Per i flag storici non sono disponibili date di attivazione certe: il backfill usa la decorrenza dell’iscrizione; per i depennati usa il giorno successivo alla cessazione. Prima del rilascio verificare questi dati con gli amministratori, soprattutto per ricostruzioni arretrate ancora da generare.

Eseguire prima backup e prova su copia di staging. Distribuire codice e `public/build`, applicare `php artisan migrate --force` secondo DEPLOYMENT.md e rigenerare cache di configurazione e viste. Non eseguire `migrate:fresh` in produzione. La migrazione blocca il rollback automatico quando sono presenti periodi di esenzione; una tabella vuota può essere rimossa: preferire correzioni in avanti e la procedura di ripristino collaudata. Non usare una vecchia versione della generazione che ignora le esenzioni dopo questo rilascio. Nessuna operazione è stata eseguita sul database di produzione durante lo sviluppo.

## Collaudo manuale

- Provare una generazione futura con iscrizione a metà semestre, esenzione, sconto e importo personalizzato; controllare l’anteprima prima della conferma.
- Modificare una condizione tra anteprima e conferma: nessuna rata deve essere creata con un’anteprima obsoleta.
- Ripetere la generazione e avviare il job giornaliero: nessuna duplicazione o entrata finanziaria.
- Cambiare esenzione/condizioni dopo la generazione: verificare rettifiche, eventuale credito liberato, nuova scadenza e audit. Confrontare le rate già maturate, che devono restare invariate.
- Verificare che morosi e report dei debiti maturati escludano rate future; controllare date italiane e stato Scaduta in rosso.
- Provare URL e conferme con un altro tenant, un Fratello e un permesso revocato.
- Verificare la tabella tesoreria e l’icona dettaglio su desktop e mobile, icone dei menù interni e fumetto Prorata con mouse e tastiera.
- Lasciare scadere la sessione su una pagina riservata: il contenuto deve scomparire, con avviso e collegamento al login; non devono esserci heartbeat automatici che prolunghino la sessione. Ripetere con una scheda in background e navigazione indietro.

## Verifiche eseguite sul repository

17 ottobre 2026: suite isolata SQLite, **365 test e 1.862 asserzioni**, Pint e PHPStan superati; build Vite completata. Test aggiunti su generazione/anteprima senza scritture finanziarie, conferma obbligatoria, idempotenza, tenant e permessi, anteprima obsoleta/scaduta, tutte le periodicità, iscrizione futura, esenzioni e prorata, ricalcolo delle previsioni con nuove condizioni/scadenze, credito liberato e mantenimento delle rate maturate, migrazione ripetuta, integrazione con incasso e storno reali del ledger, risposta di sessione scaduta e integrità dei link dei menù.

Browser Chromium con dati fittizi, larghezze 375 e 1.440 px: tabella tesoreria e icona dettaglio, data prima scadenza italiana, stato Scaduta rosso, tooltip, anteprima/conferma delle rate future e rimozione del contenuto alla scadenza simulata dell’orologio. Nessun errore JavaScript nei percorsi verificati. Nessun collegamento al database di produzione. La prova sul server cPanel e su dispositivi reali resta parte del collaudo di rilascio; per MySQL verificare la CI associata al commit.

## Correzione MySQL 8: nomi e recupero da DDL parziale

La migrazione del 17 ottobre usa ora nomi espliciti sotto i 64 caratteri: `member_exemption_periods_idx`, `member_exemption_periods_unique`, `member_exemption_lodge_fk`, `member_exemption_enrollment_fk`. La verifica automatica percorre anche tutti gli indici e le foreign key delle altre tabelle.

MySQL può conservare la tabella quando una successiva istruzione DDL fallisce. La migrazione non considera più sufficiente `hasTable()`: verifica colonne e chiave primaria, controlla le definizioni effettive degli indici e dei vincoli e aggiunge quelli mancanti prima del backfill. Indici o vincoli equivalenti già presenti, anche con i vecchi nomi validi, vengono conservati. Un nome già usato con una definizione incompatibile, una foreign key distruttiva, colonne mancanti o dati duplicati che impediscono il vincolo univoco bloccano il rilascio senza cancellare righe e senza registrare la migrazione fallita come eseguita. Il backfill non usa `INSERT IGNORE`, per evitare di nascondere errori nei dati.

Distribuire **entrambe** le migrazioni corrette: `2026_10_17_100000_track_membership_exemption_periods.php` e `2026_10_17_110000_repair_membership_exemption_structure.php`. La seconda verifica/ripara soltanto lo schema anche se una vecchia riesecuzione aveva già registrato impropriamente la prima; non ripete la normalizzazione dei flag né il backfill degli intervalli. Ripetere il normale `php artisan migrate --force` nella procedura di rilascio dopo backup e collaudo di staging. Non eliminare la tabella, le righe o le registrazioni di migrazione per aggirare il problema. Le incoerenze nei dati vanno risolte esplicitamente, verificando una copia isolata.

I test dedicati ricostruiscono un DDL interrotto, conservano e verificano righe preesistenti, controllano unicità e isolamento tra logge, esercitano riesecuzioni e recuperano il caso già registrato come migrato. Questi test usano soltanto SQLite in memoria o un MySQL di test con nome terminante in `_test`; nessun comando di test deve essere eseguito sul database operativo.

Il rollback della migrazione originaria rimuove soltanto una tabella vuota; in presenza di periodi di esenzione si interrompe senza cancellarli. Questo permette il teardown standard dei test MySQL su dati fittizi senza introdurre eccezioni basate sull’ambiente o disattivare i controlli in produzione.

Collaudo della correzione: **374 test SQLite (2.248 asserzioni)** e **390 test su MySQL 8.0.46 (2.737 asserzioni)** superati nel database effimero dedicato, inclusi nove casi sullo schema esenzioni, concorrenza, backup e ripristino. Pint e PHPStan superati. Il primo controllo MySQL aveva individuato anche il rollback erroneamente bloccato su tabella vuota: dopo la correzione, l’intera suite MySQL è stata rieseguita con esito positivo. Nessun accesso al database di produzione.
