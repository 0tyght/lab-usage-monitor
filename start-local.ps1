$ErrorActionPreference = 'Stop'

$projectDirectory = Split-Path -Parent $MyInvocation.MyCommand.Path
$phpExecutable = 'C:\xampp\php\php.exe'

if (-not (Test-Path -LiteralPath $phpExecutable)) {
    throw "ไม่พบ PHP ของ XAMPP ที่ $phpExecutable"
}

& $phpExecutable (Join-Path $projectDirectory 'scripts\init.php')
Write-Host 'LUMS: http://127.0.0.1:8085' -ForegroundColor Cyan
& $phpExecutable -S 127.0.0.1:8085 -t $projectDirectory
