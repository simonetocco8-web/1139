# Checklist di collaudo e autorizzazione al rilascio

Compilare con responsabile, data, commit, ambiente, prova conservata ed esito. Le spunte nella prima sezione descrivono solo il collaudo locale documentato; **le caselle hosting restano aperte**. Un problema critico di isolamento, tesoreria, file pubblici o segreti blocca la produzione.

## Evidenze software locali

- [x] Nuovi test per notifiche proprie, ruoli, tenant, deduplica, saldo prima dell'invio, revoche e preferenze.
- [x] Canale assente non marcato come spedizione; esito incerto senza retry automatico, ulteriore tentativo esplicito auditato.
- [x] Job con soli identificativi, due worker MySQL con un solo invio esterno/tentativo.
- [x] Form CSRF, escaping XSS, header production e logger senza messaggi sensibili verificati.
- [x] Esportazione privacy locale, richieste cifrate, assenza di policy di cancellazione predefinita.
- [x] Cancellazione bloccata da storia contabile; caso non finanziario concluso cancellabile soltanto secondo policy esplicita.
- [x] Backup cifrato, chiave errata/manomissione/troncamento/traversal rifiutati; ripristino reale MySQL con confronto saldi/report/allegato.
- [x] Schermate Chromium desktop/mobile con dati fittizi e login password+OTP SMTP locale, senza overflow o richieste esterne.
- [x] Suite locali complete: SQLite 320 test/1.490 asserzioni, MySQL 336 test/1.635 asserzioni; stile, analisi statica e build superati.
- [ ] Registrare la CI del commit approvato prima del rilascio.

## Hosting e separazione pubblica — bloccanti

- [ ] PHP CLI/web 8.3+ ed estensioni, incluse sodium/PDO MySQL; `composer check-platform-reqs` superato sull'host reale.
- [ ] MySQL 8 InnoDB, UTC e REPEATABLE READ effettivi; credenziali dedicate e privilegi limitati, TLS verificato per DB remoto.
- [ ] `flock`, `timeout`, proc_open e mysqldump 8 disponibili; limiti cron/CPU/memoria compatibili con prova sul volume atteso.
- [ ] Document root solo public, oppure alternativa public_html col front controller e APP_PUBLIC_PATH coerenti anche da CLI.
- [ ] Nessun altro dominio/symlink dell'account espone app, .env, vendor, storage privato, chiavi o backup.
- [ ] Verifica HTTP reale: `/.env`, `/.git/config`, `/composer.json`, `/vendor/autoload.php`, `/storage/`, `/backup-secret.key`, dump/backup e URL allegati diretti non restituiscono contenuti riservati. Controllare codice **e corpo**, non solo uno status diverso da 200.
- [ ] Directory listing assente, .htaccess applicata, permessi minimi e dati/segni 0700/0600 dove previsto; mai 0777.
- [ ] Redirect HTTPS anche per asset e `/up`, certificato/CA validi, APP_ENV=production, APP_DEBUG=false, APP_URL corretto.
- [ ] Cookie Secure/HttpOnly/SameSite e sessioni cifrate effettivi; header, proxy fidati, host e URL tecnici verificati.
- [ ] Asset e vendor del commit approvato, migrazioni ripetibili e cache ricostruite nell'ambiente; nessun seed/account demo/bypass.

## Accessi e moduli finanziari — bloccanti

- [ ] Invito con destinatario autorizzato, nessuna registrazione pubblica, login completo soltanto dopo password+OTP.
- [ ] OTP scaduto/usato/errato, limiti/reinvio e recupero password/fattore verificati con canali reali; nessun bypass di emergenza.
- [ ] Revoca sessioni, ruoli e assistenza efficace sulle richieste successive, anche Livewire/export/file.
- [ ] Amministratore A non legge/modifica B cambiando URL/payload; Fratello vede soltanto la propria posizione per tenant.
- [ ] Super User senza accessi espliciti non ottiene persone, documenti o finanze locali.
- [ ] Saldi iniziali, entrata, spesa, giroconto, parziale multi-rata, credito, canone e storno riconciliati su dati di collaudo.
- [ ] Ripetere un job/pagamento con stessa chiave non duplica; spese concorrenti non producono cassa negativa o posting parziale.
- [ ] `operations:integrity --files` superato; CSV/PDF confrontati con ledger e residui attuali dichiarati, date/euro corretti.

## Consegna e automazioni

- [ ] SMTP TLS effettivo, mittente neutro, dominio/SPF/DKIM/DMARC, destinatari e limiti approvati; nessun MAIL_URL che scavalchi TLS.
- [ ] SMS, se usato: provider/contratto HTTPS, token sicuro, redirect bloccati, esito accettato e consegna testati. Altrimenti SMS lasciato disabilitato.
- [ ] Abilitazione globale e locale esplicita delle notifiche; preferenze e ruoli per ciascuna loggia verificati.
- [ ] Email/SMS economici senza importi, nomi o posizioni di altri; testo amministrativo solo nell'area riservata.
- [ ] Cron scheduler e queue separati ma coordinati dal lock; nessun daemon, worker termina, nessuna starvation fra cron.
- [ ] Generazioni giornaliere, promemoria, manutenzione temporanei e heartbeat osservati realmente; ripetizione senza duplicati.
- [ ] Configurazione coda stessa connessione/tabella applicativa, after_commit=false, queue communications/default, retry_after maggiore del watchdog.
- [ ] Guasto SMTP, interruzione worker e backlog provati; stato incerto visibile e reinvio solo con verifica/conferma.
- [ ] Monitor esterno attivo su hosting/cron/DB/quota/copia backup; destinatari reali e piano incidenti definiti. Non dipendere soltanto dalla coda per rilevare il suo arresto.

## Backup, disaster recovery e rollback — bloccanti

- [ ] Chiave privata backup custodita offline con escrow; sola chiave pubblica sull'hosting, versioni/rotazione documentate.
- [ ] Backup cron cifrato completato con manutenzione e lock, nessun writer concorrente; hash/fingerprint/catalogo esterno verificati.
- [ ] Copia cifrata esterna automatica e versionata/immutabile configurata e collaudata; quota e mancata copia generano alert indipendente.
- [ ] RPO/RTO e conservazione approvati; non cancellare vecchi archivi/chiavi arbitrariamente.
- [ ] Prova di restore dall'archivio **esterno** su DB isolato senza privilegi verso produzione; SHA-256 da catalogo fidato, file e chiavi ripristinati, cache ricostruite.
- [ ] Saldi, obbligazioni, parziali/credito/storni e allegati confrontati; durata e perdita massima registrate.
- [ ] Quarantena runtime eseguita; account, recapiti, credenziali e ruoli rivalidati con procedura umana prima di riattivare un vecchio snapshot.
- [ ] Registro esterno aggiornato delle cancellazioni e decisioni successive riconciliato sul restore prima dell'uso.
- [ ] Rollback codice provato senza migrate:rollback, senza eliminare contabilità e con coda comunicazioni sospesa prima di tornare al vecchio codice.

## Privacy, rischi e approvazioni

- [ ] Titolare/responsabili, basi giuridiche, accessi, fornitori e valutazione dei dati di appartenenza definiti; eventuale DPIA effettuata.
- [ ] Politica firmata con tempi per ogni categoria, backup/log e copie esterne; variabili di retention ancora zero se non approvate.
- [ ] Anteprima `operations:prune` controllata prima di --apply; nessun audit/fatto finanziario eliminato.
- [ ] Richieste export/rettifica/cancellazione collaudate; documenti con terzi revisionati e account globale trattato separatamente.
- [ ] Cancellazione non finanziaria esplicita provata; blocco su vincoli e snapshot contabili confermato. Nessuna “cancellazione totale” promessa impropriamente.
- [ ] Rischi email/SMS OTP e scelta del fattore approvati; considerare WebAuthn/passkey per ruoli/dati ad alto rischio.
- [ ] Ripetuto audit Composer nativo da rete autorizzata; controllo alternativo GitHub e npm non considerati prova assoluta di assenza CVE.
- [ ] Pentest indipendente, audit WCAG e prestazioni sul carico atteso completati o residui formalmente valutati senza accettare rischi critici.
- [ ] Nessuna funzione/configurazione incompleta è presentata agli utenti come pronta.

## Verbale finale

Commit: ____________________  Ambiente/hosting: ____________________

Evidenze e difetti aperti: __________________________________________

Titolare/responsabile privacy: ______________  Responsabile tecnico: ______________

Data e decisione: ____________________

**Non autorizzare produzione** con esposizione di segreti/file, accessi cross-tenant, bypass OTP o discrepanze finanziarie. Il completamento del codice della fase 8 e la CI verde non sostituiscono questo verbale.
