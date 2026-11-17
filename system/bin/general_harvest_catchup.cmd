@echo off
setlocal

rem ==============================================================
rem  EcoWealth - General Harvest catch-up and doctor (Windows launcher)
rem
rem  Runs the same job as `php bin/general_harvest_catchup.php`:
rem
rem    general_harvest_catchup.cmd                    settle the backlog
rem    general_harvest_catchup.cmd --dry-run          read the liability only
rem    general_harvest_catchup.cmd --member=42        settle one member's node
rem    general_harvest_catchup.cmd --doctor           health report, no writes
rem
rem  This is an ON-DEMAND job, not a scheduled task: General Harvest has no
rem  month and no close (docs/runbook.md > "General Harvest award and
rem  reconcile drill"). Run it after enabling the stream, after a repair
rem  and after any counter-drift alarm.
rem
rem  Start in:  C:\laragon\www\ecowealth\system
rem
rem  PHP must be on PATH, or set it for the machine/user:
rem    setx PHP_EXE "C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe"
rem
rem  The exit code is the job's: 0 clean / nothing to do / busy, 1 refused
rem  or a problem found, 2 environment (database down, missing tables).
rem ==============================================================

set "APP_ROOT=%~dp0.."

if not defined PHP_EXE set "PHP_EXE=php"

"%PHP_EXE%" "%APP_ROOT%\bin\general_harvest_catchup.php" %*

endlocal

exit /b %ERRORLEVEL%
