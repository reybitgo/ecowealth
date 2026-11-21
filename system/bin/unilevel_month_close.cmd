@echo off
setlocal

rem ==============================================================
rem  EcoWealth - unilevel month close (Windows launcher)
rem
rem  Runs the same job as `php bin/unilevel_month_close.php`:
rem
rem    unilevel_month_close.cmd                normal run (catch-up)
rem    unilevel_month_close.cmd --dry-run      preview only
rem    unilevel_month_close.cmd --doctor       environment / settings report
rem    unilevel_month_close.cmd --period=2026-10
rem
rem  Task Scheduler action (00:15 on the first of every month):
rem    Program:   C:\laragon\www\ecowealth\system\bin\unilevel_month_close.cmd
rem    Arguments: (none)
rem    Start in:  C:\laragon\www\ecowealth\system
rem
rem  PHP must be on PATH, or set it for the machine/user:
rem    setx PHP_EXE "C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe"
rem ==============================================================

set "APP_ROOT=%~dp0.."

if not defined PHP_EXE set "PHP_EXE=php"

"%PHP_EXE%" "%APP_ROOT%\bin\unilevel_month_close.php" %*

endlocal
