@echo off
setlocal

rem ==============================================================
rem  EcoWealth - repeat-purchase release job (Windows launcher)
rem
rem  Runs the same job as `php bin/unilevel_release.php`:
rem
rem    unilevel_release.cmd                 normal run
rem    unilevel_release.cmd --dry-run       preview only
rem    unilevel_release.cmd --doctor        environment / settings report
rem    unilevel_release.cmd --order=123     try one order
rem
rem  Task Scheduler action (the window is day-granular; hourly is plenty):
rem    Program:   C:\laragon\www\ecowealth\system\bin\unilevel_release.cmd
rem    Arguments: --limit=200
rem    Start in:  C:\laragon\www\ecowealth\system
rem
rem  PHP must be on PATH, or set it for the machine/user:
rem    setx PHP_EXE "C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe"
rem ==============================================================

set "APP_ROOT=%~dp0.."

if not defined PHP_EXE set "PHP_EXE=php"

"%PHP_EXE%" "%APP_ROOT%\bin\unilevel_release.php" %*

endlocal
