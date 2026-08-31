$ErrorActionPreference = 'Stop'
if (-not (Get-Command node -ErrorAction SilentlyContinue)) {
    throw 'Node.js 22 or newer is required. Install Node.js and reopen PowerShell.'
}
& node (Join-Path $PSScriptRoot 'scripts\online.mjs') start
if ($LASTEXITCODE -ne 0) { throw 'LUMS could not start. See the message above; no data was deleted.' }
