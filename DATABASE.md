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
