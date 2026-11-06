@echo off
setlocal

rem ==============================================================
rem  EcoWealth - order auto-complete job (Windows launcher)
rem
rem  Runs the same job as `php bin/order_autocomplete.php`:
rem
rem    order_autocomplete.cmd                 normal run
rem    order_autocomplete.cmd --dry-run       preview only
rem    order_autocomplete.cmd --doctor        environment / settings report
rem    order_autocomplete.cmd --limit=50      smaller batch
rem
rem  Task Scheduler action (hourly is plenty; the job is idempotent):
rem    Program:   C:\laragon\www\ecowealth\system\bin\order_autocomplete.cmd
rem    Arguments: --limit=200
rem    Start in:  C:\laragon\www\ecowealth\system
rem
rem  PHP must be on PATH, or set it for the machine/user:
rem    setx PHP_EXE "C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe"
rem ==============================================================

set "APP_ROOT=%~dp0.."

if not defined PHP_EXE set "PHP_EXE=php"

"%PHP_EXE%" "%APP_ROOT%\bin\order_autocomplete.php" %*

endlocal
