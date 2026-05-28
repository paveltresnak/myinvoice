@echo off
REM ============================================================================
REM  cron-backup.cmd — denni DB backup (mariadb-dump → ZIP)
REM  Frekvence: 1x denne, doporuceno 02:00 (PRED cron-cleanup)
REM  Retention: 30 dennich + 12 mesicnich (1. v mesici se zachova deze)
REM
REM  Vyzaduje v PATH: mariadb-dump (nebo mysqldump).
REM
REM  Task Scheduler:
REM    schtasks /create /tn "MyInvoice Backup" ^
REM      /tr "%~f0" /sc daily /st 02:00 /ru SYSTEM
REM ============================================================================
setlocal
set "SCRIPT_DIR=%~dp0"
set "PROJECT_ROOT=%SCRIPT_DIR%.."
if defined MYINVOICE_DATA_DIR (set "LOG_DIR=%MYINVOICE_DATA_DIR%\log\cron") else (set "LOG_DIR=%PROJECT_ROOT%\log\cron")
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"
for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd"') do set "TODAY=%%i"
php "%PROJECT_ROOT%\api\bin\cron-backup.php" %* >> "%LOG_DIR%\backup-%TODAY%.log" 2>&1
exit /b %ERRORLEVEL%
