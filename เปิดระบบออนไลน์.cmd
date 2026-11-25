@echo off
setlocal
cd /d "%~dp0"
powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\start-handover.ps1"
if errorlevel 1 (
  echo.
  echo LUMS could not start. Read the message above.
  pause
)
