# Eclisse Sync

Applicazione Laravel che sincronizza automaticamente il catalogo prodotti/scorte di Eclisse (esportato dal gestionale in CSV) con lo store Shopify, via Admin GraphQL API. Include dashboard di amministrazione (Filament), modalita' dry-run di default, rollback a un import precedente e backup automatici del database.

## Come funziona, in breve

1. **Fetch CSV**: ogni notte (orario configurabile) l'app scarica il CSV del gestionale, lo sanitizza (encoding misto UTF-8/Windows-1252, decimali con virgola, delimitatore `;`) e lo importa in tabelle di "staging".
2. **Diff**: confronta lo staging con lo stato canonico attuale (`products`/`product_variants`, cio' che risulta essere davvero su Shopify dopo l'ultimo sync riuscito) e calcola un piano: prodotti nuovi, aggiornati, invariati, spariti.
3. **Dry-run di default**: finche' non si sceglie esplicitamente "live", il run si ferma qui — il piano calcolato E' il risultato, nessuna chiamata a Shopify.
4. **Sync live**: se attivata, applica il piano via Admin GraphQL API, un job per prodotto (cosi' un fallimento singolo non blocca gli altri). **Non elimina mai nulla**: i prodotti spariti dal CSV vengono nascosti (draft) e le scorte azzerate, mai cancellati.
5. **Snapshot + rollback**: ad ogni sync live riuscita lo stato canonico viene congelato. Da un import passato riuscito si puo' lanciare un rollback, che riporta il catalogo a quello stato con le stesse garanzie (mai eliminare).
6. **Notifiche**: email/Slack opzionali su successo, fallimento o anomalia (es. calo sospetto di prodotti nel CSV, che blocca l'import per sicurezza).
7. **Backup**: dump del database + upload su Google Drive via rclone, schedulato.

Tutto questo e' pilotabile dalla dashboard `/admin` (Filament): stato degli import, log leggibili riga per riga, pulsante "Importa ora", pagina Impostazioni, catalogo prodotti.

## Stack tecnico

- Laravel 13, PHP 8.4
- Filament v4 (pannello admin)
- MySQL (produzione/Sail), SQLite va bene per prove rapide in locale
- Coda: driver **database** (non Redis) — scelta deliberata per compatibilita' con hosting condiviso senza demoni persistenti (vedi Fase 2)
- `league/csv` per il parsing dello streaming CSV

---

## Fase 1 — Sviluppo e test in locale (Windows 11)

### Prerequisiti

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) per Windows, con backend WSL2 attivo
- Git
- Uno store Shopify di sviluppo (gratuito, via [Shopify Partner Dashboard](https://www.shopify.com/partners))

### Setup

Da un terminale WSL2 (consigliato) o PowerShell con Docker attivo:

```bash
git clone <url-del-repository> eclisse-sync
cd eclisse-sync
cp .env.example .env
```

Installa le dipendenze PHP con un container Composer usa-e-getta (non serve PHP installato sul PC):

```bash
docker run --rm -v "$(pwd):/app" -w /app composer:2 install --ignore-platform-reqs
```

Avvia i container (app + MySQL + Redis, anche se Redis non e' usato per coda/cache per restare coerenti con la produzione):

```bash
./vendor/bin/sail up -d
```

Genera la chiave applicativa, esegui le migrazioni e crea l'utente admin:

```bash
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan make:filament-user
```

La dashboard e' ora raggiungibile su **http://localhost/admin** con le credenziali appena create.

### Configurare lo store Shopify di sviluppo

1. Nel Partner Dashboard Shopify, crea/apri lo store di sviluppo.
2. Nello store, vai su **Impostazioni > App e canali di vendita > Sviluppo app**, crea una "custom app".
3. Configura gli scope Admin API necessari: `write_products`, `read_products`, `write_inventory`, `read_inventory`, `read_locations`.
4. Installa l'app sullo store e copia l'**Admin API access token** (visibile una sola volta).
5. Nel file `.env`:

```
SHOPIFY_STORE_DOMAIN=il-tuo-store-dev.myshopify.com
SHOPIFY_ADMIN_API_ACCESS_TOKEN=shpat_xxxxxxxxxxxxxxxx
```

6. Riavvia i container perche' la nuova configurazione venga letta: `./vendor/bin/sail up -d`.

### Provare un import

Dalla dashboard: **Import > Importa ora**. Di default parte in dry-run (nessuna chiamata a Shopify): mostra solo il piano calcolato. Attiva l'interruttore "Applica davvero" solo quando vuoi davvero scrivere su Shopify.

Da terminale, equivalente:

```bash
# Dry-run, eseguito subito nel processo corrente
./vendor/bin/sail artisan import:run --sync

# Live (scrive davvero su Shopify)
./vendor/bin/sail artisan import:run --sync --live
```

Senza `--sync` il comando accoda il job invece di eseguirlo subito: serve un worker attivo per processarlo:

```bash
./vendor/bin/sail artisan queue:work
```

### Eseguire i test

```bash
./vendor/bin/sail artisan test
# oppure, equivalente:
./vendor/bin/sail composer test
```

La test suite non tocca mai credenziali Shopify reali (bloccate esplicitamente in `phpunit.xml`) e gira contro un database SQLite in memoria, isolato da quello di sviluppo.

### Comandi utili in locale

| Comando | Cosa fa |
|---|---|
| `sail artisan import:run --sync` | Import manuale dry-run, subito |
| `sail artisan import:run --sync --live` | Import manuale live, subito |
| `sail artisan queue:work` | Processa i job in coda (sync Shopify, rollback) |
| `sail artisan schedule:work` | Simula in locale lo scheduler di produzione (utile per provare il sync notturno/backup senza aspettare l'orario reale) |
| `sail artisan schedule:list` | Mostra i job schedulati e i prossimi orari |
| `sail artisan test` | Esegue la suite di test |

---

## Fase 2 — Deploy su DirectAdmin/host.it

Pensato per un account reseller con SSH **jailed** (niente root, niente Supervisor o demoni persistenti): tutto quello che serve gira da un'**unica riga di cron**.

### 1. Caricare il codice ed installare le dipendenze

Via Git (se disponibile sull'hosting) o upload manuale, poi via SSH:

```bash
cd ~/percorso/dell/app
composer install --no-dev --optimize-autoloader
```

### 2. Configurare `.env` di produzione

```bash
cp .env.example .env
php artisan key:generate
```

Valori da impostare (forniti dal pannello DirectAdmin, sezione database):

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://il-tuo-dominio.it

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=<nome_db_directadmin>
DB_USERNAME=<utente_db_directadmin>
DB_PASSWORD=<password_db_directadmin>

QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database
```

`QUEUE_CONNECTION=database` e `CACHE_STORE=database` **non sono opzionali** su questo tipo di hosting: non richiedono Redis ne' un demone persistente, funzionano ovunque.

Email (per le notifiche, se configurate in Impostazioni) e credenziali Shopify come da sezione dedicata piu' sotto.

### 3. Document root

Laravel serve tutto da `public/`, ma DirectAdmin spesso punta il dominio direttamente a `public_html/`. Due opzioni, in ordine di preferenza:

- **Se il pannello lo permette**: nella sezione "Domain Setup" di DirectAdmin, cambia il document root del dominio in modo che punti a `public_html/../eclisse-sync/public` (percorso assoluto alla cartella `public/` dell'app). E' l'opzione piu' pulita.
- **Se non e' permesso cambiarlo**: sposta l'app FUORI da `public_html` (es. in una cartella privata a fianco), poi in `public_html/index.php` fai semplicemente:

```php
<?php
require __DIR__.'/../eclisse-sync/public/index.php';
```

(e copia/adatta anche `public_html/.htaccess` da quello originale in `public/.htaccess`, aggiornando i path se necessario).

### 4. Migrazioni e utente admin

```bash
php artisan migrate --force
php artisan make:filament-user
```

### 5. L'unica riga di cron

Nel pannello DirectAdmin, sezione **Cron Jobs**, aggiungi una voce ogni minuto:

```
* * * * * cd /percorso/assoluto/dell/app && php artisan schedule:run >> /dev/null 2>&1
```

Questa singola riga governa tutto:
- il **sync notturno automatico** (orario e attivazione letti da Impostazioni ad ogni giro, senza dover mai ritoccare il cron);
- il **backup automatico** giornaliero;
- il **worker della coda**: uno scheduled job interno (`queue:work --stop-when-empty`) gira ogni minuto e processa qualsiasi job in coda (sync Shopify, rollback), simulando un worker persistente senza bisogno di uno vero.

Verifica che sia configurata correttamente:

```bash
php artisan schedule:list
```

### 6. Backup automatici: configurare rclone

`rclone` deve essere installato ed autenticato **una volta sola** su questa macchina. Se l'account SSH e' jailed e senza browser, usa l'autenticazione a due passi:

1. Su un PC/Mac qualsiasi con browser: installa rclone e lancia `rclone authorize "drive"`. Si apre il browser, autorizzi l'accesso a Google Drive, e il comando stampa un blocco di configurazione (token JSON).
2. Sull'hosting, via SSH: `rclone config`, crea un nuovo remote di tipo `drive`, e quando richiesto incolla il token ottenuto al passo precedente invece di lasciare che apra un browser (che sull'hosting non c'e').
3. Dai al remote lo stesso nome usato in `.env` (default `gdrive`), oppure aggiorna `BACKUP_RCLONE_REMOTE` di conseguenza:

```
BACKUP_RCLONE_REMOTE=gdrive:eclisse-sync-backups
BACKUP_RCLONE_BINARY=rclone
BACKUP_MYSQLDUMP_BINARY=mysqldump
BACKUP_RETENTION_DAYS=30
```

Se `mysqldump`/`rclone` non sono nel `PATH` di default della shell non interattiva usata dal cron, usa il percorso assoluto (es. `/usr/bin/mysqldump`) nelle due variabili sopra — verificalo con `which mysqldump` e `which rclone` via SSH.

Prova un backup manuale prima di fidarti dello schedulato:

```bash
php artisan backup:run
```

### 7. Passaggio allo store Shopify reale

Fino a questo punto tutto puo' (e dovrebbe) essere provato contro lo store di sviluppo. Il passaggio allo store vero e proprio e' un'azione esplicita, mai automatica:

1. Crea una custom app sullo **store Shopify reale** (stessa procedura della Fase 1, sezione "Sviluppo app").
2. Aggiorna `.env` di produzione con `SHOPIFY_STORE_DOMAIN` e `SHOPIFY_ADMIN_API_ACCESS_TOKEN` dello store reale.
3. In dashboard, **Impostazioni**, verifica che "Dry-run di default" resti attivo finche' non hai fatto almeno un giro di verifica manuale.
4. Lancia un primo import manuale in dry-run (**Import > Importa ora**, senza attivare "Applica davvero") e controlla il piano calcolato con calma.
5. Solo quando il piano ti convince, lancia un giro live manuale con pochi prodotti se possibile, verifica su Shopify che sia tutto corretto (immagini, scorte, categorie), **prima** di disattivare "Dry-run di default" per lasciare che il sync notturno applichi le modifiche in automatico.

### 8. Verifica finale

- `php artisan schedule:list` mostra i tre job schedulati.
- `php artisan backup:run` completa senza errori e il file compare nel Google Drive collegato.
- La dashboard e' raggiungibile su `https://il-tuo-dominio.it/admin`.
- I log applicativi sono in `storage/logs/laravel.log` (consultabili via SSH con `tail -f`).

---

## Uso quotidiano della dashboard

- **Import** (`/admin/import-runs`): elenco di tutti gli import (schedulati, manuali, rollback), con stato live, contatori, e pulsante **Importa ora**. Cliccando su un import si vede il dettaglio (anomalie, errori) e il log leggibile riga per riga.
- **Impostazioni** (`/admin/sync-settings`): URL del CSV, orario del sync notturno, soglia di anomalia (calo % di prodotti che blocca l'import per sicurezza), modalita' dry-run di default, email/Slack per le notifiche.
- **Catalogo** (`/admin/products`): stato canonico attuale del catalogo sincronizzato, con le varianti di ogni prodotto (EAN, prezzo, scorte).

### Rollback

Da un import **live completato con successo** che ha uno snapshot disponibile, il pulsante **"Rollback a questo import"** (nella pagina di dettaglio) riporta il catalogo a quello stato: i prodotti apparsi dopo vengono nascosti, quelli cambiati ripristinati. Come per un import normale, parte in dry-run finche' non si attiva esplicitamente "Applica davvero".

### Notifiche

In Impostazioni, inserendo un'email e/o un URL webhook Slack e attivando i relativi flag (successo/fallimento/anomalia), si riceve un avviso automatico a fine di ogni run (schedulato, manuale o rollback). L'invio e' "best effort": un problema di rete su email o Slack non blocca mai l'import.

### Ripristinare un backup

I backup sono dump gzippati caricati su Google Drive (`eclisse-sync-YYYY-MM-DD-HHMMSS.sql.gz`). Per ripristinarne uno:

```bash
gunzip -c eclisse-sync-2026-01-01-030000.sql.gz | mysql -u <utente> -p <nome_database>
```

**Attenzione**: questo sovrascrive il database corrente. Da usare solo in caso di disastro (es. corruzione dati), non come alternativa al rollback applicativo descritto sopra (che agisce anche su Shopify, non solo sul database locale).

---

## Note di sicurezza

- Le credenziali Shopify e le password del database vivono solo in `.env`, mai nel database applicativo ne' in Git.
- La policy "mai eliminare" e' applicata a livello di codice (`ProductSyncer`), non solo di configurazione: un prodotto sparito dal CSV viene sempre e solo nascosto (draft) e azzerato di scorte.
- Dry-run e' il default in ogni punto d'ingresso (comando manuale, sync notturno, rollback): la modalita' live richiede sempre un'attivazione esplicita, mai implicita.
