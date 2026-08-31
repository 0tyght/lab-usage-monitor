$ErrorActionPreference = 'Stop'
& node (Join-Path $PSScriptRoot 'scripts\online.mjs') stop
if ($LASTEXITCODE -ne 0) { throw 'LUMS shutdown or online status publication failed. See the message above.' }
