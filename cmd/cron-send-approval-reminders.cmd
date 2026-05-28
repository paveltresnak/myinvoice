@echo off
REM ============================================================================
REM  cron-send-approval-reminders.cmd — upominky zakaznikum, kteri neschvalili
REM  vykaz vicepraci (faktura visi ve stavu approval_status='requested').
REM  Frekvence: 1x denne, doporuceno 09:00 v pracovni dny (Po-Pa)
REM
REM  Posila stejnou sablonu invoice_approval s flagem reminder zakaznikum,
REM  jejichz schvalovaci e-mail je vice nez --days=N dni stary (default z
REM  cfg.approval.reminder_after_days = 5) a kteri jeste neprekrocili
REM  cfg.approval.max_reminders (default 3).
REM
REM  Volitelne argumenty (predaj jako parametry .cmd):
REM    --days=N    override reminder_after_days
REM    --dry-run   jen vypise, co by se odeslalo
REM
REM  Task Scheduler (kazdy pracovni den 09:00):
REM    schtasks /create /tn "MyInvoice Approval Reminders" ^
REM      /tr "%~f0" /sc daily /st 09:00 /d MON,TUE,WED,THU,FRI /ru SYSTEM
REM ============================================================================
setlocal
set "SCRIPT_DIR=%~dp0"
set "PROJECT_ROOT=%SCRIPT_DIR%.."
if defined MYINVOICE_DATA_DIR (set "LOG_DIR=%MYINVOICE_DATA_DIR%\log\cron") else (set "LOG_DIR=%PROJECT_ROOT%\log\cron")
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"
for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd"') do set "TODAY=%%i"
php "%PROJECT_ROOT%\api\bin\cron-send-approval-reminders.php" %* >> "%LOG_DIR%\send-approval-reminders-%TODAY%.log" 2>&1
exit /b %ERRORLEVEL%
