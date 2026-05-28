@echo off
REM ============================================================================
REM  cron-send-reminders.cmd — automaticke upominky na faktury po splatnosti
REM  Frekvence: 1x denne, doporuceno 09:00 v pracovni dny (Po-Pa)
REM
REM  Posila upominku klientum, jejichz faktura je vice nez --days=N dni
REM  po splatnosti A od posledni upominky uplynulo aspon --cooldown=N dni.
REM  Default: --days=3 --cooldown=7
REM
REM  Volitelne argumenty (predaj jako parametry .cmd):
REM    --days=N        prah dni po splatnosti (default 3)
REM    --cooldown=N    minimum dni od posledni upominky (default 7)
REM    --dry-run       jen vypise, co by se odeslalo
REM
REM  Task Scheduler (kazdy pracovni den 09:00):
REM    schtasks /create /tn "MyInvoice Reminders" ^
REM      /tr "%~f0" /sc daily /st 09:00 /d MON,TUE,WED,THU,FRI /ru SYSTEM
REM ============================================================================
setlocal
set "SCRIPT_DIR=%~dp0"
set "PROJECT_ROOT=%SCRIPT_DIR%.."
if defined MYINVOICE_DATA_DIR (set "LOG_DIR=%MYINVOICE_DATA_DIR%\log\cron") else (set "LOG_DIR=%PROJECT_ROOT%\log\cron")
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"
for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd"') do set "TODAY=%%i"
php "%PROJECT_ROOT%\api\bin\cron-send-reminders.php" %* >> "%LOG_DIR%\send-reminders-%TODAY%.log" 2>&1
exit /b %ERRORLEVEL%
