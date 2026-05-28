@echo off
REM ============================================================================
REM  cron-scan-purchase-inbox.cmd — auto-import prijatych faktur (PDF / ISDOC)
REM  Frekvence: kazdych 5-15 minut (dodavatele posilaji PDF prubezne)
REM  Skenuje cfg.purchase_invoice.inbox_dir, podporuje PDF, ISDOC, XML.
REM
REM  Workflow per soubor:
REM    1. SHA-256 dedup vuci purchase_invoices.pdf_hash
REM    2. Embedded ISDOC v PDF -> ISDOC parser (priorita, zdarma)
REM    3. PDF bez ISDOC + tenant ma AI nakonfigurovanou -> AI extract
REM    4. Jinak skip
REM
REM  Task Scheduler:
REM    schtasks /create /tn "MyInvoice ScanPurchaseInbox" ^
REM      /tr "%~f0" /sc minute /mo 10 /ru SYSTEM
REM
REM  Vystup: tee-style — log + konzole (interaktivni run vidi pokrok live).
REM  PHP output buffering vypnuty (-d output_buffering=0), aby echo letel hned.
REM  Exit code se propaguje z PHP ($LASTEXITCODE) — Task Scheduler monitoring.
REM ============================================================================
setlocal
set "SCRIPT_DIR=%~dp0"
set "PROJECT_ROOT=%SCRIPT_DIR%.."
if defined MYINVOICE_DATA_DIR (set "LOG_DIR=%MYINVOICE_DATA_DIR%\log\cron") else (set "LOG_DIR=%PROJECT_ROOT%\log\cron")
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"
for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd"') do set "TODAY=%%i"
set "LOG_FILE=%LOG_DIR%\scan-purchase-inbox-%TODAY%.log"
powershell -NoProfile -Command "& php -d output_buffering=0 -d implicit_flush=1 '%PROJECT_ROOT%\api\bin\cron-scan-purchase-inbox.php' %* 2>&1 | Tee-Object -FilePath '%LOG_FILE%' -Append; exit $LASTEXITCODE"
exit /b %ERRORLEVEL%
