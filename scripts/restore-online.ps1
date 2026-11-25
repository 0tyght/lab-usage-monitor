param(
    [string]$BackupFile = '',
    [ValidatePattern('^[a-z0-9][a-z0-9_-]*$')][string]$ProjectName = 'lums-online'
)
$ErrorActionPreference = 'Stop'
Set-Location (Split-Path -Parent $PSScriptRoot)
if (-not $BackupFile) {
    Add-Type -AssemblyName System.Windows.Forms
    $dialog = New-Object System.Windows.Forms.OpenFileDialog
    $dialog.Title = 'Select the LUMS SQLite backup'
    $dialog.Filter = 'SQLite backup (*.sqlite)|*.sqlite'
    if ($dialog.ShowDialog() -ne [System.Windows.Forms.DialogResult]::OK) { Write-Host 'No backup selected.'; exit 0 }
    $BackupFile = $dialog.FileName
}
if (-not (Test-Path -LiteralPath $BackupFile -PathType Leaf)) { throw 'The selected backup does not exist.' }
if (-not (Test-Path -LiteralPath '.env.online')) { throw 'Copy the original private .env.online to this folder first.' }
$running = & docker ps -q --filter "label=com.docker.compose.project=$ProjectName"
if ($LASTEXITCODE -ne 0) { throw 'Docker is not ready.' }
if ($running) { throw 'Stop LUMS online before restoring. Existing data is not changed.' }
& docker compose -p $ProjectName -f compose.online.yaml build app
if ($LASTEXITCODE -ne 0) { throw 'Could not build LUMS.' }
$helper = 'lums-restore-' + [guid]::NewGuid().ToString('N').Substring(0,12)
try {
    $volume = "${ProjectName}_online_storage"
    & docker create --name $helper --mount "type=volume,source=$volume,target=/var/www/html/storage" --entrypoint sleep lums-app:online 300 | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Could not prepare restore container.' }
    & docker start $helper | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Could not start restore container.' }
    & docker exec $helper sh -c 'test ! -e /var/www/html/storage/lums.sqlite && test ! -e /var/www/html/storage/lums.sqlite-wal && test ! -e /var/www/html/storage/lums.sqlite-shm'
    if ($LASTEXITCODE -ne 0) { throw 'The destination already contains a database. No data was changed.' }
    & docker cp $BackupFile "${helper}:/tmp/lums-restore.sqlite"
    if ($LASTEXITCODE -ne 0) { throw 'Could not copy backup into restore container.' }
    & docker exec $helper php /var/www/html/scripts/validate-backup.php /tmp/lums-restore.sqlite
    if ($LASTEXITCODE -ne 0) { throw 'The selected database failed integrity verification.' }
    & docker exec $helper sh -c 'set -C; cat /tmp/lums-restore.sqlite > /var/www/html/storage/lums.sqlite && chown www-data:www-data /var/www/html/storage/lums.sqlite'
    if ($LASTEXITCODE -ne 0) { throw 'Could not restore. Check the destination before retrying.' }
    Write-Host 'Backup restored. Start LUMS online using the launcher.'
} finally {
    & docker rm -f $helper 2>$null | Out-Null
}
