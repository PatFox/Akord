# Akord

Akord is a contract app for projects with several parties: individuals, teams and AI agents. A contract is made of phases and steps. Parties comment on steps, edit them and approve them, and every change is kept in history. Editing a step cancels its approvals. Once every step is approved and all required parties have signed off, the contract locks; later changes go into a new version. An owner can delete a contract once it is cancelled, with its whole history. Agents work through a REST API and an MCP server.

Akord installs from a published [release](https://github.com/PatFox/Akord/releases) in one of two ways:

- [**On Windows**, as a local instance](#installing-on-windows) at `http://localhost:8100`, run by PHP's built-in server. Its data lives in `%LOCALAPPDATA%\Akord` and it starts when you log in. See also [Updating on Windows](#updating-on-windows).
- [**On an Ubuntu server**, in an Apache virtual host](#installing-on-an-ubuntu-server) with HTTPS and a domain name, running PHP through PHP-FPM or mod_php. See also [Updating on an Ubuntu server](#updating-on-an-ubuntu-server).

## Installing on Windows

### Requirements

- Windows 10 or 11. Windows PowerShell 5.1 is built in.
- PHP 8.3 or later (x64) on your `PATH`, with the extensions listed in step 1. You don't need Node, Composer or a separate web server.
- Two free ports, 8100 and 8101 by default.

### Step 1: Install PHP

Skip this step if `php -v` already shows 8.3 or later and `php -m` lists every extension in item 5.

1. Download the **x64 Non Thread Safe** zip of PHP 8.3 or later from <https://windows.php.net/download/>.
2. Extract it to a folder such as `C:\php`. This guide uses `C:\php`; change the paths below if yours is different.
3. Add the folder to your `PATH`, then open a **new** PowerShell window:
   ```powershell
   [Environment]::SetEnvironmentVariable('Path', [Environment]::GetEnvironmentVariable('Path', 'User') + ';C:\php', 'User')
   ```
4. In `C:\php`, copy `php.ini-production` to `php.ini`.
5. Open `php.ini` and remove the `;` at the start of each of these lines:
   ```ini
   extension_dir = "ext"
   extension=curl
   extension=fileinfo
   extension=mbstring
   extension=openssl
   extension=pdo_sqlite
   extension=sodium
   ```
6. Check it: `php -v` should show 8.3 or later, and `php -m` should list `curl`, `fileinfo`, `mbstring`, `openssl`, `pdo_sqlite` and `sodium`. `ctype`, `dom` and `tokenizer` are built in.

### Step 2: Give PHP a CA certificate bundle

Without a CA bundle, PHP can't make HTTPS calls. Akord needs them to check new passwords against known breaches and to send mail over TLS. The installer warns you if the bundle is missing.

1. Create the folder `C:\php\extras\ssl` if it doesn't exist, and download the bundle into it:
   ```powershell
   New-Item -ItemType Directory -Force C:\php\extras\ssl | Out-Null
   Invoke-WebRequest -UseBasicParsing https://curl.se/ca/cacert.pem -OutFile C:\php\extras\ssl\cacert.pem
   ```
2. In `php.ini`, find `;curl.cainfo =` and `;openssl.cafile=`, remove the `;`, and point both at the file:
   ```ini
   curl.cainfo = "C:\php\extras\ssl\cacert.pem"
   openssl.cafile = "C:\php\extras\ssl\cacert.pem"
   ```
3. Check it: `php -r "echo ini_get('curl.cainfo');"` should print the path.

### Step 3: Download the installer

```powershell
cd $env:USERPROFILE\Downloads
Invoke-WebRequest -UseBasicParsing https://github.com/PatFox/Akord/releases/latest/download/akord.ps1 -OutFile akord.ps1
```

### Step 4: Run the installer

```powershell
powershell -ExecutionPolicy Bypass -File .\akord.ps1 install
```

The installer:

1. checks PHP, its extensions and the two ports;
2. downloads the latest release and verifies its SHA-256 checksum;
3. creates `%LOCALAPPDATA%\Akord`, with a settings file (`shared\.env`) that has new random keys and production settings;
4. creates the SQLite database and the OAuth signing keys;
5. adds a shortcut to your Startup folder, so Akord starts when you log in;
6. starts the web server, the Reverb live-update server and the queue worker, and waits until they respond.

It takes about a minute and ends with `Akord vX.Y.Z is running at http://localhost:8100`. The instance keeps its own copy of the script at `%LOCALAPPDATA%\Akord\akord.ps1`, so you can delete the one in Downloads.

These options change the defaults:

| Option | Default | Meaning |
|---|---|---|
| `-Port 8200` | `8100` | Port of the web app |
| `-ReverbPort 8201` | `8101` | Port of the live-update server |
| `-Root D:\Akord` | `%LOCALAPPDATA%\Akord` | Where the instance lives |
| `-Version v1.2.0` | latest release | Install a specific release |
| `-NoAutostart` | | Don't start Akord at logon |
| `-Package .\akord-v1.2.0.zip` | | Install from a downloaded release zip instead of GitHub |

### Step 5: Create your account

1. Open <http://localhost:8100/register> and create an account. The password needs at least 12 characters, with upper and lower case letters, a number and a symbol. It must also not appear in a known data breach.
2. Akord writes emails to its log instead of sending them. To get the verification link, run:
   ```powershell
   Select-String -Path "$env:LOCALAPPDATA\Akord\shared\storage\logs\laravel-*.log" -Pattern 'http://localhost:\d+/email/verify/[^\s"<>)\]]+' |
       Select-Object -Last 1 | ForEach-Object { $_.Matches[0].Value -replace '&amp;', '&' }
   ```
   Open the link in the same browser.
3. Optional, for real email such as invitations and notifications: set `MAIL_MAILER=smtp` and the other `MAIL_*` values in `%LOCALAPPDATA%\Akord\shared\.env`, then restart Akord (see below).

### Managing the instance

Run the instance's own copy of the script. In PowerShell:

```powershell
$akord = "$env:LOCALAPPDATA\Akord\akord.ps1"
powershell -ExecutionPolicy Bypass -File $akord status
```

| Command | What it does |
|---|---|
| `status` | Shows the version, whether Akord is running, its URL and where the files are |
| `stop` | Stops Akord |
| `start` | Starts Akord |
| `restart` | Stops and starts Akord. Run it after editing `shared\.env` |
| `uninstall` | Stops Akord and removes the logon shortcut. Delete `%LOCALAPPDATA%\Akord` to remove all data |

Files in `%LOCALAPPDATA%\Akord`:

| Path | Contents |
|---|---|
| `shared\.env` | Settings: URL, ports, mail, keys |
| `shared\database\akord.sqlite` | All data |
| `shared\storage\logs\` | `laravel-<date>.log` (the app and its emails), plus `web.log`, `reverb.log`, `queue.log` and `supervisor.log` |
| `releases\` and `current` | The installed versions. `current` points at the active one |
| `backups\` | Database copies, one taken before each update |

## Updating on Windows

### Update to the latest release

```powershell
powershell -ExecutionPolicy Bypass -File "$env:LOCALAPPDATA\Akord\akord.ps1" update
```

The update:

1. downloads the release and verifies its checksum;
2. prepares it alongside the running version, which keeps working meanwhile;
3. stops Akord. It stays unavailable until step 6, usually less than half a minute;
4. backs up the database to `backups\akord-<old version>-<date>-<time>.sqlite`;
5. applies the new version's database migrations;
6. switches to the new version and starts it.

If any of steps 5–6 fails, the script puts the database backup back, switches to the previous version, starts it again, and reports the cause. Your settings in `shared\.env` and your data are kept across updates. The last 3 versions and the last 10 backups are kept.

If you're already on the latest release, it says `Already on vX.Y.Z` and changes nothing.

### Other update options

| Option | Meaning |
|---|---|
| `-Version v1.2.0` | Update to that release. This is the only way to install a prerelease such as `v1.3.0-rc.1`. Don't use it to go back to an older version; see below |
| `-Package .\akord-v1.2.0.zip` | Update from a downloaded release zip. A `.sha256` file next to it is checked |
| `-Force` | Reinstall the version that is already active |

### Going back to an earlier version

There is no rollback command. To return to the version you had before an update:

> **Note:** this also returns the database to its state before that update, so anything entered since then is lost.

```powershell
cd $env:LOCALAPPDATA\Akord
powershell -ExecutionPolicy Bypass -File .\akord.ps1 stop
dir backups                                    # pick the backup taken before the update
Copy-Item backups\akord-v1.1.0-20261117-104955.sqlite shared\database\akord.sqlite -Force
cmd /c rmdir current                           # removes the link only, not the release
cmd /c mklink /J current releases\v1.1.0
powershell -ExecutionPolicy Bypass -File .\akord.ps1 start
```

## Installing on an Ubuntu server

This runs Akord under its own domain, in an Apache virtual host with HTTPS. Apache runs PHP in one of two ways; you choose in step 2. Reverb (the live-update server) and the queue worker run as systemd services. Apache passes Reverb's WebSocket traffic through the same virtual host, so everything uses port 443.

The commands are for Ubuntu 24.04 LTS, which ships PHP 8.3. Replace `akord.example.com` with your domain and `v1.2.0` with the [release](https://github.com/PatFox/Akord/releases) you install.

### Requirements

- Ubuntu 24.04 LTS, and a user that can run `sudo`.
- A domain name whose DNS record points at the server.
- Ports 80 and 443 open to the internet. Reverb also needs port 8080 free on the server itself; it only listens on `127.0.0.1`, so don't open 8080.
- An SMTP server for email (account verification, invitations, notifications). You can start without one; see step 4.

### Step 1: Install Apache and PHP

```bash
sudo apt update
sudo apt install -y apache2 php8.3-cli php8.3-curl php8.3-mbstring php8.3-sqlite3 php8.3-xml unzip certbot python3-certbot-apache
sudo a2enmod rewrite proxy proxy_http
```

Check that PHP has every extension Akord needs. This should print `9`:

```bash
php -m | grep -cxE 'ctype|curl|dom|fileinfo|mbstring|openssl|pdo_sqlite|sodium|tokenizer'
```

### Step 2: Choose how Apache runs PHP

Pick **one** of the two options. Don't enable both.

**Option A: PHP-FPM (recommended).** PHP runs in its own pool of processes, and Apache keeps its default event MPM, which holds many open connections with little memory. PHP settings are in `/etc/php/8.3/fpm/php.ini`.

```bash
sudo apt install -y php8.3-fpm
sudo a2enmod proxy_fcgi setenvif
sudo a2enconf php8.3-fpm
```

**Option B: mod_php.** PHP runs inside Apache's own processes. It is simpler, but needs the prefork MPM, which uses one process (with PHP loaded) for each open connection. PHP settings are in `/etc/php/8.3/apache2/php.ini`.

```bash
sudo apt install -y libapache2-mod-php8.3
sudo a2dismod mpm_event
sudo a2enmod mpm_prefork php8.3
```

Installing the package usually switches Apache to prefork and enables `php8.3` by itself. The two commands after it make sure; if they print "already disabled" or "already enabled", that's fine.

To switch from mod_php to PHP-FPM later, run `sudo a2dismod php8.3 mpm_prefork && sudo a2enmod mpm_event`, then do option A. To switch the other way, run `sudo a2disconf php8.3-fpm`, then do option B. Restart Apache afterwards with `sudo systemctl restart apache2`.

### Step 3: Unpack the release

Download the release and check it:

```bash
VERSION=v1.2.0
cd /tmp
curl -fLO https://github.com/PatFox/Akord/releases/download/$VERSION/akord-$VERSION.zip
curl -fLO https://github.com/PatFox/Akord/releases/download/$VERSION/akord-$VERSION.zip.sha256
sha256sum -c akord-$VERSION.zip.sha256
```

The last command must print `akord-v1.2.0.zip: OK`.

Unpack it into `/var/www/akord`:

```bash
sudo mkdir -p /var/www/akord/releases /var/www/akord/shared/database /var/www/akord/backups
sudo unzip -q /tmp/akord-$VERSION.zip -d /var/www/akord/releases/$VERSION
cd /var/www/akord
sudo mv releases/$VERSION/storage shared/storage
sudo cp releases/$VERSION/.env.example shared/.env
sudo touch shared/database/akord.sqlite
sudo ln -s /var/www/akord/shared/storage releases/$VERSION/storage
sudo ln -s /var/www/akord/shared/.env releases/$VERSION/.env
sudo ln -sfn /var/www/akord/releases/$VERSION current
sudo chown -R www-data:www-data /var/www/akord
sudo chmod 640 shared/.env
```

Each release gets its own folder under `releases/`, and `current` points at the active one. Settings (`shared/.env`), files and logs (`shared/storage`) and the database (`shared/database`) live outside the releases, so updates leave them alone.

### Step 4: Configure Akord

Make three random values for Reverb's credentials:

```bash
echo "REVERB_APP_ID=$(shuf -i 100000-999999 -n 1)"
echo "REVERB_APP_KEY=$(openssl rand -hex 10)"
echo "REVERB_APP_SECRET=$(openssl rand -hex 20)"
```

Open the settings with `sudo nano /var/www/akord/shared/.env`. Change the lines below. If a line is missing or starts with `#`, add it or remove the `#`. Use your own domain, mail server and the three Reverb values from above.

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://akord.example.com

LOG_STACK=daily

DB_CONNECTION=sqlite
DB_DATABASE=/var/www/akord/shared/database/akord.sqlite

SESSION_SECURE_COOKIE=true

MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=your-username
MAIL_PASSWORD=your-password
MAIL_FROM_ADDRESS="akord@example.com"

REVERB_APP_ID=123456
REVERB_APP_KEY=your-random-key
REVERB_APP_SECRET=your-random-secret
REVERB_HOST="akord.example.com"
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT=8080
```

- **Mail:** if your mail server uses port 465, also set `MAIL_SCHEME=smtps`. Without a mail server, set `MAIL_MAILER=log`, and Akord writes emails to its log instead (step 8 shows how to find the verification link).
- **Reverb:** `REVERB_HOST`, `REVERB_PORT` and `REVERB_SCHEME` are the public address that browsers use, and that Akord itself uses to publish events. `REVERB_SERVER_*` is where Reverb listens on the server.

Then create the app key, the OAuth signing keys and the database, and cache the configuration:

```bash
cd /var/www/akord/current
sudo -u www-data php artisan key:generate --force
sudo -u www-data php artisan passport:keys
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan optimize
```

### Step 5: Create the virtual host

Create `/etc/apache2/sites-available/akord.conf` (for example with `sudo nano`):

```apache
<VirtualHost *:80>
    ServerName akord.example.com
    DocumentRoot /var/www/akord/current/public

    <Directory /var/www/akord/current/public>
        AllowOverride All
        Require all granted
    </Directory>

    # Reverb: browsers open WebSockets on /app/..., and Akord publishes events to /apps/...
    ProxyPass        /app/  http://127.0.0.1:8080/app/ upgrade=websocket
    ProxyPassReverse /app/  http://127.0.0.1:8080/app/
    ProxyPass        /apps/ http://127.0.0.1:8080/apps/
    ProxyPassReverse /apps/ http://127.0.0.1:8080/apps/

    ErrorLog ${APACHE_LOG_DIR}/akord-error.log
    CustomLog ${APACHE_LOG_DIR}/akord-access.log combined
</VirtualHost>
```

The vhost is the same for PHP-FPM and mod_php. Akord's `public/.htaccess` sends every request to Laravel and passes the `Authorization` header on, which the REST API and the MCP server need.

Enable the site, check the configuration, and restart Apache:

```bash
sudo a2ensite akord
sudo a2dissite 000-default
sudo apache2ctl configtest
sudo systemctl restart apache2
```

`configtest` must print `Syntax OK`. A warning before it, `AH00558: Could not reliably determine the server's fully qualified domain name`, is harmless.

### Step 6: Turn on HTTPS

```bash
sudo certbot --apache -d akord.example.com
```

Certbot gets a free certificate from Let's Encrypt and adds `akord-le-ssl.conf`, a copy of the vhost on port 443. It also redirects HTTP to HTTPS, and it renews the certificate automatically.

### Step 7: Run Reverb and the queue worker

Create `/etc/systemd/system/akord-reverb.service`:

```ini
[Unit]
Description=Akord Reverb (live updates)
After=network.target

[Service]
User=www-data
Group=www-data
WorkingDirectory=/var/www/akord/current
ExecStart=/usr/bin/php /var/www/akord/current/artisan reverb:start
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
```

Create `/etc/systemd/system/akord-queue.service`:

```ini
[Unit]
Description=Akord queue worker (notifications, live updates)
After=network.target

[Service]
User=www-data
Group=www-data
WorkingDirectory=/var/www/akord/current
ExecStart=/usr/bin/php /var/www/akord/current/artisan queue:work --sleep=3 --tries=3 --max-time=3600
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
```

Start both now and at every boot:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now akord-reverb akord-queue
```

### Step 8: Check it and create your account

1. Check Akord responds. This should print `200`:
   ```bash
   curl -s -o /dev/null -w '%{http_code}\n' https://akord.example.com/up
   ```
2. Check both services are `active (running)`: `systemctl status akord-reverb akord-queue --no-pager`.
3. Open `https://akord.example.com/register` and create an account. The password needs at least 12 characters, with upper and lower case letters, a number and a symbol. It must also not appear in a known data breach.
4. Click the link in the verification email. With `MAIL_MAILER=log`, get the link from the log:
   ```bash
   sudo grep -ohE 'https://akord.example.com/email/verify/[^ "<>)]+' /var/www/akord/shared/storage/logs/laravel-*.log | tail -n 1 | sed 's/&amp;/\&/g'
   ```
5. To check live updates, open the same contract in two browsers. A comment added in one appears in the other without reloading.

Logs: Akord writes to `/var/www/akord/shared/storage/logs/laravel-<date>.log`, and Apache to `/var/log/apache2/akord-*.log`. Reverb and the queue worker log to `journalctl -u akord-reverb` and `journalctl -u akord-queue`.

## Updating on an Ubuntu server

The new release is prepared next to the running one. Akord then shows a maintenance page for the short time it takes to back up the database, migrate it and switch over. Run these steps in one shell, because later steps use `$VERSION` and `$OLD`.

### Step 1: Unpack the new release

```bash
VERSION=v1.3.0
cd /tmp
curl -fLO https://github.com/PatFox/Akord/releases/download/$VERSION/akord-$VERSION.zip
curl -fLO https://github.com/PatFox/Akord/releases/download/$VERSION/akord-$VERSION.zip.sha256
sha256sum -c akord-$VERSION.zip.sha256

sudo unzip -q /tmp/akord-$VERSION.zip -d /var/www/akord/releases/$VERSION
cd /var/www/akord
sudo rm -rf releases/$VERSION/storage
sudo ln -s /var/www/akord/shared/storage releases/$VERSION/storage
sudo ln -s /var/www/akord/shared/.env releases/$VERSION/.env
sudo chown -R www-data:www-data releases/$VERSION
sudo -u www-data php releases/$VERSION/artisan optimize
```

Akord keeps running during this step. If `releases/$VERSION` already exists (for example after an attempt that stopped halfway), `unzip` asks whether to overwrite files. In that case delete the folder with `sudo rm -rf /var/www/akord/releases/$VERSION` and run the step again.

### Step 2: Stop Akord and back up the database

```bash
cd /var/www/akord
OLD=$(cat current/VERSION)
sudo -u www-data php current/artisan down
sudo systemctl stop akord-queue akord-reverb
sudo -u www-data cp shared/database/akord.sqlite backups/akord-$OLD-$(date +%Y%m%d-%H%M%S).sqlite
```

### Step 3: Migrate and switch

```bash
sudo -u www-data php releases/$VERSION/artisan migrate --force
sudo ln -sfn /var/www/akord/releases/$VERSION current
```

Then reload PHP. With mod_php this is required: until Apache reloads, it keeps running the old release's code. With PHP-FPM, the reload clears PHP's caches of the old paths. Run the line for your option from install step 2:

```bash
sudo systemctl reload php8.3-fpm   # option A, PHP-FPM
sudo systemctl reload apache2      # option B, mod_php
```

Start everything again:

```bash
sudo systemctl start akord-reverb akord-queue
sudo -u www-data php current/artisan up
```

### Step 4: Check it

`cat /var/www/akord/current/VERSION` shows the new version, and `curl -s -o /dev/null -w '%{http_code}\n' https://akord.example.com/up` prints `200`.

### If the migration fails

If step 3's `migrate` fails, `current` still points at the old release. Put the backup back and start the old release again:

```bash
cd /var/www/akord
ls backups                        # the newest file is the one from step 2
sudo -u www-data cp backups/akord-v1.2.0-20261117-104955.sqlite shared/database/akord.sqlite
sudo systemctl start akord-reverb akord-queue
sudo -u www-data php current/artisan up
```

### Going back to an earlier version

> **Note:** this returns the database to its state before that update, so anything entered since then is lost.

To return to the version you had before an update, put back the backup taken during that update and point `current` at the old release:

```bash
cd /var/www/akord
sudo -u www-data php current/artisan down
sudo systemctl stop akord-queue akord-reverb
sudo -u www-data cp backups/akord-v1.2.0-20261117-104955.sqlite shared/database/akord.sqlite
sudo ln -sfn /var/www/akord/releases/v1.2.0 current
sudo systemctl reload php8.3-fpm   # or: sudo systemctl reload apache2 (mod_php)
sudo systemctl start akord-reverb akord-queue
sudo -u www-data php current/artisan up
```

### Removing old releases

Old releases stay in `/var/www/akord/releases`. Delete the ones you no longer need with `sudo rm -rf /var/www/akord/releases/v1.1.0`. Never delete the folder `current` points at. `rm -rf` deletes a release's `storage` and `.env` links, not the shared files they point to.
