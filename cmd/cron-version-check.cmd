@echo off
REM ============================================================================
REM  cron-version-check.cmd — denni kontrola dostupnosti nove verze
REM  Frekvence: 1x denne (kdykoliv, nesnese vic nez 1x za 6h kvuli GitHub
REM  rate limitu pro anonymni volani = 60 req/h/IP).
REM
REM  Vola GitHub Releases API a cachuje tag + release notes do tabulky
REM  `app_meta`. UI footer + Systém -> Aktualizace pak cte z cache.
REM
REM  Task Scheduler:
REM    schtasks /create /tn "MyInvoice Version Check" ^
REM      /tr "%~f0" /sc daily /st 06:00 /ru SYSTEM
REM ============================================================================
setlocal
set "SCRIPT_DIR=%~dp0"
set "PROJECT_ROOT=%SCRIPT_DIR%.."
if defined MYINVOICE_DATA_DIR (set "LOG_DIR=%MYINVOICE_DATA_DIR%\log\cron") else (set "LOG_DIR=%PROJECT_ROOT%\log\cron")
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"
for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd"') do set "TODAY=%%i"
php "%PROJECT_ROOT%\api\bin\cron-version-check.php" %* >> "%LOG_DIR%\version-check-%TODAY%.log" 2>&1
exit /b %ERRORLEVEL%
