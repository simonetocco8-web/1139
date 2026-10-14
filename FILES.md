# File della prima fase

Tutti i file dello starter sono nuovi nel repository inizialmente vuoto. I file principali per la revisione:

| File / directory | Scopo |
| --- | --- |
| ARCHITECTURE.md, DATABASE.md, REQUIREMENTS.md, ROADMAP.md | Analisi iniziale, assunzioni, relazioni/vincoli futuri, fasi e gate |
| README.md, VERIFICATION.md, FILES.md, LICENSE | Avvio, deploy cPanel, esiti e limiti, inventario e licenza |
| composer.json / composer.lock | Laravel 13, Fortify, Livewire 4 e dipendenze compatibili PHP 8.3 |
| package.json / package-lock.json / vite.config.js | Build Vite/Tailwind senza Node in produzione |
| .env.example | MySQL/SMTP senza credenziali, locale e storage/sessioni/coda/cache |
| config/app.php, platform.php, fortify.php, filesystems.php | Impostazioni di base, autenticazione chiusa e file privati non serviti automaticamente |
| bootstrap/app.php, app/Http/Middleware/PrivacyHeaders.php | Routing, health neutra e header privacy |
| app/Providers/FortifyServiceProvider.php | Disabilitazione totale delle route Fortify nella fase 1 |
| resources/views/components/layouts/foundation.blade.php | Layout responsive con navigazione e skip link |
| resources/views/welcome.blade.php | Pagina pubblica senza dati personali |
| app/Livewire/FoundationStatus.php, resources/views/livewire/foundation-status.blade.php | Componente Livewire pubblico senza azioni di modifica |
| resources/css/app.css, resources/js/app.js, public/build | Frontend e asset compilati per hosting tradizionale |
| public/robots.txt | Divieto indicizzazione |
| database/migrations, database/seeders/DatabaseSeeder.php | Tabelle infrastrutturali dello starter e seed vuoto |
| tests/Feature/FoundationTest.php, phpunit.xml, phpstan.neon, pint.json | Test e controlli iniziali |
| .github/workflows/tests.yml | CI PHP 8.3/Node 24 predisposta |

`.env`, database SQLite locale, vendor, node_modules, log/cache e intermedi non fanno parte dei sorgenti versionabili. In produzione generare APP_KEY e configurare i servizi reali. Gli asset public/build sono inclusi per rendere il frontend consegnabile senza Node sul server.

## Aggiunte organizzative

| File / directory | Scopo |
| --- | --- |
| ORGANIZATION.md, ORGANIZATION-VERIFICATION.md | Decisioni, invarianti, limiti e verifica della nuova fase |
| database/migrations/2026_10_14_160000_create_organization_tables.php | Organizzazione, compatibilità, storico territoriale e audit |
| database/migrations/2026_10_14_160100_add_organization_access.php | Flag amministrazione piattaforma, membership e ruoli separati |
| app/Domains/Organization/Models | Relazioni Eloquent e divieto eliminazione distruttiva |
| app/Domains/Organization/Actions | Validazione, transazioni/lock, invarianti e selezione autorizzata |
| app/Domains/Organization/Policies | Policy amministrative senza bypass globale |
| app/Domains/Identity/VerifiedSession.php, app/Http/Middleware/RequireVerifiedSession.php | Confine di integrazione con il futuro OTP; nessun login implementato |
| app/Livewire/Organization, resources/views/livewire/organization | CRUD, filtri, paginazione, disattivazione e selettore |
| resources/views/components/layouts/organization.blade.php | Navigazione amministrativa mobile/desktop |
| database/seeders/OrganizationDemoSeeder.php | Demo esplicita idempotente senza account |
| tests/Feature/OrganizationTest.php, tests/MySQL, phpunit.mysql.xml, tests/mysql-bootstrap.php | Test gerarchie, vincoli, isolamento e protezione del DB di test |
| docs/screenshots | Schermate reali con fixture fittizie |

Le fixture di autenticazione per le schermate sono esterne al repository, legate a un DB locale separato e server loopback; non vengono distribuite.
