param(
    [string] $InstallDir = (Split-Path -Parent $PSScriptRoot),
    [string] $DesktopPath = [Environment]::GetFolderPath('DesktopDirectory'),
    [switch] $Quiet
)

$ErrorActionPreference = 'Stop'
$launcher = Join-Path $InstallDir 'เปิดระบบออนไลน์.cmd'
if (-not (Test-Path -LiteralPath $launcher -PathType Leaf)) {
    throw "Cannot find the LUMS launcher: $launcher"
}
if ([string]::IsNullOrWhiteSpace($DesktopPath)) {
    throw 'Windows did not provide a Desktop folder for the shortcut.'
}

New-Item -ItemType Directory -Path $DesktopPath -Force | Out-Null
$shortcutPath = Join-Path $DesktopPath 'LUMS - เปิดระบบ.lnk'
$commandPrompt = Join-Path $env:SystemRoot 'System32\cmd.exe'
$shell = New-Object -ComObject WScript.Shell
$shortcut = $shell.CreateShortcut($shortcutPath)
$shortcut.TargetPath = $commandPrompt
$shortcut.Arguments = '/c ""{0}""' -f $launcher
$shortcut.WorkingDirectory = $InstallDir
$shortcut.Description = 'เริ่มระบบ LUMS และเปิดเว็บไซต์'
$shortcut.Save()

if (-not $Quiet) {
    Write-Host "Desktop shortcut ready: $shortcutPath" -ForegroundColor Green
}
