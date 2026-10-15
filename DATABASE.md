# Database — schema organizzativo implementato e domini futuri

MySQL 8.x InnoDB, utf8mb4; identificatori BIGINT UNSIGNED; importi DECIMAL(15,2), mai FLOAT. Calcoli in centesimi interi o decimal string con arrotondamento esplicito half-up; vietato cast float. FK RESTRICT per dati amministrativi/contabili; cancellazione controllata, non cascade su registrazioni contabilizzate. In fase 1 si installano esclusivamente le tabelle infrastrutturali dello starter (users, password_reset_tokens, sessions, cache/cache_locks, jobs/job_batches/failed_jobs). Seed senza account o persone demo.

## Organizzazione (implementata nella fase organizzativa autorizzata)
- obediences: id, code UNIQUE, name, contatti opzionali, status.
- rites: id, code UNIQUE, name; obedience_rite UNIQUE(obedience_id,rite_id).
- structures: id, obedience_id FK, parent_id nullable, level nazionale/regionale/provinciale, name, contatti e IBAN facoltativi, status; UNIQUE(obedience_id,id); FK composita (obedience_id,parent_id) -> structures(obedience_id,id).
- lodges: id, obedience_id FK, rite_id FK, number, name, contatti, IBAN, status, settings JSON; UNIQUE(obedience_id,number). Compatibilità rito/obbedienza verificata da servizio e FK sulla pivot ove obbligatoria.
- lodge_territorial_assignments (sostituisce la pivot lodge_structure prevista): id, lodge_id, structure_id, obedience_id, level, structure_name snapshot, starts_at UTC, ended_at UTC nullable; colonna active_level generata = level solo se ended_at è NULL; UNIQUE(lodge_id,active_level). FK composite per obbedienza/loggia e obbedienza/struttura/livello. Le righe chiuse rimangono storiche e non sono riaperte o riscritte. Livelli facoltativi, nessuna catena obbligatoria a tre livelli.
- organization_events: attore FK users RESTRICT, tipo/id entità, created/updated/activated/deactivated, occurred_at UTC; solo insert nelle Action, senza dettagli personali.
- lodge_user e lodge_user_roles: schema di predisposizione selettore, membership con stato e più ruoli per tenant. Gestione tramite inviti e ruoli locali nella fase 2; nessun account demo. users.is_platform_admin default false, non fillable.
Le migrazioni 2026_10_14_160000_create_organization_tables.php e 2026_10_14_160100_add_organization_access.php implementano questi elementi con indici esplicitamente brevi dove necessario per il limite MySQL di 64 caratteri.
La gerarchia richiede transazione con lock delle strutture dell'obbedienza: vietati auto-parent, cicli, genitori di livello subordinato e obbedienze differenti. La FK non impedisce cicli da sola.

## Identità e iscrizioni (fase 2/3)
- users globali: email UNIQUE normalizzata, password hash, verified channels, session_version, stato, platform_role separato.
- members: id, lodge_id, user_id nullable, nome, cognome, telefono, email, joined_on DATE, status, note cifrate opzionali; UNIQUE(lodge_id,id). Persona locale distinta da account; nessuna deduplicazione globale che riveli appartenenze.
- lodge_user: lodge_id, user_id, status; UNIQUE(lodge_id,user_id).
- lodge_user_roles: lodge_id,user_id,role; UNIQUE(lodge_id,user_id,role), FK su lodge_user; più ruoli consentiti.
- invitations: id, lodge_id, email, token_hash UNIQUE, expires_at, accepted_at, invited_by; ruoli verificati server-side; nessun token in chiaro.
- otp_challenges: id, user_id, channel, code_hash, expires_at, consumed_at, attempts, delivery_status; indice(user_id,expires_at). Challenge legato a sessione pending; aggiornamento atomico contro replay.
- assisted_access_grants: lodge_id, requester_id, approver_id, reason, scopes JSON, starts_at, expires_at, revoked_at. Separato dalle membership ordinarie, approvazione espressa auditata.

## Capitazioni e canoni (fasi future)
- capitation_plans: lodge_id, amount DECIMAL, frequency, starts_on, ends_on nullable, due_rule, status; UNIQUE(lodge_id,id).
- member_adjustments: lodge_id,member_id,plan_id,valid_from/to, tipo esenzione/riduzione e valore decimal; FK composite. Sovrapposizioni vietate dal servizio.
- receivables: lodge_id,member_id,plan_id,period_start/end,due_on,amount DECIMAL; UNIQUE(lodge_id,member_id,plan_id,period_start). Snapshot dell'importo maturato, non riscritto retroattivamente.
- levy_plans: lodge_id,structure_id,amount DECIMAL,frequency,per_member,valid_from/to,due_rule.
- payables: lodge_id,levy_plan_id,structure_id,period_start/end,due_on,amount,member_count_snapshot; UNIQUE(lodge_id,levy_plan_id,period_start). Snapshot del conteggio: attivi alla data di inizio periodo, assunzione da confermare.
- payment_allocations: lodge_id,receipt/posting_id,receivable_id o payable_id,amount DECIMAL; CHECK esattamente uno dei due; FK composite su ogni relazione tenant. Pagamenti parziali e anticipi non allocati separati, rettifiche in righe collegate all'originale. Lock dei debiti e pagamenti prima di allocare; vietata sovra-allocazione.
Maturazione genera credito/debito, mai movimento di cassa. Arretrato = residuo con due_on < data odierna locale; il residuo include rettifiche tracciate e allocazioni.

## Tesoreria (fasi future)
- treasury_accounts: lodge_id,id,type bank/cash,label,currency EUR; UNIQUE(lodge_id,id).
- financial_operations: lodge_id,id,kind,posted_on,description,category_id,created_by,reverses_id nullable,idempotency_key; UNIQUE(lodge_id,id), UNIQUE(lodge_id,idempotency_key). Nessun delete/update delle operazioni contabilizzate.
- financial_entries: lodge_id,operation_id,account_id,amount DECIMAL signed; FK composite. Saldo = SUM(entries), saldo iniziale come operazione opening. Giroconto due righe opposte, somma zero, stessa valuta, conti distinti e stessa loggia, inserimento atomico. Storno nuove righe inverse collegate, una sola inversione autorizzata, originale immutato.
- attachments: lodge_id,operation_id,private_path,mime,size,checksum; FK composite, nomi casuali, limiti MIME/dimensione e download autorizzato.
- audit_events: actor_id,lodge_id nullable,event,subject_type/id,reason,request_id,occurred_at; accesso ristretto, contenuti minimizzati, append-only. Non sostituiscono il libro dei movimenti.

## Test necessari su MySQL
FK composite, CHECK, collazione/unicità, locking concorrente, deadlock retry, consumo OTP, doppi job e doppie allocazioni. SQLite per test infrastrutturali non certifica queste proprietà; non dichiarare completata la verifica MySQL senza un'istanza MySQL 8.

## Stato delle verifiche
Schema e suite realmente eseguiti su MySQL 8.0.46 InnoDB, oltre a SQLite; dettagli aggiornati in ORGANIZATION-VERIFICATION.md. Contabilità e persone iscritte restano progettate; identità, OTP e assistenza sono ora implementate come descritto sotto. Il blocco iniziale "In fase 1" descrive le sole fondamenta precedenti, non lo stato corrente della fase organizzativa.

## Migrazioni identità implementate
`2026_10_14_170000_create_identity_security_tables.php` aggiunge users.is_active/activated_at/session_version/phone cifrato/phone_verified_at/otp_channel; identity_controls per serializzare bootstrap; identity_invitations con digest unico, ruoli JSON, emittente e tenant, scadenza/consumo/revoca/stato consegna; otp_challenges UUID con digest HMAC, nonce sessione, versione credenziali, payload cifrato, contatore e date di consumo/invalidation/consegna; identity_recovery_codes con digest unico monouso; assisted_access_grants con richiedente/approvatore, motivo cifrato, scope e durata; identity_events con metadati minimali. Foreign key RESTRICT e indici account/scopo/scadenza o tenant/utente/scadenza preservano referenzialità e ricerche. Nessun OTP/token/codice recupero in chiaro.
`2026_10_14_170100_preserve_territorial_timestamp_precision.php` porta starts_at/ended_at dello storico territoriale a TIMESTAMP(6), senza riscriverne il collegamento. Timestamp di sicurezza UTC; connessione MySQL +00:00. Rollback identità elimina challenge/inviti/audit e attributi: non è una procedura di disattivazione e richiede backup/restore. Mai migrate:fresh su dati operativi.
I nomi nella sezione progettuale precedente sono indicativi: i nomi effettivi sono identity_invitations, identity_recovery_codes, identity_events e code_digest. Il modulo members non è implementato.

## Fase 3 — schema effettivo
Migrazione `2026_10_14_180000_create_membership_tables.php`:
- member_people: lodge_id FK RESTRICT, user_id opzionale FK RESTRICT, reference locale univoco, nome/cognome, nascita DATE facoltativa, email anagrafica, telefono/indirizzo cifrati, versione; UNIQUE(lodge_id,id), UNIQUE(lodge_id,reference), UNIQUE(lodge_id,user_id), indice tenant/cognome/nome. Nessuna persona globale condivisa implicitamente.
- member_enrollments: lodge_id/person_id FK composita RESTRICT, starts_on/ends_on DATE locali, stato active/suspended/ended, esenzione e motivazione cifrata, versione; UNIQUE(tenant,persona,inizio). open_person_id generata se ends_on NULL, UNIQUE(tenant,open_person_id) per un solo periodo aperto. CHECK MySQL 8 fine>=inizio e ended richiede fine; sovrapposizioni tra intervalli chiusi impedite dall'Action sotto lock, non dalla sola FK.
- member_history: persona/tenant FK composita RESTRICT, attore opzionale FK RESTRICT, evento, snapshot cifrato e occurred_at TIMESTAMP(6) UTC; indice tenant/persona/id. Appende soltanto; nessuna route delete/update storico.
- member_imports: tenant/attore FK RESTRICT, digest token unico, digest nonce sessione, payload cifrato, scadenza/consumo TIMESTAMP(6), indice tenant/attore/scadenza. Dati temporanei rimossi da prune; payload svuotato al consumo.
- identity_invitations.person_id opzionale con FK composita lodge_id/person_id RESTRICT; solo inviti locali possono associare la persona dopo accettazione e controlli correnti.
- lodges: administrative_email, reminder_days (0–30 validato), brothers_treasury_visible default false; i campi organizzativi e bancari non sono modificabili dal controller impostazioni locali.

Il progetto members di fase iniziale è ora separato in member_people e member_enrollments. Non ci sono tabelle finanziarie: i futuri legami devono preservare gli ID storici e adottare FK composite sul tenant. Rollback fase 3 distruttivo per il modulo, solo su test/restore controllato. Dati demo fittizi senza account.

## Fase 4 — schema capitazioni effettivo
`2026_10_14_190000_create_capitation_tables.php` installa:
- capitation_plans: tenant, descrizione, periodicità, inizio/fine DATE locali; identità/calendario immutabili.
- capitation_plan_versions: FK composita piano/tenant, decorrenza univoca per piano, importo DECIMAL(15,2), descrizione, due_basis/due_days, prorata e is_active; append-only.
- capitation_person_rules: FK piano/tenant e persona/tenant, decorrenza/fine DATE, kind standard/exempt/discount/custom, punti base o importo, motivazione cifrata; versione unica per persona/piano/ciclo.
- capitation_installments: FK composite tenant/piano/persona/iscrizione; UNIQUE(tenant,piano,persona,period_start), periodo/scadenza DATE, original_amount DECIMAL(15,2) e snapshot JSON della versione/condizione/intervalli. Indici scadenzario e chiavi composite tenant/persona/id. Nuovo indice di proprietà su member_enrollments per FK del primo intervallo usato.
- capitation_corrections: FK rata/tenant, variazione DECIMAL firmata, motivo cifrato, attore, chiave idempotente/digest. Non si cambia original_amount.
- capitation_receipts: FK persona/tenant, importo positivo DECIMAL, received_on DATE, riferimento/etichetta conto e riferimento movimento unico per tenant, chiave idempotente/digest. Nessuna ricevuta senza conferma del gateway condiviso; nessun conto o movimento di cassa qui.
- capitation_allocation_batches: ricevuta/tenant FK, chiave/digest idempotenti; protegge ripetizione dell'intero riparto.
- capitation_allocations: FK composite rata e ricevuta con stessa persona/tenant, DECIMAL firmato, reversal opzionale collegato univocamente, motivo cifrato/attore. Alloca solo entro credito e residuo; annullamento con riga inversa, mai delete.

Timestamp tecnici UTC a microsecondi; DATE locali tramite CalendarDate. MySQL CHECK su quote positive, giorni scadenza 0–60, tipi/parametri condizioni, rate non negative/intervalli, ricevute positive e imputazioni non nulle. FK RESTRICT; modelli immutabili. Dovuto/versato/residuo/credito derivati da righe originali e rettifiche/imputazioni, mai saldi modificabili. Le vecchie tabelle progettuali receivables/capitation_plans non sono nomi effettivi di questa fase; canoni/tesoreria restano futuri.

## Fase 5 — canoni
Quattro tabelle: levy_programs (identità calendario), levy_conditions (versioni per ambito), levy_obligations (snapshot locali) e levy_payments (riferimenti alle uscite reali). UNIQUE obbedienza/codice, programma/ambito/decorrenza, loggia/programma/periodo, loggia/chiave pagamento e loggia/riferimento Tesoreria. FK composite su appartenenza a obbedienza, condizione del programma, assegnazione/destinatario dello stesso tenant, pagamento/obbligo. Indici scadenze e pagamenti, importi DECIMAL(15,2), DATE locali e timestamp UTC(6); IBAN degli obblighi e motivazioni cifrati. CHECK MySQL impediscono importi negativi, pagamenti nulli e intervalli non validi. Nessun saldo finanziario in queste tabelle. Migrazione e regole in LEVIES.md.
