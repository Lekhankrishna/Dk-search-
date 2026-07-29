@echo off
REM Runs continuously from the Startup folder (Task Scheduler was permission-
REM blocked when this was set up 2026-07-27, so this loop is the substitute
REM for "restart on crash" - a one-shot launch alone only starts the service
REM once at logon and does nothing if it later dies, which is exactly what
REM happened the first time: the service got killed during testing and
REM stayed down until someone noticed the CRM pages failing).
setlocal enabledelayedexpansion
cd /d "%~dp0"

:loop
REM A real HTTP request, not a bare TCP connect (found 2026-07-28): this
REM environment has repeatedly produced a "zombie" listening socket that
REM still accepts TCP connections and even queues them (visible as growing
REM CLOSE_WAIT entries in netstat) after the actual python process behind
REM it has died with NO process left at all under Get-Process/tasklist/WMI.
REM A TcpClient.Connect() check passes against that zombie socket every
REM time, so the watchdog never noticed the service was actually dead - it
REM just sat there reporting "healthy" while every real request timed out.
REM Hitting a real endpoint and requiring an actual HTTP response (even a
REM 404 counts - it proves something real answered) catches this; only a
REM connection failure/timeout with no response at all counts as down.
powershell -NoProfile -Command "try { Invoke-WebRequest -Uri 'http://127.0.0.1:9197/api/search/healthcheck' -TimeoutSec 5 -UseBasicParsing | Out-Null; exit 0 } catch { if ($_.Exception.Response) { exit 0 } else { exit 1 } }" >nul 2>nul
if errorlevel 1 (
  REM Kill any stray python process first, so a fresh app.py can bind the
  REM port cleanly instead of piling up behind a zombie. Deliberately NOT
  REM killing chrome.exe here (found 2026-07-28, caught before shipping):
  REM this machine is also used interactively (AnyDesk), and chrome.exe is
  REM indistinguishable by image name from anyone's own regular browsing -
  REM a blanket kill would end real browser sessions, not just orphaned
  REM Selenium ones. chromedriver.exe IS safe (Selenium-only, never a
  REM normal browser window) and killing it drops its own child chrome.exe
  REM connection, which chromedriver already cleans up on its way out.
  taskkill /F /IM pythonw.exe >nul 2>nul
  taskkill /F /IM python.exe >nul 2>nul
  taskkill /F /IM chromedriver.exe >nul 2>nul

  REM Start-Process (not cmd's own "start" + redirection) - confirmed
  REM earlier this project that combination could intermittently launch a
  REM pythonw.exe that never actually finished starting Flask or bound the
  REM port, with nothing in a log to explain why.
  powershell -NoProfile -Command "Start-Process -FilePath 'pythonw' -ArgumentList 'app.py' -WorkingDirectory '%~dp0' -RedirectStandardOutput '%~dp0service.log' -RedirectStandardError '%~dp0service_error.log'"
)

timeout /t 15 /nobreak >nul
goto loop
