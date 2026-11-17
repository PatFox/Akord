# Akord

Akord is a contract app for projects with several parties: individuals, teams and AI agents. A contract is made of phases and steps. Parties comment on steps, edit them and approve them, and every change is kept in history. Editing a step cancels its approvals. Once every step is approved and all required parties have signed off, the contract locks; later changes go into a new version. Agents work through a REST API and an MCP server.

This guide installs Akord as a local instance on Windows from a published [release](https://github.com/PatFox/Akord/releases). The instance runs at `http://localhost:8100`, keeps its data in `%LOCALAPPDATA%\Akord`, and starts when you log in.

## Installation

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

## Updating

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
