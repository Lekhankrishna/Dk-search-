@echo off
REM Registered as the handler for the "lpgtool://" link protocol (see
REM install_lpgtool_protocol.bat) - .bat instead of the earlier .vbs version,
REM since antivirus products commonly quarantine a downloaded .vbs file that
REM later gets executed via wscript.exe (a classic malware delivery pattern,
REM even though this one is legitimate). A .bat launched via cmd.exe /c is
REM generally far less aggressively flagged (found 2026-07-21: an agent's
REM "lpgtool://" link started failing with "cannot find script file" after
REM working initially - Defender had quarantined the .vbs sometime after
REM install). Trade-off: a console window flashes briefly instead of being
REM fully invisible, since Windows always shows a window for a .bat run this
REM way, however short-lived.
setlocal enabledelayedexpansion
cd /d "%~dp0"

REM %1 is the full URI the browser passed when the lpgtool:// link was
REM clicked, e.g. "lpgtool://open?target=bulk" - parsed below to know which
REM page to actually open. Defaults to the single-search page if nothing
REM usable was passed (a plain "lpgtool://open" link, or this script run
REM directly with no argument at all).
set "TARGET=single"
set "URI=%~1"
if not "!URI!"=="" (
  echo !URI! | findstr /C:"target=bulk" >nul
  if not errorlevel 1 set "TARGET=bulk"
)

if "!TARGET!"=="bulk" (
  set "TOOLPAGE=http://127.0.0.1:9196/bulk"
) else (
  set "TOOLPAGE=http://127.0.0.1:9196/"
)

REM Check whether the tool server is already reachable BEFORE starting a new
REM one. Two real problems used to come from skipping this check (found
REM 2026-07-25):
REM   1. Two independent instances could each win a race to bind port 9196,
REM      leaving the CRM randomly talking to whichever one happened to
REM      answer a given request - hours were lost debugging what looked
REM      like flaky connectivity but was actually two servers coexisting.
REM   2. The log redirection below (">") truncates lpgtool.log to empty on
REM      EVERY run, including a redundant relaunch attempt that was just
REM      going to fail on "port already in use" anyway - so a perfectly
REM      healthy, already-running instance's log kept getting wiped for no
REM      reason, which is exactly why it looked empty during troubleshooting.
REM A raw TCP connect (not an HTTP request) is enough to answer "is anything
REM listening" and is fast/dependency-free either way.
powershell -NoProfile -Command "try { $c = New-Object System.Net.Sockets.TcpClient; $c.Connect('127.0.0.1', 9196); $c.Close(); exit 0 } catch { exit 1 }" >nul 2>nul
if not errorlevel 1 goto :server_ready

REM pythonw (not py/python) - pythonw.exe has no console subsystem at all,
REM so no window is ever created for it. Output is redirected to log files
REM instead, so troubleshooting is still possible without one.
REM
REM NOT "start "" pythonw app.py > log 2>&1" (found 2026-07-26 via direct
REM testing on a real computer): that combination of cmd.exe's own "start"
REM plus "> file" redirection intermittently launched a pythonw.exe process
REM that never actually finished starting Flask or binding the port at all
REM - it just sat there as a dead process, with nothing in the log to
REM explain why, and every later connection attempt failed. The exact same
REM launch via PowerShell's Start-Process with -RedirectStandardOutput /
REM -RedirectStandardError instead worked reliably every time.
powershell -NoProfile -Command "Start-Process -FilePath 'pythonw' -ArgumentList 'app.py' -WorkingDirectory '%~dp0' -RedirectStandardOutput '%~dp0lpgtool.log' -RedirectStandardError '%~dp0lpgtool_error.log'"

REM A fixed 3-second wait here used to be enough for a Flask dev server to
REM start listening, but not always (found 2026-07-26: on a slower
REM computer, or the very first launch after a fresh install, Chrome's
REM app-mode window could open and hit "connection refused" before the
REM server had actually finished starting - a fixed wait can't adapt to
REM that). Polling the port directly, up to 15 times / ~15s worst case,
REM proceeds the moment it's actually ready instead of guessing a delay.
REM This loop is deliberately NOT inside any parenthesized ( ... ) block -
REM confirmed by direct testing (2026-07-26) that goto/labels used for
REM looping (backward jumps) inside a block are unreliable in cmd.exe: a
REM "for /l" version silently ran every iteration to completion regardless
REM of an early goto, and a label+goto version nested inside an "if ( ... )"
REM block hit a flat "was unexpected at this time" parse error. Both
REM problems disappear once the loop's labels live at the script's top
REM level instead of inside a block.
set "WAITCOUNT=0"
:wait_for_server
set /a WAITCOUNT+=1
powershell -NoProfile -Command "try { $c = New-Object System.Net.Sockets.TcpClient; $c.Connect('127.0.0.1', 9196); $c.Close(); exit 0 } catch { exit 1 }" >nul 2>nul
if not errorlevel 1 goto :server_ready
if !WAITCOUNT! GEQ 15 goto :server_ready
timeout /t 1 /nobreak >nul
goto :wait_for_server

:server_ready

REM A normal browser tab pointed at a raw local URL always shows that URL in
REM the address bar. Chrome's "app mode" (--app=) instead opens a clean,
REM borderless window with no address bar UI at all (found 2026-07-26, after
REM confirming a page loaded over plain HTTP can't embed this via
REM iframe/fetch either - Chrome blocks that outright as a Private Network
REM Access violation, regardless of any header the local server sends). This
REM sidesteps that entirely: the OS is launching Chrome directly here, not a
REM webpage reaching into a private network via JS - so the CRM tab's own
REM address bar never has to navigate anywhere, and this new window never
REM shows one either.
set "CHROMEEXE="
if exist "%ProgramFiles%\Google\Chrome\Application\chrome.exe" set "CHROMEEXE=%ProgramFiles%\Google\Chrome\Application\chrome.exe"
if exist "%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe" set "CHROMEEXE=%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe"
if exist "%LocalAppData%\Google\Chrome\Application\chrome.exe" set "CHROMEEXE=%LocalAppData%\Google\Chrome\Application\chrome.exe"

if not "!CHROMEEXE!"=="" (
  start "" "!CHROMEEXE!" --app=!TOOLPAGE!
) else (
  REM Chrome genuinely isn't installed anywhere expected - fall back to
  REM whatever the system's default browser is, in a normal tab (URL will
  REM be visible there, but at least something opens instead of nothing).
  start "" !TOOLPAGE!
)
