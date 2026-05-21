@echo off
REM Stáhne XSD schémata EPO MFČR do storage/xsd/ (Windows verze).
REM
REM Pouziti:
REM   cmd\download-xsd.cmd           — stáhne všech 5 schémat
REM   cmd\download-xsd.cmd dphkh1    — stáhne jen jedno

setlocal EnableDelayedExpansion

set "DIR=%~dp0..\storage\xsd"
set "BASE=https://adisspr.mfcr.cz/adis/jepo/schema"

if not exist "%DIR%" mkdir "%DIR%"

if "%~1"=="" (
    set "FORMS=dphdp3 dphkh1 dphshv dpfdp5 dppdp9"
) else (
    set "FORMS=%*"
)

for %%F in (%FORMS%) do (
    echo -^> %%F: %BASE%/%%F_epo2.xsd
    powershell -NoProfile -Command "try { Invoke-WebRequest -Uri '%BASE%/%%F_epo2.xsd' -OutFile '%DIR%\%%F.xsd' -UseBasicParsing; Write-Host '  OK' } catch { Write-Host '  FAIL:' $_.Exception.Message }"
)

echo.
echo Hotovo. Schemata v: %DIR%
endlocal
