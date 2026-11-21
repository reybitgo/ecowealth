@echo off
setlocal

rem ==============================================================
rem  EcoWealth - transfer-limit reset job (Windows launcher)
rem
rem  Runs the same job as `php bin/reset_transfer_limits.php`:
rem
rem    reset_transfer_limits.cmd                 daily reset
rem    reset_transfer_limits.cmd --dry-run       preview only
rem    reset_transfer_limits.cmd --doctor        environment / counters report
rem    reset_transfer_limits.cmd --week          force the weekly reset
rem
rem  Task Scheduler action (run once a day, just after midnight):
rem    Program:   C:\laragon\www\ecowealth\system\bin\reset_transfer_limits.cmd
rem    Arguments: (none - Mondays reset the weekly counters automatically)
rem    Start in:  C:\laragon\www\ecowealth\system
rem
rem  PHP must be on PATH, or set it for the machine/user:
rem    setx PHP_EXE "C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe"
rem ==============================================================

set "APP_ROOT=%~dp0.."

if not defined PHP_EXE set "PHP_EXE=php"

"%PHP_EXE%" "%APP_ROOT%\bin\reset_transfer_limits.php" %*

endlocal
