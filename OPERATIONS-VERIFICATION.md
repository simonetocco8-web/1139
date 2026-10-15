# Verifiche fase 8 — 15 ottobre 2026

Lavoro ripreso dai file salvati dopo l'interruzione: nessuna duplicazione dei moduli precedenti. Ambiente di test PHP 8.4.26, Laravel 13, PHPUnit 11.5.57, Node 24.19.0 e MySQL 8.0.46 InnoDB effimero. La CI verifica PHP 8.3, Node 24 e MySQL 8. Nessun dato o destinatario reale.

## Esiti locali

| Controllo | Esito |
| --- | --- |
| Composer validate --strict / check-platform-reqs | Superati, inclusa ext-sodium |
| Pint completo con cache nuova | Superato |
| Larastan livello 7 | Nessun errore |
| composer test, SQLite | **320 test, 1.490 asserzioni**, superati |
| Suite completa MySQL | **336 test, 1.635 asserzioni**, superati |
| npm run build | Superato, public/build aggiornato |
| npm audit | Zero vulnerabilità segnalate |
| Advisory GitHub per 138 versioni Composer incluse dev | Zero risultati; filtro verificato con controllo positivo vulnerabile |
| Composer audit nativo locale | Non completabile: proxy nega packagist.org; non dichiarato superato |
| Migrazione fase 8 MySQL | Applicata, rollback di un passo, riapplicazione e seconda migrate senza perdita dei 10 movimenti/20 righe finanziarie demo |
| Shell wrapper / front controller alternativo | sh -n e lint PHP superati; collaudo cPanel reale ancora necessario |

Entrambi gli audit delle dipendenze sono ora gate della CI. L'esito della singola esecuzione GitHub riguarda il commit relativo; il risultato del controllo locale bloccato resta distinto. Non considerare questi cataloghi prova dell'assenza di vulnerabilità.

## Nuova copertura

**39 test funzionali** (`OperationsTest`, `EncryptedBackupTest`) e **due test MySQL** dedicati, oltre all'intera suite preesistente:

- Opt-in globale/locale, ruoli ammessi, destinatario proprietario e persona non associata; nessun dato di altri iscritti nei messaggi economici. Preavviso/scaduto con finestre e chiavi stabili, ripetizione senza duplicati.
- Rata saldata, membership revocata, tenant disattivato e preferenza cambiata prima del consumo annullano l'invio. SMS richiede telefono verificato. Super User senza membership non riceve comunicazioni locali.
- Canale non configurato bloccato senza accettazione fittizia; tentativi automatici distanziati/limitati. Eccezione/interruzione produce esito incerto, non retry automatico. Tentativo esplicito auditato anche dopo tre blocchi, senza azzerare lo storico.
- Job duplicati non generano doppi invii; pump ripetuto non riaccoda durante il lease. Payload della coda privo di indirizzi e nominativi. Corpo amministrativo cifrato, idempotente, non incluso nell'email e correttamente escapato nell'app.
- Endpoint locali, proprietà, ruoli e centro globale verificati contro URL manomessi/escalation. Centro tecnico senza storico locale e alert deduplicati.
- JSON privacy solo del proprietario/loggia, niente credenziali; richieste cifrate e visibilità locale. Cancellazione senza policy o con legami contabili bloccata; cancellazione autorizzata della sola anagrafica non finanziaria conclusa, senza alterare altre logge o ledger.
- Retention in anteprima, default senza eliminazioni, necessità di policy approvata; invii incerti conservati e finestra minima per deduplica. Registro cancellazioni cifrato e decifrabile con scopo esplicito.
- Header produzione, POST senza CSRF 419, blocco MAIL_URL alternativo e reporting senza messaggi sensibili. Preflight segnala configurazioni mancanti anziché dichiarare readiness.
- Archivio cifrato binario a flusso, roundtrip di SQL/chiavi/file privati e permessi 0600/0700; chiave errata, alterazione, troncamento, traversal, directory esistente, chiavi sotto alias pubblico e digest esterno errato rifiutati.

`CommunicationConcurrencyTest`: due processi MySQL indipendenti partono insieme sul medesimo outbox. Il trasporto di test registra un solo effetto esterno, un solo tentativo e stato accepted. È una prova di lock/commit reale, non un mock del conteggio delle righe.

`BackupRestoreTest`: fixture con saldi iniziali, entrata/spesa, giroconto, rettifica, capitazione parziale con credito e pagamento canone. Backup tramite **mysqldump 8 reale**, cifrato con chiave pubblica; secondo backup ordinario idempotente. Archivio decifrato, database dedicato ricreato da zero, dump importato con **mysql reale**, file privato ripristinato e decifrato. Confrontati metriche e righe dei report, invarianti e bytes/digest dell'allegato. Quarantena revoca runtime e disattiva account senza eliminare movimenti. Nella sessione locale i client reali sono quelli del container temporaneo tramite adapter di test; la CI usa i client MySQL del runner. Docker non è una dipendenza dell'applicazione.

## Correttezza finanziaria

La fixture integrata conserva apertura 1.100,00 €, entrate nette 260,00 €, uscite nette 55,00 €, rettifiche −10,00 € e chiusura **1.295,00 €**. Giroconto escluso dai totali economici; imputazione del credito senza un nuovo incasso; dovuto/residui indipendenti dai saldi finché non avviene pagamento. Report e file recuperati coincidono dopo il restore. Un'alterazione intenzionale delle righe viene rilevata dal controllo di integrità. Restano nella suite i test MySQL di posting concorrenti, idempotenza, cassa negativa, parziali e storni delle fasi precedenti.

## Browser e consegna di test

Chromium/Playwright, desktop **1440×1000**, mobile **320×740**, tre ruoli separati e logge distinte. Login password più OTP ricevuto da SMTP locale, senza marker di sessione costruiti dal browser. Verificati preferenze con conferma, storico, dettaglio, richieste privacy e download JSON personale. Il Fratello vede una sola persona locale e riceve 403 sul report finanziario. Il centro piattaforma non contiene notifiche economiche locali.

Nessun overflow orizzontale, errore JavaScript, risposta 5xx o risorsa esterna. Il worker database è terminato a coda vuota; i messaggi al sink SMTP locale hanno corpo neutro. **Non** sono stati provati consegna SMTP/SMS reali o cron cPanel. [18 schermate reali](docs/screenshots/operations/README.md), soltanto dati fittizi; fixture/account di browser non distribuiti.

## Non completato sull'ambiente di destinazione

Non sono stati forniti hosting, credenziali, provider/destinatari, archivio esterno o politiche approvate. Restano da configurare/collaudare:

- document root o alternativa public_html, HTTPS/proxy/header/file negati, PHP/DB/strumenti e limiti del piano;
- cron reali, consegna SMTP e SMS eventuale, monitor esterno e quote;
- replica automatica cifrata esterna, catalogo fidato/escrow, RPO/RTO e restore dal deposito esterno;
- approvazione privacy/retention e procedura umana di rivalidazione degli account dopo disaster recovery;
- pentest indipendente, WCAG completo e carico effettivo.

Non implementati come automatismi: ricevute di consegna del provider/idempotenza end-to-end, passkey/WebAuthn, riattivazione indiscriminata degli account dopo restore, distruzione automatica della contabilità o cancellazione globale indistinta di account/copie. I canali mancanti e gli esiti incerti sono segnalati esplicitamente. Questi limiti non sono presentati come funzioni operative pronte. **Gestionale non dichiarato pronto per la produzione**: completare la checklist e non accettare problemi critici di isolamento, OTP, esposizione dei segreti o correttezza contabile.
