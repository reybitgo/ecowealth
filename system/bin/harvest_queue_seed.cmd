@echo off
setlocal

rem ==============================================================
rem  EcoWealth - Team Harvest queue seed (Windows launcher)
rem
rem  Runs the same job as `php bin/harvest_queue_seed.php`:
rem
rem    harvest_queue_seed.cmd                     reconstruct the queue
rem    harvest_queue_seed.cmd --dry-run           preview only
rem    harvest_queue_seed.cmd --by-id             order by member id
rem    harvest_queue_seed.cmd --force             append only the missing
rem    harvest_queue_seed.cmd --rebuild           recount / report drift
rem    harvest_queue_seed.cmd --rebuild --apply   repair the counters
rem
rem  This is a ONE-TIME go-live job, not a scheduled task. Run it in this
rem  order (docs/runbook.md > "Team Harvest go-live order"):
rem
rem    1. deploy the code
rem    2. harvest_queue_seed.cmd --dry-run
rem    3. harvest_queue_seed.cmd
rem    4. turn the bonus on in /admin/settings > Commissions
rem
rem  Start in:  C:\laragon\www\ecowealth\system
rem
rem  PHP must be on PATH, or set it for the machine/user:
rem    setx PHP_EXE "C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe"
rem
rem  The exit code is the job's: 0 success/no-op, 1 refused or drift
rem  found, 2 environment (database down, missing tables).
rem ==============================================================

set "APP_ROOT=%~dp0.."

if not defined PHP_EXE set "PHP_EXE=php"

"%PHP_EXE%" "%APP_ROOT%\bin\harvest_queue_seed.php" %*

endlocal

exit /b %ERRORLEVEL%
