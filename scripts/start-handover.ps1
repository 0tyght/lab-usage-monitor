$ErrorActionPreference = 'Stop'
Set-Location (Split-Path -Parent $PSScriptRoot)

try {
    & (Join-Path $PSScriptRoot 'create-desktop-shortcut.ps1') -InstallDir (Get-Location).Path -Quiet
} catch {
    Write-Warning "Could not prepare the desktop shortcut. You can still use start-online.ps1 directly. $($_.Exception.Message)"
}

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'Docker Desktop is required. Install it, then restart Windows and try again.'
}
if (-not (Get-Command git -ErrorAction SilentlyContinue)) {
    throw 'Git for Windows is required. Install it, then try again.'
}
if (-not (Get-Command node -ErrorAction SilentlyContinue)) {
    throw 'Node.js 22 or newer is required. Install it, then try again.'
}
& docker info --format '{{.ServerVersion}}' 2>$null | Out-Null
if ($LASTEXITCODE -ne 0) {
    Write-Host 'Starting Docker Desktop...'
    & docker desktop start --timeout 120
    if ($LASTEXITCODE -ne 0) { throw 'Docker Desktop did not start. Open it and check its status.' }
}
& (Join-Path (Split-Path -Parent $PSScriptRoot) 'start-online.ps1')
if ($LASTEXITCODE -ne 0) { throw 'LUMS did not start. Check the message above.' }
Start-Process 'https://0tyght.github.io/lab-usage-monitor/'
