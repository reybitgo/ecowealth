@echo off
setlocal

rem ==============================================================
rem  EcoWealth - General Harvest arm (Windows launcher)
rem
rem  Runs the same job as `php bin/general_harvest_arm.php`:
rem
rem    general_harvest_arm.cmd --dry-run    print the baseline, write nothing
rem    general_harvest_arm.cmd              record the baseline
rem
rem  This is a ONE-TIME, ON-DEMAND job, not a scheduled task: it records
rem  the go-live baseline once, after the ladder is configured and after
rem  Phase 20's queue is seeded, and BEFORE the stream is switched on
rem  (docs/runbook.md > "General Harvest go-live order, part 1").
rem
rem  Start in:  C:\laragon\www\ecowealth\system
rem
rem  PHP must be on PATH, or set it for the machine/user:
rem    setx PHP_EXE "C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe"
rem
rem  The exit code is the job's: 0 success (or a clean dry run), 1 refused
rem  (already armed, stream on, unseeded, invalid ladder, a queue hole),
rem  2 environment (database down, missing tables).
rem
rem  It moves no money: every row it writes is `baseline` with no wallet
rem  reference, and a second arm is refused (the baseline is irreversible).
rem ==============================================================

set "APP_ROOT=%~dp0.."

if not defined PHP_EXE set "PHP_EXE=php"

"%PHP_EXE%" "%APP_ROOT%\bin\general_harvest_arm.php" %*

endlocal

exit /b %ERRORLEVEL%
