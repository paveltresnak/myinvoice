@echo off
REM ============================================================================
REM  cron-generate-recurring-invoices.cmd — automaticke generovani pravidelnych faktur
REM  Frekvence: 1x denne, doporuceno 06:30 (po cron-version-check)
REM
REM  Prochazi sablony pravidelnych faktur (recurring_invoice_templates) kde
REM  status='active' a next_run_date <= dnes a vygeneruje fakturu. Podle
REM  per-sablona flagu auto_issue / auto_send_email rovnou vystavi a/nebo
REM  odesle klientovi e-mailem.
REM
REM  Per-supplier kill-switch: Nastaveni -> Muj dodavatel -> "Generovat
REM  pravidelne fakturace cronem".
REM
REM  Volitelne argumenty:
REM    --dry-run       jen vypise, co by se vygenerovalo
REM
REM  Task Scheduler (kazdy den 06:30):
REM    schtasks /create /tn "MyInvoice Recurring" ^
REM      /tr "%~f0" /sc daily /st 06:30 /ru SYSTEM
REM ============================================================================
setlocal
set "SCRIPT_DIR=%~dp0"
set "PROJECT_ROOT=%SCRIPT_DIR%.."
if defined MYINVOICE_DATA_DIR (set "LOG_DIR=%MYINVOICE_DATA_DIR%\log\cron") else (set "LOG_DIR=%PROJECT_ROOT%\log\cron")
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"
for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd"') do set "TODAY=%%i"
php "%PROJECT_ROOT%\api\bin\cron-generate-recurring-invoices.php" %* >> "%LOG_DIR%\generate-recurring-%TODAY%.log" 2>&1
exit /b %ERRORLEVEL%
