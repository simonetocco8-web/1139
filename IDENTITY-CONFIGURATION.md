# Configurazione accesso, SMTP e SMS

Applicare entrambe le migrazioni della fase 2 con `php artisan migrate --force`, dopo backup protetto e verifica restore. Nessun account viene creato dal seed. Produzione: APP_ENV=production, APP_DEBUG=false, APP_URL=https://dominio-autorizzato, SESSION_DRIVER=database, SESSION_ENCRYPT=true, CACHE_STORE=database. PHP web/CLI 8.3+ e MySQL 8. HTTPS è obbligatorio; cookie Secure/HttpOnly/SameSite=Lax imposti in produzione. Configurare il proxy fidato solo se realmente presente; non fidarsi indiscriminatamente di header forwarded del client.

## SMTP
Configurare `.env` con valori effettivi del provider:
```dotenv
IDENTITY_DELIVERY_DRIVER=configured
MAIL_MAILER=smtp
MAIL_SCHEME=smtps
MAIL_HOST=
MAIL_PORT=465
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME="Servizio amministrativo"
MAIL_REQUIRE_TLS=true
```
Per submission STARTTLS sulla porta 587 utilizzare MAIL_SCHEME=smtp e mantenere MAIL_REQUIRE_TLS=true. Non disattivare verifica dei certificati. Timeout 10 secondi; invio sincrono, senza OTP in job/failed_jobs. Inviti, reset e OTP hanno testo neutrale senza nomi di logge o dettagli di appartenenza. Configurare SPF/DKIM/DMARC secondo il provider e verificare ricezione reale prima dell'uso operativo.
MAIL_MAILER=array/log non è una consegna valida: l'app richiede SMTP esplicito. Host/mittente mancanti producono errore di configurazione; nessuna autenticazione viene concessa. Un timeout può lasciare incerta la consegna esterna: viene mostrato “invio non confermato”, non un successo. Accettazione dal server SMTP non garantisce la ricezione finale.

## SMS configurabile
Il driver predefinito è disabilitato. Nessun provider commerciale o credenziale inventata:
```dotenv
SMS_DRIVER=http
SMS_ENDPOINT=
SMS_TOKEN=
SMS_SENDER=
```
Il gateway deve esporre un endpoint HTTPS POST, accettare `Authorization: Bearer <SMS_TOKEN>` e JSON `{"to":"+...","text":"...","sender":"..."}`; risposta HTTP 2xx con JSON `{"accepted":true}`. Timeout 10 secondi. Se il provider usa un altro contratto, predisporre un gateway conforme o un adapter CodeTransport, senza trattare una risposta generica 2xx come consegna. Segreti solo nell'ambiente server. In assenza di endpoint/token o con driver disabled, nessun SMS è dichiarato inviato.
Il telefono è E.164, cifrato a riposo, e deve essere verificato con OTP prima di selezionarlo per login. Il cambio canale revoca le sessioni. In questa consegna SMS è verificato con mock HTTP/fake: non è stata eseguita una spedizione commerciale reale.

## Segreti e bootstrap
APP_KEY viene generata una volta e conservata nei backup protetti: serve anche per telefono/payload/motivi assistenza cifrati. OTP_SECRET è facoltativo: se impostato deve essere casuale e lungo almeno 32 caratteri; altrimenti viene derivato da APP_KEY con HKDF. Digest HMAC-SHA256 separati per OTP, nonce, rate limiting e codici offline. Rotazione del segreto invalida challenge/codici offline precedenti; non ruotare APP_KEY senza migrare i dati cifrati. Non stampare OTP, link invito/reset o codici offline nei log.
Dopo SMTP configurato:
```sh
php artisan identity:invite-superuser destinatario-autorizzato@example.invalid
php artisan config:cache
php artisan route:cache
php artisan view:cache
```
Sostituire l'indirizzo di esempio. Il comando funziona solo prima di un Super User/invito iniziale valido; invia link monouso di 24 ore, senza password/token in console. Accettazione attiva un nuovo account ma non autentica: occorrono password e OTP. Un account già esistente accetta inviti aggiuntivi solo con la propria sessione verificata recente; l'invito non cambia password né riattiva account disabilitati. Il primo amministratore locale può essere invitato dal Super User soltanto dove manca un amministratore storico, mai invitando sé stesso.

## Revoca e recupero
Sessioni complete 30 minuti per impostazione predefinita, senza remember-me. Richieste sensibili richiedono OTP recente entro 5 minuti e password corrente ove previsto. Revoca aumenta session_version e rimuove sessioni DB, challenge e reset token; ogni richiesta ricontrolla la versione anche con altri driver sessione.
Generare dalla pagina Sicurezza i 10 codici offline monouso e conservarli separatamente; mostrati una sola volta, solo digest persistiti. Rigenerazione invalida i precedenti. Recupero secondo fattore richiede password, codice offline valido e OTP sul nuovo recapito, poi un nuovo login normale. Reset password non sostituisce il secondo fattore. Nessun override amministrativo: perdita di contatto e di tutti i codici offline non ha recupero automatico.
Assistenza: richiesta motivata del Super User, approvazione di diverso amministratore locale, scope espliciti senza amministrazione identità, durata massima 60 minuti, revocabile e auditata. Nessun accesso implicito ai contenuti locali.

## Test e operatività
IDENTITY_DELIVERY_DRIVER=fake è ammesso esclusivamente con APP_ENV=testing; mantiene messaggi in memoria e non spedisce. In produzione viene rifiutato. Test MySQL solo su database sacrificabile con nome terminante `_test`; la suite cancella lo schema. Non usare credenziali operative.
Il controllo `/up` non prova SMTP/SMS/database. Verificare canali reali, backup/restore, hosting e monitoraggio prima di dati reali. Conservazione di eventi/challenge e procedura di recupero manuale sono decisioni operative da definire, non bypass già implementati. Nessun dato personale reale incluso.
