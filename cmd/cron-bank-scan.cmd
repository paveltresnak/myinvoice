@echo off
REM ============================================================================
REM  cron-bank-scan.cmd — auto-import GPC vypisu z banky (FIO)
REM  Frekvence: kazdych 15-30 minut (FIO export pravidelne dorazi)
REM  Skenuje cfg.bank_import.scan_root + podadresare YYYY-MM/, hleda *.gpc/*.txt.
REM  SHA256 dedupe — soubor co uz byl naimportovany se preskoci.
REM
REM  Task Scheduler:
REM    schtasks /create /tn "MyInvoice BankScan" ^
REM      /tr "%~f0" /sc minute /mo 30 /ru SYSTEM
REM ============================================================================
setlocal
set "SCRIPT_DIR=%~dp0"
set "PROJECT_ROOT=%SCRIPT_DIR%.."
if defined MYINVOICE_DATA_DIR (set "LOG_DIR=%MYINVOICE_DATA_DIR%\log\cron") else (set "LOG_DIR=%PROJECT_ROOT%\log\cron")
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"
for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd"') do set "TODAY=%%i"
php "%PROJECT_ROOT%\api\bin\cron-bank-scan.php" %* >> "%LOG_DIR%\bank-scan-%TODAY%.log" 2>&1
exit /b %ERRORLEVEL%
