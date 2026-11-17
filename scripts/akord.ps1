<#
.SYNOPSIS
    Installs, updates and runs a local Akord instance on Windows.

.DESCRIPTION
    install    Create an instance in -Root from a GitHub release (or -Package), migrate it, start it,
               and start it at logon (unless -NoAutostart).
    update     Switch to another release (default: the latest). It is prepared beside the running one;
               then Akord stops, the database is backed up and migrated, and the new release starts.
               If that fails, the database backup and the previous release are restored.
    start, stop, restart, status
    uninstall  Stop the instance and remove its logon shortcut. Files stay in -Root.
    run        Internal: the supervisor that keeps the web server, Reverb and the queue worker running.

    Layout of -Root (default %LOCALAPPDATA%\Akord):
      akord.ps1                     this script; each release brings its own copy
      shared\.env                   instance settings; run "restart" after editing
      shared\storage\               logs, OAuth keys, compiled views; linked into every release
      shared\database\akord.sqlite
      releases\<version>\           unpacked releases; current\ points at the active one
      backups\                      database copies taken before each update migrates

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File .\akord.ps1 install

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File $env:LOCALAPPDATA\Akord\akord.ps1 update
#>
[CmdletBinding()]
param(
    [Parameter(Position = 0, Mandatory = $true)]
    [ValidateSet('install', 'update', 'start', 'stop', 'restart', 'status', 'uninstall', 'run')]
    [string] $Command,

    # Release tag to install or update to, e.g. v1.2.0.
    [string] $Version = 'latest',

    # A local release zip instead of a GitHub download (checked against <zip>.sha256 when present).
    [string] $Package,

    [string] $Root,

    # Ports for a new install. Afterwards they live in shared\.env (APP_URL, REVERB_PORT, REVERB_SERVER_PORT).
    [int] $Port = 8100,
    [int] $ReverbPort = 8101,

    [string] $Repo = 'PatFox/Akord',
    [switch] $NoAutostart,

    # Reinstall the version that is already active.
    [switch] $Force
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

if (-not $Root) {
    if (Test-Path -LiteralPath (Join-Path $PSScriptRoot 'shared\.env')) { $Root = $PSScriptRoot }
    else { $Root = Join-Path $env:LOCALAPPDATA 'Akord' }
}
$Root = [IO.Path]::GetFullPath($Root).TrimEnd('\')

$Paths = @{
    Script    = Join-Path $Root 'akord.ps1'
    Env       = Join-Path $Root 'shared\.env'
    Storage   = Join-Path $Root 'shared\storage'
    Database  = Join-Path $Root 'shared\database\akord.sqlite'
    Releases  = Join-Path $Root 'releases'
    Current   = Join-Path $Root 'current'
    Backups   = Join-Path $Root 'backups'
    Downloads = Join-Path $Root 'downloads'
    Pid       = Join-Path $Root 'run\supervisor.pid'
}
$Shortcut = Join-Path ([Environment]::GetFolderPath('Startup')) 'Akord.lnk'
$KeepReleases = 3
$KeepBackups = 10
$Utf8 = New-Object Text.UTF8Encoding $false

function Write-Step([string] $Message) { Write-Host "==> $Message" -ForegroundColor Cyan }

function Get-Php {
    $command = Get-Command php.exe -ErrorAction SilentlyContinue
    if (-not $command) { throw 'php.exe is not on PATH. Install PHP 8.3 or later first.' }
    return $command.Source
}

function Assert-Php {
    $php = Get-Php
    # No double quotes inside: Windows PowerShell does not escape them for native commands.
    $check = "echo PHP_VERSION_ID < 80300 ? 'PHP-8.3+ ' : ''; foreach (['ctype','curl','dom','fileinfo','mbstring','openssl','pdo_sqlite','sodium','tokenizer'] as `$e) { if (!extension_loaded(`$e)) echo `$e, ' '; }"
    $missing = & $php -r $check
    if ($LASTEXITCODE -ne 0) { throw "$php failed to run." }
    if ("$missing".Trim()) { throw "$php is missing: $("$missing".Trim())" }
    # Without a CA bundle every outgoing HTTPS call fails: the breach check on new passwords
    # (production only; it then lets the password through) and TLS mail.
    $ca = & $php -r "echo ini_get('curl.cainfo') ?: ini_get('openssl.cafile');"
    if (-not "$ca".Trim()) {
        Write-Warning "$php has no CA bundle, so Akord's HTTPS calls (password breach check, TLS mail) will fail. Download https://curl.se/ca/cacert.pem and set curl.cainfo and openssl.cafile to it in php.ini."
    }
}

function Read-EnvFile([string] $Path) {
    $values = @{}
    foreach ($line in [IO.File]::ReadAllLines($Path)) {
        if ($line -match '^\s*([A-Za-z0-9_]+)\s*=\s*(.*)$') { $values[$Matches[1]] = $Matches[2].Trim().Trim('"') }
    }
    return $values
}

function Set-EnvValues([string] $Path, [Collections.IDictionary] $Values) {
    $lines = New-Object Collections.Generic.List[string]
    $lines.AddRange([string[]] [IO.File]::ReadAllLines($Path))
    foreach ($key in $Values.Keys) {
        $line = "$key=$($Values[$key])"
        $index = $lines.FindIndex({ param($l) $l -match "^$key=" })
        if ($index -ge 0) { $lines[$index] = $line } else { $lines.Add($line) }
    }
    [IO.File]::WriteAllLines($Path, $lines, $Utf8)   # no BOM: phpdotenv would read it as part of the first key
}

# Shells that load a project's .env (omp, `artisan dev`) export its keys. Real environment variables
# override .env and config:cache bakes them in, so drop every key Akord reads before running anything.
function Clear-InheritedEnv([string[]] $Files) {
    foreach ($file in $Files) {
        if (-not (Test-Path -LiteralPath $file)) { continue }
        foreach ($key in (Read-EnvFile $file).Keys) { Remove-Item -LiteralPath "Env:$key" -ErrorAction SilentlyContinue }
    }
}

function New-RandomHex([int] $Bytes) {
    $buffer = New-Object byte[] $Bytes
    [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($buffer)
    return -join ($buffer | ForEach-Object { $_.ToString('x2') })
}

function Get-ActiveRelease {
    $item = Get-Item -LiteralPath $Paths.Current -Force -ErrorAction SilentlyContinue
    if (-not $item) { return $null }
    return ([string] @($item.Target)[0]).TrimEnd('\')
}

function Get-ReleaseVersion([string] $Release) {
    return (Get-Content -LiteralPath (Join-Path $Release 'VERSION') -Raw).Trim()
}

function Set-Junction([string] $Path, [string] $Target) {
    $item = Get-Item -LiteralPath $Path -Force -ErrorAction SilentlyContinue
    if ($item) {
        if ($item.LinkType -ne 'Junction') { throw "$Path exists and is not a junction." }
        [IO.Directory]::Delete($Path)   # removes the link only, never its target
    }
    New-Item -ItemType Junction -Path $Path -Value $Target | Out-Null
}

function Remove-Release([string] $Release) {
    # Unlink the shared storage first, so the recursive delete can never reach it.
    $storage = Get-Item -LiteralPath (Join-Path $Release 'storage') -Force -ErrorAction SilentlyContinue
    if ($storage -and $storage.LinkType -eq 'Junction') { [IO.Directory]::Delete($storage.FullName) }
    Remove-Item -LiteralPath $Release -Recurse -Force
}

function Invoke-Artisan([string] $Release, [string[]] $Arguments) {
    & (Get-Php) (Join-Path $Release 'artisan') @Arguments --no-interaction
    if ($LASTEXITCODE -ne 0) { throw "php artisan $($Arguments -join ' ') failed (exit $LASTEXITCODE)." }
}

function Get-GitHubRelease([string] $Tag) {
    $uri = "https://api.github.com/repos/$Repo/releases/latest"
    if ($Tag -ne 'latest') { $uri = "https://api.github.com/repos/$Repo/releases/tags/$Tag" }
    try { return Invoke-RestMethod -UseBasicParsing -Uri $uri -Headers @{ 'User-Agent' = 'akord-installer' } }
    catch { throw "Cannot find release '$Tag' of $Repo ($uri): $($_.Exception.Message)" }
}

function Assert-Checksum([string] $Zip, [string] $SumFile) {
    $expected = ((Get-Content -LiteralPath $SumFile -Raw).Trim() -split '\s+')[0]
    $actual = (Get-FileHash -LiteralPath $Zip -Algorithm SHA256).Hash
    if ($expected -ne $actual) { throw "Checksum mismatch for $Zip." }   # -ne ignores case
}

# Unpacks a release (from -Package, or the given GitHub release) into releases\ and returns its folder.
function Expand-Release($GitHubRelease) {
    New-Item -ItemType Directory -Force -Path $Paths.Releases, $Paths.Downloads | Out-Null

    if ($Package) {
        $zip = (Resolve-Path -LiteralPath $Package).Path
        if (Test-Path -LiteralPath "$zip.sha256") { Assert-Checksum $zip "$zip.sha256" }
        else { Write-Warning "No $zip.sha256 beside the package; not checking it." }
    } else {
        $asset = $GitHubRelease.assets | Where-Object { $_.name -like 'akord-v*.zip' } | Select-Object -First 1
        $sum = $null
        if ($asset) { $sum = $GitHubRelease.assets | Where-Object { $_.name -eq "$($asset.name).sha256" } | Select-Object -First 1 }
        if (-not $sum) { throw "Release $($GitHubRelease.tag_name) has no akord-*.zip with a .sha256 file." }
        $zip = Join-Path $Paths.Downloads $asset.name
        Write-Step "Downloading $($asset.name)"
        Invoke-WebRequest -UseBasicParsing -Uri $asset.browser_download_url -OutFile $zip
        Invoke-WebRequest -UseBasicParsing -Uri $sum.browser_download_url -OutFile "$zip.sha256"
        Assert-Checksum $zip "$zip.sha256"
    }

    Write-Step "Unpacking $(Split-Path $zip -Leaf)"
    $staging = Join-Path $Paths.Releases ('.incoming-' + (New-RandomHex 4))
    New-Item -ItemType Directory -Path $staging | Out-Null
    try {
        & (Join-Path $env:SystemRoot 'System32\tar.exe') -xf $zip -C $staging
        if ($LASTEXITCODE -ne 0) { throw "Could not unpack $zip." }
        if (-not (Test-Path -LiteralPath (Join-Path $staging 'VERSION'))) { throw "$zip is not an Akord release (no VERSION file)." }
        $target = Join-Path $Paths.Releases (Get-ReleaseVersion $staging)
        if (Test-Path -LiteralPath $target) { $target = "$target-$(Get-Date -Format yyyyMMddHHmmss)" }
        Rename-Item -LiteralPath $staging -NewName (Split-Path $target -Leaf)
        return $target
    } catch {
        Remove-Item -LiteralPath $staging -Recurse -Force -ErrorAction SilentlyContinue
        throw
    }
}

# Links the shared storage into a release, gives it the instance .env, and caches config, routes, events and views.
function Initialize-Release([string] $Release) {
    $storage = Join-Path $Release 'storage'
    if ((Get-Item -LiteralPath $storage -Force).LinkType -ne 'Junction') {
        # A release may add storage folders; create them in the shared copy.
        Get-ChildItem -LiteralPath $storage -Recurse -Directory | ForEach-Object {
            New-Item -ItemType Directory -Force -Path ($Paths.Storage + $_.FullName.Substring($storage.Length)) | Out-Null
        }
        Remove-Item -LiteralPath $storage -Recurse -Force
        New-Item -ItemType Junction -Path $storage -Value $Paths.Storage | Out-Null
    }
    Copy-Item -LiteralPath $Paths.Env -Destination (Join-Path $Release '.env') -Force
    Invoke-Artisan $Release @('optimize')
}

function Get-Ports {
    $values = Read-EnvFile $Paths.Env
    return @{ Web = ([Uri] $values['APP_URL']).Port; Reverb = [int] $values['REVERB_SERVER_PORT'] }
}

function Test-PortListening([int] $Number) {
    return [bool] (Get-NetTCPConnection -State Listen -LocalPort $Number -ErrorAction SilentlyContinue)
}

function Get-Supervisor {
    if (-not (Test-Path -LiteralPath $Paths.Pid)) { return $null }
    $id = [int] (Get-Content -LiteralPath $Paths.Pid -Raw).Trim()
    $process = Get-CimInstance Win32_Process -Filter "ProcessId = $id"
    if ($process -and $process.CommandLine -like "*akord.ps1*run*$Root*") { return $id }
    return $null
}

function Test-Healthy {
    $ports = Get-Ports
    if (-not (Test-PortListening $ports.Reverb)) { return $false }
    try { return (Invoke-WebRequest -UseBasicParsing -Uri "http://127.0.0.1:$($ports.Web)/up" -TimeoutSec 5).StatusCode -eq 200 }
    catch { return $false }
}

function Start-Instance {
    if (Get-Supervisor) { Write-Host 'Akord is already running.'; return }
    $release = Get-ActiveRelease
    if (-not $release) { throw "Akord is not installed in $Root. Run install first." }

    $ports = Get-Ports
    foreach ($number in $ports.Web, $ports.Reverb) {
        if (Test-PortListening $number) { throw "Port $number is already in use. Free it, or change APP_URL / REVERB_PORT / REVERB_SERVER_PORT in $($Paths.Env)." }
    }

    Write-Step "Preparing $(Get-ReleaseVersion $release)"
    Initialize-Release $release

    Write-Step 'Starting the web server, Reverb and the queue worker'
    $arguments = '-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "{0}" run -Root "{1}"' -f $Paths.Script, $Root
    Start-Process -FilePath (Join-Path $PSHOME 'powershell.exe') -ArgumentList $arguments -WindowStyle Hidden

    $deadline = (Get-Date).AddSeconds(60)
    while (-not (Test-Healthy)) {
        if ((Get-Date) -gt $deadline) {
            Stop-Instance -Quiet
            throw "Akord did not come up on ports $($ports.Web) and $($ports.Reverb). Logs: $(Join-Path $Paths.Storage 'logs')"
        }
        Start-Sleep -Seconds 1
    }
    Write-Host "Akord $(Get-ReleaseVersion $release) is running at $((Read-EnvFile $Paths.Env)['APP_URL'])" -ForegroundColor Green
}

function Stop-Instance([switch] $Quiet) {
    $id = Get-Supervisor
    if ($id) {
        Write-Step 'Stopping'
        & taskkill.exe /PID $id /T /F | Out-Null   # the supervisor and every process it started
    } elseif (-not $Quiet) {
        Write-Host 'Akord is not running.'
    }
    Remove-Item -LiteralPath $Paths.Pid -ErrorAction SilentlyContinue

    if ($id -and (Test-Path -LiteralPath $Paths.Env)) {
        $ports = Get-Ports
        $deadline = (Get-Date).AddSeconds(15)
        while ((Test-PortListening $ports.Web) -or (Test-PortListening $ports.Reverb)) {
            if ((Get-Date) -gt $deadline) { throw "Akord stopped, but ports $($ports.Web) / $($ports.Reverb) are still in use." }
            Start-Sleep -Milliseconds 500
        }
    }
}

# Runs hidden. Keeps the three processes up, restarting any that exits (with backoff if it keeps failing).
function Invoke-Supervisor {
    $release = Get-ActiveRelease
    $php = Get-Php
    $ports = Get-Ports
    $logs = Join-Path $Paths.Storage 'logs'
    New-Item -ItemType Directory -Force -Path (Split-Path $Paths.Pid), $logs | Out-Null
    [IO.File]::WriteAllText($Paths.Pid, "$PID")

    $artisan = Join-Path $release 'artisan'
    $server = Join-Path $release 'vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php'
    $children = @(
        @{ Name = 'web'; Directory = (Join-Path $release 'public'); Arguments = "-S 127.0.0.1:$($ports.Web) `"$server`"" },
        @{ Name = 'reverb'; Directory = $release; Arguments = "`"$artisan`" reverb:start" },
        @{ Name = 'queue'; Directory = $release; Arguments = "`"$artisan`" queue:work --sleep=3 --tries=3 --max-time=3600" }
    )
    foreach ($child in $children) {
        $child.Process = $null
        $child.Started = [DateTime]::MinValue
        $child.NextStart = [DateTime]::MinValue
        $child.Delay = 1
        $child.Log = Join-Path $logs "$($child.Name).log"
    }
    $journal = Join-Path $logs 'supervisor.log'

    while ($true) {
        foreach ($child in $children) {
            $process = $child.Process
            if ($process -and -not $process.HasExited) { continue }

            if ($process) {
                $ran = [int] ((Get-Date) - $child.Started).TotalSeconds
                if ($ran -ge 60) { $child.Delay = 1 } else { $child.Delay = [Math]::Min($child.Delay * 2, 60) }
                $child.NextStart = (Get-Date).AddSeconds($child.Delay)
                $child.Process = $null
                Add-Content -LiteralPath $journal -Value "$(Get-Date -Format s) $($child.Name) exited with code $($process.ExitCode) after ${ran}s; restarting in $($child.Delay)s"
            }
            if ((Get-Date) -lt $child.NextStart) { continue }

            # cmd appends the output to the child's log; killing the supervisor's tree stops both.
            $line = '/d /s /c ""{0}" {1} >> "{2}" 2>&1"' -f $php, $child.Arguments, $child.Log
            $child.Process = Start-Process -FilePath cmd.exe -ArgumentList $line -WorkingDirectory $child.Directory -WindowStyle Hidden -PassThru
            $null = $child.Process.Handle   # keeps ExitCode readable after exit
            $child.Started = Get-Date
            Add-Content -LiteralPath $journal -Value "$(Get-Date -Format s) $($child.Name) started (release $(Get-ReleaseVersion $release))"
        }
        Start-Sleep -Seconds 2
    }
}

function Register-Autostart {
    $link = (New-Object -ComObject WScript.Shell).CreateShortcut($Shortcut)
    $link.TargetPath = Join-Path $PSHOME 'powershell.exe'
    $link.Arguments = '-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "{0}" start' -f $Paths.Script
    $link.WorkingDirectory = $Root
    $link.WindowStyle = 7
    $link.Save()
}

function Test-Autostart {
    if (-not (Test-Path -LiteralPath $Shortcut)) { return $false }
    return (New-Object -ComObject WScript.Shell).CreateShortcut($Shortcut).Arguments -like "*$($Paths.Script)*"
}

function Remove-OldFiles {
    $active = Get-ActiveRelease
    Get-ChildItem -LiteralPath $Paths.Releases -Directory |
        Where-Object { $_.FullName -ne $active } |
        Sort-Object CreationTime -Descending |
        Select-Object -Skip ($KeepReleases - 1) |
        ForEach-Object { Remove-Release $_.FullName }
    Get-ChildItem -LiteralPath $Paths.Backups -File |
        Sort-Object CreationTime -Descending |
        Select-Object -Skip $KeepBackups |
        Remove-Item
    Get-ChildItem -LiteralPath $Paths.Downloads -File -ErrorAction SilentlyContinue | Remove-Item
}

function Install-Instance {
    if (Test-Path -LiteralPath $Paths.Env) { throw "Akord is already installed in $Root. Use update." }
    Assert-Php
    foreach ($number in $Port, $ReverbPort) {
        if (Test-PortListening $number) { throw "Port $number is in use. Choose others with -Port and -ReverbPort." }
    }

    New-Item -ItemType Directory -Force -Path $Root, $Paths.Storage, (Split-Path $Paths.Database), $Paths.Backups | Out-Null
    $github = $null
    if (-not $Package) { $github = Get-GitHubRelease $Version }
    $release = Expand-Release $github
    Clear-InheritedEnv @(Join-Path $release '.env.example')

    Write-Step "Writing $($Paths.Env)"
    Copy-Item -LiteralPath (Join-Path $release '.env.example') -Destination $Paths.Env
    $appKey = New-Object byte[] 32
    [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($appKey)
    Set-EnvValues $Paths.Env ([ordered] @{
        APP_ENV            = 'production'
        APP_DEBUG          = 'false'
        APP_KEY            = 'base64:' + [Convert]::ToBase64String($appKey)
        APP_URL            = "http://localhost:$Port"
        LOG_STACK          = 'daily'
        DB_DATABASE        = '"' + ($Paths.Database -replace '\\', '/') + '"'
        # Browsers share cookies across ports, so each instance on localhost needs its own session cookie.
        SESSION_COOKIE     = "akord_${Port}_session"
        MAIL_MAILER        = 'log'
        REVERB_APP_ID      = [string] (Get-Random -Minimum 100000 -Maximum 999999)
        REVERB_APP_KEY     = New-RandomHex 10
        REVERB_APP_SECRET  = New-RandomHex 20
        REVERB_HOST        = 'localhost'
        REVERB_PORT        = $ReverbPort
        REVERB_SERVER_HOST = '127.0.0.1'
        REVERB_SERVER_PORT = $ReverbPort
        REVERB_SCHEME      = 'http'
    })
    New-Item -ItemType File -Path $Paths.Database | Out-Null

    Initialize-Release $release
    Write-Step 'Creating OAuth keys and the database'
    Invoke-Artisan $release @('passport:keys')
    Invoke-Artisan $release @('migrate', '--force')
    Set-Junction $Paths.Current $release
    Copy-Item -LiteralPath (Join-Path $release 'scripts\akord.ps1') -Destination $Paths.Script -Force
    if (-not $NoAutostart) { Register-Autostart }

    Start-Instance
    $url = (Read-EnvFile $Paths.Env)['APP_URL']
    Write-Host @"

Next:
  - Register at $url/register. Mail is written to the log (MAIL_MAILER=log), so the
    verification link is in $(Join-Path $Paths.Storage 'logs')\laravel-<date>.log.
    For real mail, set MAIL_* in $($Paths.Env) and run restart.
  - Manage it with: powershell -ExecutionPolicy Bypass -File "$($Paths.Script)" status|stop|start|restart|update
"@
}

function Update-Instance {
    $old = Get-ActiveRelease
    if (-not $old) { throw "Akord is not installed in $Root. Run install first." }
    Assert-Php
    $oldVersion = Get-ReleaseVersion $old

    $github = $null
    if (-not $Package) {
        $github = Get-GitHubRelease $Version
        if ($github.tag_name -eq $oldVersion -and -not $Force) { Write-Host "Already on $oldVersion."; return }
    }
    $new = Expand-Release $github
    $newVersion = Get-ReleaseVersion $new
    if ($newVersion -eq $oldVersion -and -not $Force) {
        Remove-Release $new
        Write-Host "Already on $oldVersion."
        return
    }

    Clear-InheritedEnv @(Join-Path $new '.env.example')
    Write-Step "Preparing $newVersion beside the running $oldVersion"
    Initialize-Release $new

    Stop-Instance -Quiet
    $backup = Join-Path $Paths.Backups ('akord-{0}-{1}.sqlite' -f $oldVersion, (Get-Date -Format yyyyMMdd-HHmmss))
    Write-Step "Backing up the database to $backup"
    Copy-Item -LiteralPath $Paths.Database -Destination $backup

    try {
        Write-Step 'Migrating'
        Invoke-Artisan $new @('migrate', '--force')
        Set-Junction $Paths.Current $new
        Start-Instance
    } catch {
        $failure = $_.Exception.Message
        Write-Warning "Update to $newVersion failed: $failure"
        Write-Step "Restoring $oldVersion and its database"
        Stop-Instance -Quiet
        Copy-Item -LiteralPath $backup -Destination $Paths.Database -Force
        Set-Junction $Paths.Current $old
        Start-Instance
        throw "Update to $newVersion failed and was rolled back to $oldVersion. Cause: $failure"
    }

    Copy-Item -LiteralPath (Join-Path $new 'scripts\akord.ps1') -Destination $Paths.Script -Force
    Remove-OldFiles
    Write-Host "Updated $oldVersion -> $newVersion" -ForegroundColor Green
}

function Show-Status {
    $release = Get-ActiveRelease
    if (-not $release) { Write-Host "Akord is not installed in $Root."; return }
    $id = Get-Supervisor
    $state = 'stopped'
    if ($id -and (Test-Healthy)) { $state = "running (supervisor pid $id)" }
    elseif ($id) { $state = "starting or unhealthy (supervisor pid $id); see supervisor.log" }

    Write-Host "Version:   $(Get-ReleaseVersion $release)"
    Write-Host "State:     $state"
    Write-Host "URL:       $((Read-EnvFile $Paths.Env)['APP_URL'])"
    Write-Host "Root:      $Root"
    Write-Host "Settings:  $($Paths.Env)"
    Write-Host "Logs:      $(Join-Path $Paths.Storage 'logs')"
    Write-Host "Autostart: $(if (Test-Autostart) { 'at logon' } else { 'off' })"
}

function Uninstall-Instance {
    Stop-Instance -Quiet
    if (Test-Autostart) { Remove-Item -LiteralPath $Shortcut }
    Write-Host "Akord is stopped and no longer starts at logon. Its files are still in $Root; delete that folder to remove them."
}

Clear-InheritedEnv @($Paths.Env, (Join-Path $Paths.Current '.env.example'))

switch ($Command) {
    'install' { Install-Instance }
    'update' { Update-Instance }
    'start' { Start-Instance }
    'stop' { Stop-Instance }
    'restart' { Stop-Instance -Quiet; Start-Instance }
    'status' { Show-Status }
    'uninstall' { Uninstall-Instance }
    'run' { Invoke-Supervisor }
}
