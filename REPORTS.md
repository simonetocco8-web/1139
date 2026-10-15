# Fase 7 — dashboard e report

## Accessi e schermate

`/dashboard` apre la panoramica piattaforma per il Super User, oppure la panoramica della loggia selezionata per gli utenti locali. Senza selezione propone il selettore, senza interrogare contenuti economici. `/admin/panoramica` richiede amministrazione piattaforma. `/logge/{lodge}/panoramica` richiede secondo fattore valido, accesso locale e corrispondenza con la loggia attiva.

Il Super User vede conteggi di logge attive, utenti e obbedienze, stato tecnico e attività amministrative minimizzate. Nessun nome di iscritto, operazione o importo locale. Membership e assistenza esplicita rimangono necessarie per accedere alle singole logge; il flag globale non concede lettura automatica.

Amministratore e Tesoriere vedono banche, casse, disponibilità, residui delle capitazioni e dei canoni, prossime scadenze nei 30 giorni e sei movimenti recenti del periodo, con collegamenti alle operazioni esistenti. Il riepilogo dei flussi richiede anche `reports`. I ruoli e le autorizzazioni restano quelli della fase 2, per loggia.

Il Fratello vede solo la persona associata al proprio account nella loggia attiva: rate, imputazioni, versamenti netti effettivi, arretrati, credito, scadenze e storico personale. Il collegamento con un'altra loggia non unisce i dati. Senza associazione anagrafica non vengono ricercate persone per nome o email. La preferenza `brothers_treasury_visible` abilita esclusivamente saldi aggregati correnti, senza nominativi di conti, causali o movimenti. Le ulteriori letture richiedono i permessi locali già esistenti.

Report: `/logge/{lodge}/report/{kind}`; esportazioni: stesso percorso più `/esporta/csv` o `/esporta/pdf`.

| kind | Contenuto |
| --- | --- |
| financial | Situazione finanziaria e saldi per conto nel periodo |
| categories | Entrate/uscite nette per categoria |
| balances | Apertura, variazione e chiusura di banche/casse |
| capitations | Dovuto, imputato e residuo per fratello |
| arrears | Rate scadute con residuo, raggruppate per fratello |
| levies | Canoni con destinatario storico, scadenza e stato |
| annual | Prospetto mensile dell'anno, fino a oggi se corrente |
| personal | Sole rate della persona associata all'account |

I report gestionali richiedono `reports` e lettura completa; quello personale richiede `read` e proprietà locale. L'export aggiunge sempre `export`, anche per il prospetto personale. Il ruolo Fratello predefinito non dispone di export. Filtri, identificativi e formati vengono validati sul server; modificare un URL non cambia il tenant autorizzato. Ogni richiesta controlla nuovamente account, sessione OTP, ruoli e loggia attiva. Le route Livewire esistenti conservano i middleware persistenti.

## Criteri economici

Nessun nuovo saldo persistito o movimento contabile: le query leggono i moduli delle fasi 4–6, con somme SQL DECIMAL convertite in centesimi interi. Ogni report/dashboard locale viene composto in una transazione di lettura; MySQL InnoDB con isolamento predefinito REPEATABLE READ mantiene una vista coerente tra le query. Non cambiare l'isolamento a READ COMMITTED senza adeguare questa garanzia.

I report finanziari selezionano per **data contabile**. Saldo iniziale = righe finanziarie prima dell'inizio; saldo finale = righe fino alla fine inclusa. Si verifica l'equazione:

`saldo iniziale + entrate nette − uscite nette + aperture/rettifiche = saldo finale`.

I giroconti modificano i conti coinvolti ma hanno effetto complessivo nullo e non sono entrate/uscite economiche. Gli storni compensano i bucket originali, con segno opposto, nella loro data contabile. Debiti non pagati non modificano i saldi. L'imputazione successiva di credito non crea un ulteriore incasso. Il prospetto annuale include aperture e rettifiche separatamente e non proietta mesi futuri.

I report delle obbligazioni mostrano lo **stato attuale delle rate/canoni selezionati per scadenza**: le imputazioni e gli storni sono quelli attuali, anche se successivi al periodo selezionato. Non costituiscono una ricostruzione del debito a una data passata; questa distinzione è scritta nelle schermate e nei documenti esportati. Gli arretrati escludono rate saldate e scadenze odierne, e per impostazione predefinita ricercano dal 01/01/2000 a oggi. I totali correnti della dashboard non sono limitati dal filtro finanziario. Non si modificano snapshot e condizioni storiche.

Nella dashboard personale i versamenti effettivi del periodo sono separati dalle imputazioni alle rate: incassi per `received_on`, meno incassi stornati per data contabile dello storno. Un rimborso a novembre non riscrive il flusso di ottobre; lo stato attuale della ricevuta e della rata resta visibile. Credito e arretrati sono correnti.

## Documenti privati e mobile

Schermata, CSV e PDF usano lo stesso `Document`: identici criteri, importi e righe per i medesimi filtri. CSV UTF-8 BOM, separatore punto e virgola, importi decimali con virgola e intestazioni EUR; celle testuali protette dalla formula injection. PDF A4 orizzontale con DejaVu Sans, niente fetch remoti, PHP o JavaScript. Risposte `private, no-store` e `nosniff`; nessun file esportato salvato in public o URL condivisibile. La copia scaricata resta sul dispositivo del destinatario autorizzato. L'audit registra tipo, formato, periodo e numero righe, senza importi o nomi.

Paginazione delle card a 20 righe; massimo 5.000 righe per i report delle obbligazioni e per CSV, 250 per PDF. Oltre il limite la richiesta viene rifiutata con indicazione di restringere il periodo, senza documenti troncati. Export soggetti al rate limit account/IP esistente. Nessuna nuova dipendenza Composer/npm.

Mobile con menu richiudibile, metriche prioritarie, card e liste senza tabelle larghe, selettore report e form di periodo. Date e input in `gg/mm/aaaa`, importi in euro italiano; le query accettano anche date ISO per i collegamenti. Validazione del calendario e limiti sul server. Indicatori di aggiornamento filtri, preparazione download, successo ed errore. Senza JavaScript rimangono navigazione, filtri ed export nativi. Nessuna risorsa esterna obbligatoria. Non è un audit completo WCAG.

## Migrazione e stato tecnico

Nuova migrazione `2026_10_15_020000_create_system_heartbeats.php`: tabella tecnica con nome PK e ultimo segnale UTC a microsecondi. Non altera tabelle o storia contabile. Backup prima del deploy; `php artisan migrate --force`, build aggiornata `public/build`. Il rollback elimina solo i segnali tecnici; è stato verificato sul database effimero di test.

Lo scheduler scrive il segnale ogni minuto, tramite il cron `schedule:run` già documentato nel README. Il Super User vede «Non rilevato» prima del primo segnale, «Segnale recente» entro cinque minuti o «Segnale in ritardo» oltre. Non si dichiara funzionante un cron mai osservato. Controlli tecnici: raggiungibilità DB, migrazioni mancanti, numero di job in attesa/falliti e scrivibilità archivio privato. Nessuna esposizione di payload, errori sensibili, percorsi, credenziali o versioni. Il segnale non attesta esecuzione della coda o consegna SMTP/SMS. Nessun messaggio viene inviato da questo modulo.

Dati e schermate di verifica sono completamente fittizi. Il seed predefinito resta vuoto; i seeder dei moduli precedenti rimangono facoltativi e limitati a locale/testing. Nessun account o bypass demo distribuito. Hosting cPanel, cron e provider reali richiedono la verifica nell'ambiente di destinazione. La fase 7 non introduce altri moduli né procedure di rilascio operativo per dati reali.
