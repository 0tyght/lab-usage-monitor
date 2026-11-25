param(
    [ValidatePattern('^[a-z0-9][a-z0-9_-]*$')][string]$ProjectName = 'lums-online',
    [string]$BackupDirectory = ''
)
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $projectRoot
if (-not $BackupDirectory) { $BackupDirectory = Join-Path $projectRoot 'storage\handover-backups' }
New-Item -ItemType Directory -Path $backupDirectory -Force | Out-Null

$containerId = & docker compose -p $ProjectName -f compose.online.yaml ps -q app
if ($LASTEXITCODE -ne 0 -or -not $containerId) { throw 'Start LUMS online before making a backup.' }
$backupPath = & docker exec $containerId php /var/www/html/scripts/backup-online.php
if ($LASTEXITCODE -ne 0 -or $backupPath -notmatch '^/var/www/html/storage/backups/handover-[a-zA-Z0-9-]+\.sqlite$') {
    throw 'Database backup failed. The current database has not been changed.'
}
$destination = Join-Path $backupDirectory ([IO.Path]::GetFileName($backupPath))
& docker cp "${containerId}:${backupPath}" $destination
if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $destination)) { throw 'Could not copy the backup to Windows.' }
Write-Host "Database backup saved to: $destination"
Write-Host 'This file contains user records. Store it securely.'
Write-Host 'For a machine transfer, also keep .env.online privately with the backup.'
