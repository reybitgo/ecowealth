@echo off
setlocal

rem ==============================================================
rem  EcoWealth - Royalty month close (Windows launcher)
rem
rem  Runs the same job as `php bin/royalty_month_close.php`:
rem
rem    royalty_month_close.cmd                     normal run (catch-up)
rem    royalty_month_close.cmd --dry-run           preview only
rem    royalty_month_close.cmd --doctor            environment / settings report
rem    royalty_month_close.cmd --period=2026-10
rem    royalty_month_close.cmd --pay-only --period=2026-10
rem    royalty_month_close.cmd --max-batches=20
rem
rem  Task Scheduler action (00:30 on the first of every month):
rem    Program:   C:\laragon\www\ecowealth\system\bin\royalty_month_close.cmd
rem    Arguments: --max-batches=20
rem    Start in:  C:\laragon\www\ecowealth\system
rem
rem  PHP must be on PATH, or set it for the machine/user:
rem    setx PHP_EXE "C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe"
rem
rem  The exit code is the job's: 0 success/no-op/busy, 1 processing or
rem  validation error, 2 environment (database down, missing tables).
rem ==============================================================

set "APP_ROOT=%~dp0.."

if not defined PHP_EXE set "PHP_EXE=php"

"%PHP_EXE%" "%APP_ROOT%\bin\royalty_month_close.php" %*

endlocal

exit /b %ERRORLEVEL%
