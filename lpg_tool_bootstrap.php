<?php
require __DIR__ . '/includes/auth.php';
requireLpgSearchAccess();
require_once __DIR__ . '/includes/lpg_crypto.php';

// Generates a single downloadable .bat that does everything needed on a
// fresh computer: download the tool, install its Python packages, register
// the "lpgtool://" auto-launch handler, and start it once immediately — one
// double-click, ever, per computer. A website can never execute a downloaded
// file automatically (hard browser security boundary — see lpg_search.php's
// auto-download trigger for where this gets served), so that one manual
// double-click can't be engineered away; everything after it is automatic.
$scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$rootPath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');

// Which page the installer should open once setup finishes - passed as
// ?target=bulk from lpg_bulk_search.php's download link (lpg_search.php's
// omits it, same as "single" here). Without this, the installer's own
// final auto-launch step (below) had no way to know which page the user
// actually wanted, and always opened the single-search page regardless of
// which one triggered the download in the first place (found 2026-07-26).
$target = ($_GET['target'] ?? '') === 'bulk' ? 'bulk' : 'single';

// The .bat runs as its own standalone PowerShell process with no browser
// session/cookies at all, so lpg_tool_download.php can't gate on being
// logged in the normal way — that silently downloaded the "not logged in"
// error page in place of the real zip the first time (found 2026-07-21: it
// "succeeded" but Expand-Archive then failed with "End of Central Directory
// record could not be found", since what got saved wasn't a zip at all). A
// short-lived signed token (this page itself IS authenticated, being loaded
// by the browser with a real session) stands in for that instead. 40 minutes
// (not the original 15) - found 2026-07-25: 15 minutes only covered the
// download itself, but on a bare computer needing BOTH Python and Chrome
// auto-installed first, those two installs alone could plausibly run past
// 15 minutes combined, expiring the token before the download step the whole
// installer was building up to ever ran. Still short enough that a stale
// downloaded .bat file re-run much later won't work with a dead token.
global $LPG_ENC_KEY;
$expires = time() + 2400;
$token = $expires . '.' . hash_hmac('sha256', 'lpg-dl.' . $expires, $LPG_ENC_KEY);
$downloadUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . $rootPath . '/lpg_tool_download.php?token=' . urlencode($token);

// Ties this specific downloaded copy to the specific agent who downloaded
// it (found 2026-07-26) - the SAME per-agent capability key lpg_autofill.php
// already uses, not a new mechanism. Written into the extracted install
// directory below; the Flask tool re-checks it against lpg_verify_user.php
// before every search, so disabling this account, revoking LPG access,
// letting it expire, or regenerating the key (Admin > Agents) all stop this
// specific installed copy from working - even if it's copied to another
// computer, since the check is a live server call, not a local secret.
$agentKey = lpgEnsureBookmarkletKey($pdo, (int) $_SESSION['user_id']);
$verifyUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . $rootPath . '/lpg_verify_user.php';

// Built as a plain PHP string (not raw template text below a PHP closing
// tag) so line endings can be normalized explicitly below — a batch file saved with
// LF-only endings gets its first couple of characters on every line eaten
// by cmd.exe's parser, which is exactly what broke install_lpgtool_protocol.bat
// the first time it was written (2026-07-21). CRLF is forced right before
// output regardless of how this PHP source file itself is saved.
$bat = <<<BAT
@echo off
REM enabledelayedexpansion (use !VAR! instead of %VAR%) is required for any
REM variable that's both SET and READ within the same parenthesized ( ... )
REM block, like the Python auto-install block below — cmd.exe substitutes
REM %VAR% for a whole block ONCE, at parse time, before any of the block's
REM own lines have run, so a variable first set inside that same block reads
REM back as empty (found 2026-07-21: "%PYINSTALLER%" had already been
REM blanked out this way by the time the installer line ran, so cmd.exe saw
REM a bare "" as the command and failed with "'""' is not recognized").
setlocal enabledelayedexpansion

set "INSTALLDIR=%LOCALAPPDATA%\LPGTool"
set "TOOLDIR=%INSTALLDIR%\lpg_web"
set "ZIPFILE=%TEMP%\lpg_web_install.zip"

echo ============================================
echo  LPG Tool - Automatic Install
echo ============================================
echo.

echo Checking Python...
py -m pip --version >nul 2>nul
if errorlevel 1 (
  REM "where py" alone isn't enough here - it found a python.exe on a real
  REM computer that turned out to be a broken/embeddable install with no pip
  REM at all (found 2026-07-21: "Could not find platform independent
  REM libraries", "No module named pip"), which used to slip past this check
  REM and only fail later, deep into the install. Checking pip directly
  REM catches both "no Python at all" and "a Python that doesn't actually work".
  echo Python isn't installed correctly on this computer - installing it automatically.
  echo This is a one-time step and can take a few minutes, please wait...
  echo.
  set "PYINSTALLER=%TEMP%\python-installer.exe"
  REM "| Out-Null" on the Invoke-WebRequest call - without it, the
  REM WebResponseObject it returns gets auto-echoed to the console by
  REM PowerShell's -Command host (StatusCode/Headers/RawContent and all),
  REM which looks like noisy, alarming output even on a successful download.
  powershell -NoProfile -Command "try { Invoke-WebRequest -Uri 'https://www.python.org/ftp/python/3.14.6/python-3.14.6-amd64.exe' -OutFile '!PYINSTALLER!' -UseBasicParsing | Out-Null } catch { Write-Host \$_.Exception.Message; exit 1 }"
  if errorlevel 1 (
    echo [ERROR] Could not download Python. Check your internet connection and try again.
    pause
    exit /b 1
  )

  echo Installing Python - this window may sit quietly for a minute or two, that's normal...
  REM InstallAllUsers=0 is a per-user install - no administrator rights
  REM needed, matching everything else this installer does. PrependPath=1 +
  REM Include_pip=1 avoid needing to touch the PATH or pip manually afterward.
  "!PYINSTALLER!" /quiet InstallAllUsers=0 PrependPath=1 Include_pip=1 Include_test=0 InstallLauncherAllUsers=0
  del "!PYINSTALLER!" >nul 2>nul

  REM The installer just updated PATH in the registry, but this already-running
  REM cmd session won't see that change on its own - re-read it from both the
  REM user and machine registry locations so "py" resolves in the rest of this script.
  for /f "skip=2 tokens=3*" %%A in ('reg query "HKCU\Environment" /v PATH 2^>nul') do set "USERPATH=%%A %%B"
  for /f "skip=2 tokens=3*" %%A in ('reg query "HKLM\SYSTEM\CurrentControlSet\Control\Session Manager\Environment" /v PATH 2^>nul') do set "SYSPATH=%%A %%B"
  set "PATH=!SYSPATH!;!USERPATH!;%PATH%"

  py -m pip --version >nul 2>nul
  if errorlevel 1 (
    echo [ERROR] Python was installed but still isn't working correctly.
    echo Please restart this computer and run this file again - a restart is
    echo sometimes needed for Windows to fully recognize a new Python install.
    pause
    exit /b 1
  )
  echo Python installed successfully.
  echo.
)

echo Checking Google Chrome...
REM Selenium needs the actual Chrome browser, not just the chromedriver
REM Python package - this was missing entirely before (found 2026-07-23 on
REM a real computer that had never had Chrome installed): setup would
REM "succeed" completely, then every search would fail with "session not
REM created: Chrome instance exited", since chromedriver had nothing to
REM launch. All three common install locations are checked - a per-user
REM install (no admin rights) lands under %LocalAppData%, not Program Files.
set "CHROME_FOUND="
if exist "%ProgramFiles%\Google\Chrome\Application\chrome.exe" set "CHROME_FOUND=1"
if exist "%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe" set "CHROME_FOUND=1"
if exist "%LocalAppData%\Google\Chrome\Application\chrome.exe" set "CHROME_FOUND=1"
if not defined CHROME_FOUND (
  echo Google Chrome isn't installed on this computer - installing it automatically.
  echo This is a one-time step and can take a few minutes, please wait...
  echo.
  set "CHROMEINSTALLER=%TEMP%\chrome_installer.exe"
  powershell -NoProfile -Command "try { Invoke-WebRequest -Uri 'https://dl.google.com/chrome/install/latest/chrome_installer.exe' -OutFile '!CHROMEINSTALLER!' -UseBasicParsing | Out-Null } catch { Write-Host \$_.Exception.Message; exit 1 }"
  if errorlevel 1 (
    echo [ERROR] Could not download Chrome. Check your internet connection and try again.
    pause
    exit /b 1
  )

  echo Installing Chrome - this window may sit quietly for a minute or two, that's normal...
  REM Run without administrator rights, this installs per-user into
  REM %LocalAppData% automatically - same "no admin needed" approach as
  REM everything else this installer does, no special flag required.
  "!CHROMEINSTALLER!" /silent /install
  del "!CHROMEINSTALLER!" >nul 2>nul

  REM The installer's own process returns almost immediately and finishes
  REM copying files in the background - a short wait avoids a false
  REM "still not found" on the re-check right below.
  timeout /t 15 /nobreak >nul

  set "CHROME_FOUND="
  if exist "%ProgramFiles%\Google\Chrome\Application\chrome.exe" set "CHROME_FOUND=1"
  if exist "%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe" set "CHROME_FOUND=1"
  if exist "%LocalAppData%\Google\Chrome\Application\chrome.exe" set "CHROME_FOUND=1"
  if not defined CHROME_FOUND (
    echo [ERROR] Chrome was installed but could not be found afterward.
    echo Please install it manually from https://www.google.com/chrome/ and run this file again.
    pause
    exit /b 1
  )
  echo Chrome installed successfully.
  echo.
)

echo Downloading the tool...
powershell -NoProfile -Command "try { Invoke-WebRequest -Uri '$downloadUrl' -OutFile '%ZIPFILE%' -UseBasicParsing | Out-Null } catch { Write-Host \$_.Exception.Message; exit 1 }"
if errorlevel 1 (
  echo [ERROR] Download failed. Check your internet connection and try again.
  pause
  exit /b 1
)
for %%Z in ("%ZIPFILE%") do set ZIPSIZE=%%~zZ
if %ZIPSIZE% LSS 1000 (
  echo [ERROR] The download looks incomplete or was rejected - it's too small to be real.
  echo This usually means the download link expired ^(it's only valid for 40 minutes^).
  echo Go back to the CRM's LPG Search page and let it download this file again, then
  echo run the new copy right away.
  del "%ZIPFILE%" >nul 2>nul
  pause
  exit /b 1
)

echo Extracting...
if not exist "%INSTALLDIR%" mkdir "%INSTALLDIR%"
powershell -NoProfile -Command "try { Expand-Archive -Path '%ZIPFILE%' -DestinationPath '%INSTALLDIR%' -Force } catch { Write-Host \$_.Exception.Message; exit 1 }"
if errorlevel 1 (
  echo [ERROR] Could not extract the downloaded file - it may be corrupted.
  echo Go back to the CRM's LPG Search page and let it download this file again.
  del "%ZIPFILE%" >nul 2>nul
  pause
  exit /b 1
)
del "%ZIPFILE%" >nul 2>nul

echo Saving your agent identity for this computer...
REM Read by app.py on every search to confirm this account is still active
REM and LPG-Search-enabled - see lpg_verify_user.php. Line 1 is the capability
REM key, line 2 is the CRM's own base URL for the verify call.
(
  echo $agentKey
  echo $verifyUrl
) > "%TOOLDIR%\lpg_agent.cfg"

echo Installing required packages - this can take a minute...
py -m pip install --quiet flask selenium
if errorlevel 1 (
  echo [ERROR] Failed to install the required Python packages ^(flask, selenium^).
  echo This usually means Python itself isn't set up correctly on this computer -
  echo try reinstalling Python from https://www.python.org/downloads/ and make sure
  echo "Add python.exe to PATH" is ticked, then run this file again.
  echo.
  echo The rest of setup will be skipped until this is fixed.
  pause
  exit /b 1
)

echo Registering auto-launch for the CRM...
reg add "HKCU\Software\Classes\lpgtool" /ve /d "URL:LPG Tool Protocol" /f >nul
reg add "HKCU\Software\Classes\lpgtool" /v "URL Protocol" /d "" /f >nul
REM %%1 (not %1) - this installer itself takes no argument, so an unescaped
REM %1 here would bake a permanent empty string into the registry value
REM instead of a real placeholder. %%1 is the literal, un-expanded text
REM "%1" that Windows substitutes with the actual clicked lpgtool:// URI
REM every time the protocol is invoked later, so the launcher can tell
REM which of the two tool pages (single vs bulk search) to open
REM (found 2026-07-26).
REM
REM Target is the .bat file DIRECTLY - NOT "cmd.exe /c "batch" "%%1""
REM (found 2026-07-26 via direct A/B testing on a real computer, after the
REM protocol silently did nothing on every click/Win+R attempt with no
REM error anywhere): when Windows substitutes %%1 into a command that
REM already has its own quoted "cmd.exe /c "path"" prefix, the result has
REM two separate quoted tokens - and cmd.exe's own /c quote-stripping rule
REM only preserves quotes when there are EXACTLY two quote characters on
REM the line. Four quote characters (two pairs) trips a fallback "strip
REM first char, strip last quote" behavior instead, mangling both the batch
REM path and the URI into one unrecognized token - silently, since the
REM failure happens inside cmd.exe's own argument parsing before anything
REM gets a chance to show an error window. Windows already resolves .bat
REM execution on its own via the file association for that extension, so
REM the "cmd.exe /c" wrapper was never actually needed.
reg add "HKCU\Software\Classes\lpgtool\shell\open\command" /ve /d "\"%TOOLDIR%\lpgtool_launcher.bat\" \"%%1\"" /f >nul

echo Starting the tool now...
start "" cmd.exe /c "%TOOLDIR%\lpgtool_launcher.bat" "lpgtool://open?target=$target"

echo.
echo ============================================
echo  Setup complete!
echo ============================================
echo Go back to the CRM tab and reload the LPG Search page - it should
echo load automatically within a few seconds.
echo.
echo From now on, just click "LPG Search" in the CRM on this computer -
echo no need to run this installer again.
echo.
pause
BAT;

// Normalize to CRLF regardless of source line endings.
$bat = str_replace("\r\n", "\n", $bat);
$bat = str_replace("\n", "\r\n", $bat);

// No-store (not just no-cache) - found 2026-07-26: this is fetched from the
// exact same URL every time (no cache-busting query string - the per-
// download token lives INSIDE the generated .bat's content, not in this
// URL), and with no cache headers at all a browser could - and, on at
// least one real computer, evidently did - keep serving a stale cached
// copy of the installer even after being told to "download it again",
// silently missing every fix made since whenever it first got cached.
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="LPG-Tool-Install.bat"');
header('Content-Length: ' . strlen($bat));
echo $bat;
