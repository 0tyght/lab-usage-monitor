$ErrorActionPreference = 'Stop'
try {
    foreach ($program in @('git','docker','node')) {
        if (-not (Get-Command $program -ErrorAction SilentlyContinue)) { throw "Missing $program. Install Git for Windows, Docker Desktop and Node.js 22 or newer, then reopen this installer." }
    }
    $version = & node -p 'process.versions.node.split(".")[0]'
    if ([int]$version -lt 22) { throw 'Node.js 22 or newer is required.' }
    $destination = Join-Path ([Environment]::GetFolderPath('MyDocuments')) 'LUMS'
    if (Test-Path -LiteralPath $destination) { throw "The folder already exists: $destination. Open that folder instead; the installer will not overwrite it." }
    Write-Host "Installing to $destination"
    & git clone https://github.com/0tyght/lab-usage-monitor.git $destination
    if ($LASTEXITCODE -ne 0) { throw 'Download failed. Check the Internet connection and GitHub access.' }
    Write-Host 'Installed. Read the handover guide in the LUMS folder before starting.'
    Start-Process explorer.exe -ArgumentList $destination
} catch {
    Write-Host $_.Exception.Message -ForegroundColor Red
    exit 1
}
