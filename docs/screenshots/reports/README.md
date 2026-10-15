# Dashboard e report — schermate reali

Acquisite il 15/10/2026 con Chromium/Playwright, dopo login password e OTP consegnato a un server SMTP locale di test. Solo logge, persone e operazioni completamente fittizie. Nessun account demo o bypass viene distribuito nell'applicazione.

Desktop 1440×1000; mobile 320×740. Immagini a pagina intera, menu mobile chiuso: metriche prioritarie, liste/card, nessuno scorrimento orizzontale rilevato. I due viewport usano logge demo distinte; i totali non sono cumulati.

| Schermata | Desktop | Mobile |
| --- | --- | --- |
| Super User — piattaforma | [Apri](dashboard-piattaforma-desktop.png) | [Apri](dashboard-piattaforma-mobile.png) |
| Amministratore/Tesoriere — loggia | [Apri](dashboard-admin-desktop.png) | [Apri](dashboard-admin-mobile.png) |
| Fratello — situazione personale | [Apri](dashboard-brother-desktop.png) | [Apri](dashboard-brother-mobile.png) |
| Situazione finanziaria | [Apri](report-financial-desktop.png) | [Apri](report-financial-mobile.png) |
| Entrate/uscite per categoria | [Apri](report-categories-desktop.png) | [Apri](report-categories-mobile.png) |
| Saldi banca/cassa | [Apri](report-balances-desktop.png) | [Apri](report-balances-mobile.png) |
| Capitazioni per fratello | [Apri](report-capitations-desktop.png) | [Apri](report-capitations-mobile.png) |
| Arretrati | [Apri](report-arrears-desktop.png) | [Apri](report-arrears-mobile.png) |
| Canoni pagati/da pagare | [Apri](report-levies-desktop.png) | [Apri](report-levies-mobile.png) |
| Prospetto annuale mensile | [Apri](report-annual-desktop.png) | [Apri](report-annual-mobile.png) |
| Filtro finanziario dal 01/01 | [Apri](report-periodo-annuale-desktop.png) | [Apri](report-periodo-annuale-mobile.png) |
| Prospetto personale | [Apri](report-personale-desktop.png) | [Apri](report-personale-mobile.png) |

Il Super User senza accessi locali non vede saldi; il Fratello non vede esportazioni senza permesso e riceve 403 richiedendo il report finanziario. Nei due viewport sono stati scaricati CSV e PDF del report finanziario e verificati gli importi: banca 980,00 €, cassa 140,00 €, totale 1.120,00 €, entrate 30,00 €, uscite 10,00 €, giroconto escluso dai flussi. Il seeder dei moduli precedenti e fixture private di test preparano i dati; le fixture di account non sono nel repository.
