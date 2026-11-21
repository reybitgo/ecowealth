@echo off
setlocal

rem ==============================================================
rem  EcoWealth - shop order expiry job (Windows launcher)
rem
rem  Runs the same job as `php bin/shop_expire.php`:
rem
rem    shop_expire.cmd                 normal run
rem    shop_expire.cmd --dry-run       preview only
rem    shop_expire.cmd --doctor        environment / settings report
rem    shop_expire.cmd --limit=50      smaller batch
rem
rem  Task Scheduler action:
rem    Program:   C:\laragon\www\ecowealth\system\bin\shop_expire.cmd
rem    Arguments: --limit=200
rem    Start in:  C:\laragon\www\ecowealth\system
rem
rem  PHP must be on PATH, or set it for the machine/user:
rem    setx PHP_EXE "C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe"
rem ==============================================================

set "APP_ROOT=%~dp0.."

if not defined PHP_EXE set "PHP_EXE=php"

"%PHP_EXE%" "%APP_ROOT%\bin\shop_expire.php" %*

endlocal
