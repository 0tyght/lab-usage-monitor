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
    $shortcutScript = Join-Path $destination 'scripts\create-desktop-shortcut.ps1'
    & powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File $shortcutScript -InstallDir $destination
    if ($LASTEXITCODE -ne 0) {
        Write-Warning "The desktop shortcut could not be created. You can open the launcher in '$destination' directly."
    }
    Write-Host "Installed. Double-click 'LUMS - เปิดระบบ' on the Desktop to start the server and open the site."
    Write-Host "Handover guide: $(Join-Path $destination 'คู่มือส่งมอบ.md')"
} catch {
    Write-Host $_.Exception.Message -ForegroundColor Red
    exit 1
}
