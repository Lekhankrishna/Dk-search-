@echo off
setlocal

set "SCRIPTDIR=%~dp0"
if "%SCRIPTDIR:~-1%"=="\" set "SCRIPTDIR=%SCRIPTDIR:~0,-1%"

echo ============================================
echo  LPG Tool - One-time computer setup
echo ============================================
echo.
echo This registers the "lpgtool://" link handler for YOUR Windows user
echo account only (no administrator rights needed, and it doesn't affect
echo other user accounts on this computer).
echo.
echo After this, clicking "LPG Search" in the CRM will show a one-time
echo browser permission prompt, then automatically start this tool on this
echo computer whenever it isn't already running.
echo.

where py >nul 2>nul
if errorlevel 1 (
  echo [ERROR] Python was not found on this computer.
  echo Please install Python first - see README.txt - then re-run this script.
  echo.
  pause
  exit /b 1
)

reg add "HKCU\Software\Classes\lpgtool" /ve /d "URL:LPG Tool Protocol" /f >nul
reg add "HKCU\Software\Classes\lpgtool" /v "URL Protocol" /d "" /f >nul
REM "%%1" (not "%1") - this script itself takes no argument, so an
REM unescaped %1 here would resolve to this script's OWN empty first
REM parameter and bake a permanent empty string into the registry value
REM instead of a real placeholder. %%1 is the literal, un-expanded text
REM "%1" that Windows substitutes with the actual clicked URI (e.g.
REM "lpgtool://open?target=bulk") every time the protocol is invoked later
REM (found 2026-07-26, added alongside the Chrome app-mode window change,
REM which needs to know which of the two tool pages to open).
REM
REM Target is the .bat file DIRECTLY - NOT "cmd.exe /c "batch" "%%1""
REM (found 2026-07-26 via direct A/B testing on a real computer, after the
REM protocol silently did nothing on every click/Win+R attempt with no
REM error anywhere: when Windows substitutes %%1 into a command that
REM already has ITS OWN quoted "cmd.exe /c "path"" prefix, the result has
REM two separate quoted tokens - and cmd.exe's own /c quote-stripping rule
REM only preserves quotes when there are EXACTLY two quote characters on
REM the line. Four quote characters (two pairs) trips the fallback "strip
REM first char, strip last quote" behavior instead, mangling both the batch
REM path and the URI into one unrecognized token - silently, since the
REM whole failure happens inside cmd.exe's own argument parsing before
REM anything gets a chance to show an error window. Windows already
REM resolves .bat execution on its own via the file association for that
REM extension, so the "cmd.exe /c" wrapper was never actually needed - a
REM direct target avoids the whole quote-counting trap and was confirmed
REM working via a clean isolated protocol registration test.
reg add "HKCU\Software\Classes\lpgtool\shell\open\command" /ve /d "\"%SCRIPTDIR%\lpgtool_launcher.bat\" \"%%1\"" /f >nul

echo Done. You can close this window.
echo.
echo Tip: the packages (flask, selenium) still need to be installed once on
echo this computer too - if you haven't already run setup_and_run.bat here
echo at least once, do that first so the required packages are present.
echo.
pause
