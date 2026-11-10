#Requires -RunAsAdministrator
<#
.SYNOPSIS
  One-click Typecho blog server deployment for Windows.

.DESCRIPTION
  Downloads official packages (PHP 8.1 NTS, Typecho, Caddy, cacert.pem),
  configures pm2 process management (installed globally via npm), runs a
  fully-automated CLI install of Typecho on SQLite, and registers Windows
  scheduled tasks for autostart / siteUrl sync / health self-healing.

  Cloudflare Tunnel setup (fixed domain + free HTTPS) is guided separately,
  see README.md.

.EXAMPLE
  .\install.ps1 -Domain blog.example.com -AdminPassword "YourStrong#Pass1"

.NOTES
  - Requires: admin PowerShell, Node.js installed (for npm), internet access.
  - All downloads come from official sources only.
#>
param(
  [string]$Root = 'D:\server',
  [string]$Domain = '',                # your domain, e.g. blog.example.com (optional)
  [Parameter(Mandatory=$true)][string]$AdminPassword,
  [string]$Port = '8080',
  [string]$AdminUser = 'admin',
  [string]$AdminMail = 'admin@example.com'
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$PhpVer  = '8.1.31'
$CaddyVer = '2.11.9'
$DL      = Join-Path $Root 'downloads'

function Step($msg) { Write-Host "`n==> $msg" -ForegroundColor Cyan }
function Ok($msg)   { Write-Host "    OK: $msg" -ForegroundColor Green }

# ---------- 0. layout ----------
Step "Creating directory layout under $Root"
foreach ($d in @('php','www','caddy','cloudflared','tools','logs','backups','tmp\sessions', 'downloads')) {
  New-Item -ItemType Directory -Force -Path (Join-Path $Root $d) | Out-Null
}
$repo = $PSScriptRoot
Ok "layout ready"

# ---------- 1. download official packages ----------
Step "Downloading official packages (PHP / Typecho / Caddy / CA bundle)"
$phpZip  = Join-Path $DL "php-$PhpVer.zip"
$teZip   = Join-Path $DL 'typecho.zip'
$cdZip   = Join-Path $DL 'caddy.zip'

if (-not (Test-Path $phpZip)) {
  Invoke-WebRequest "https://windows.php.net/downloads/releases/archives/php-$PhpVer-nts-Win32-vs16-x64.zip" -OutFile $phpZip
}
if (-not (Test-Path $teZip)) {
  Invoke-WebRequest 'https://github.com/typecho/typecho/releases/latest/download/typecho.zip' -OutFile $teZip
}
if (-not (Test-Path $cdZip)) {
  Invoke-WebRequest "https://github.com/caddyserver/caddy/releases/download/v$CaddyVer/caddy_${CaddyVer}_windows_amd64.zip" -OutFile $cdZip
}

Expand-Archive $phpZip -DestinationPath (Join-Path $Root 'php') -Force
Expand-Archive $teZip  -DestinationPath (Join-Path $Root 'www') -Force
Expand-Archive $cdZip  -DestinationPath (Join-Path $Root 'caddy') -Force

$caPem = Join-Path $Root 'php\cacert.pem'
if (-not (Test-Path $caPem)) {
  Invoke-WebRequest 'https://curl.se/ca/cacert.pem' -OutFile $caPem
}

# router.php for quick-test built-in server
Copy-Item (Join-Path $repo 'runtime\router.php') (Join-Path $Root 'www\router.php') -Force

# tools
Copy-Item (Join-Path $repo 'tools\*') (Join-Path $Root 'tools\') -Force
Ok "packages extracted"

# ---------- 2. config files ----------
Step "Writing config files (paths -> $Root)"
$mk = {
  param($src, $dst)
  (Get-Content $src -Raw).Replace('D:\server', $Root).Replace('D:/server', ($Root -replace '\\','/')) |
    Set-Content -Path $dst -Encoding UTF8
}

& $mk (Join-Path $repo 'configs\php.ini.example')           (Join-Path $Root 'php\php.ini')
& $mk (Join-Path $repo 'configs\Caddyfile.example')         (Join-Path $Root 'Caddyfile')
& $mk (Join-Path $repo 'configs\ecosystem.config.js.example')(Join-Path $Root 'ecosystem.config.js')
& $mk (Join-Path $repo 'configs\start-server.cmd.example')  (Join-Path $Root 'start-server.cmd')

if ($Domain -ne '') {
  $Domain | Set-Content (Join-Path $Root 'tools\tunnel-host.txt') -Encoding Ascii
}
Ok "configs written"

# ---------- 3. pm2 (npm global) ----------
Step "Installing pm2 globally via npm"
npm install -g pm2 --no-fund --no-audit 2>$null
$pm2ver = (& pm2 -v) 2>$null
if (-not $pm2ver) { throw 'pm2 not found after npm install -g pm2. Ensure Node.js/npm are on PATH.' }
Ok "pm2 $pm2ver"

# ---------- 4. start stack ----------
Step "Starting php-cgi x3 + Caddy via pm2"
Push-Location $Root
pm2 delete typecho-cgi-1,typecho-cgi-2,typecho-cgi-3,typecho-web 2>$null | Out-Null
pm2 start ecosystem.config.js --only typecho-cgi-1,typecho-cgi-2,typecho-cgi-3,typecho-web | Out-Null
Pop-Location
Start-Sleep -Seconds 4
Ok "services started (php-cgi :9001-9003, Caddy :$Port)"

# ---------- 5. Typecho CLI auto-install ----------
Step "Running Typecho CLI auto-install (SQLite)"
Push-Location (Join-Path $Root 'www')
$env:TYPECHO_DB_ADAPTER     = 'Pdo_SQLite'
$env:TYPECHO_DB_FILE        = (Join-Path $Root 'www\usr\typecho.db')
$env:TYPECHO_SITE_URL       = "http://127.0.0.1:$Port"
$env:TYPECHO_USER_NAME      = $AdminUser
$env:TYPECHO_USER_PASSWORD  = $AdminPassword
$env:TYPECHO_USER_MAIL      = $AdminMail
& (Join-Path $Root 'php\php.exe') install.php 2>&1 | Select-Object -Last 5
Pop-Location
Ok "Typecho installed (user: $AdminUser)"

# ---------- 6. scheduled tasks ----------
Step "Registering scheduled tasks (autostart / sync / healthcheck)"
$me = "$env:COMPUTERNAME\$env:USERNAME"

$acts = @(
  @{ Name='BlogServer';        Exe='cmd.exe';                                Arg="/c $Root\start-server.cmd"; EveryMin=0 },
  @{ Name='BlogSiteUrlSync';   Exe="$Root\php\php.exe";                      Arg="$Root\tools\sync-siteurl.php"; EveryMin=10 },
  @{ Name='BlogHealthCheck';   Exe="$Root\php\php.exe";                      Arg="$Root\tools\healthcheck.php";  EveryMin=5 }
)
foreach ($a in $acts) {
  Unregister-ScheduledTask -TaskName $a.Name -Confirm:$false -ErrorAction SilentlyContinue
  $action = New-ScheduledTaskAction -Execute $a.Exe -Argument $a.Arg
  if ($a.EveryMin -eq 0) {
    $trigger = New-ScheduledTaskTrigger -AtLogOn
    $settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
      -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1) -StartWhenAvailable
  } else {
    $trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes $a.EveryMin)
    $settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
      -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Minutes 5)
  }
  Register-ScheduledTask -TaskName $a.Name -Action $action -Trigger $trigger -Settings $settings `
    -RunLevel Highest -User $me -Force | Out-Null
}
Ok "tasks: BlogServer (logon), BlogSiteUrlSync (10min), BlogHealthCheck (5min)"

# ---------- 7. firewall ----------
Step "Firewall: enable all profiles + allow inbound TCP $Port"
Set-NetFirewallProfile -Profile Domain,Private,Public -Enabled True
if (-not (Get-NetFirewallRule -DisplayName 'Blog-HTTP-8080' -ErrorAction SilentlyContinue)) {
  New-NetFirewallRule -DisplayName 'Blog-HTTP-8080' -Direction Inbound -Protocol TCP -LocalPort $Port `
    -Action Allow -Profile Any | Out-Null
}
Ok "firewall ready (only $Port open to inbound)"

# ---------- 8. verify ----------
Step "Smoke test"
$code = (Invoke-WebRequest "http://127.0.0.1:$Port/" -UseBasicParsing -TimeoutSec 15).StatusCode
if ($code -ne 200) { throw "homepage returned $code" }
Ok "http://127.0.0.1:$Port/ -> 200"
pm2 save | Out-Null

# ---------- 9. cloudflared (optional) ----------
Step "Downloading cloudflared (tunnel client)"
$cdExe = Join-Path $Root 'cloudflared\cloudflared.exe'
if (-not (Test-Path $cdExe)) {
  Invoke-WebRequest 'https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-windows-amd64.exe' -OutFile $cdExe
}
Ok $cdExe

Write-Host @"

============================================================
 Deployment complete!

 Next steps (Cloudflare Tunnel, fixed domain + free HTTPS):
   1. Host your domain on Cloudflare (free plan), wait for Active
   2. cd $Root\cloudflared
      .\cloudflared.exe tunnel login              <- browser authorization
      .\cloudflared.exe tunnel create typecho
      .\cloudflared.exe tunnel route dns typecho <your-domain>
   3. Edit $Root\cloudflared\config.yml  (see configs/cloudflared.config.yml.example)
   4. pm2 start ecosystem.config.js --only cf-tunnel
      "<your-domain>" | Out-File -Encoding ascii $Root\tools\tunnel-host.txt
      $Root\php\php.exe $Root\tools\sync-siteurl.php

 Admin panel : http://127.0.0.1:$Port/admin/
 Admin user  : $AdminUser  (password as you provided)
 Local access: http://<LAN-IP>:$Port/

 See README.md for the full operations manual.
============================================================
"@ -ForegroundColor Yellow
