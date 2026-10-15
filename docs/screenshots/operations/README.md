# Fase 8 — notifiche e privacy

Schermate reali del 15/10/2026, Chromium/Playwright: desktop 1440×1000 e mobile 320×740, pagina intera. Login con password e OTP inviato a un sink SMTP locale di test. Solo persone/logge/contenuti completamente fittizi, nessun account o bypass demo distribuito.

| Schermata | Desktop | Mobile |
| --- | --- | --- |
| Preferenze, regole e storico amministratore | [Apri](notifiche-admin-desktop.png) | [Apri](notifiche-admin-mobile.png) |
| Preferenze e storico personale Fratello | [Apri](notifiche-brother-desktop.png) | [Apri](notifiche-brother-mobile.png) |
| Dettaglio avviso amministratore | [Apri](avviso-admin-desktop.png) | [Apri](avviso-admin-mobile.png) |
| Dettaglio avviso personale | [Apri](avviso-brother-desktop.png) | [Apri](avviso-brother-mobile.png) |
| Alert tecnici piattaforma | [Apri](alert-piattaforma-desktop.png) | [Apri](alert-piattaforma-mobile.png) |
| Strumenti privacy amministratore | [Apri](privacy-admin-desktop.png) | [Apri](privacy-admin-mobile.png) |
| Strumenti privacy personali | [Apri](privacy-brother-desktop.png) | [Apri](privacy-brother-mobile.png) |
| Richiesta privacy registrata dall'amministratore | [Apri](richiesta-privacy-admin-desktop.png) | [Apri](richiesta-privacy-admin-mobile.png) |
| Richiesta privacy personale confermata | [Apri](richiesta-privacy-brother-desktop.png) | [Apri](richiesta-privacy-brother-mobile.png) |

Verificati nei due viewport: modifica preferenza con conferma, cronologia/dettaglio, richiesta di rettifica, download JSON proprio e rifiuto del report finanziario per Fratello. Nessun overflow orizzontale, errore JavaScript, risposta 5xx o risorsa esterna. La coda ha spedito avvisi neutri al server SMTP **locale di test**; non è un collaudo di provider o destinatari reali. Gli alert di backup assente sono mostrati come problemi effettivi, senza fingere un servizio configurato.
