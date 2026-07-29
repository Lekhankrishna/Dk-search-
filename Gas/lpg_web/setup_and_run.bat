@echo off
setlocal

echo ============================================
echo  LPG Bulk Search Tool - Setup and Run
echo ============================================
echo.

where py >nul 2>nul
if errorlevel 1 (
  echo [ERROR] Python was not found on this computer.
  echo.
  echo Please install Python first:
  echo   1. Go to https://www.python.org/downloads/
  echo   2. Run the installer
  echo   3. IMPORTANT: tick "Add python.exe to PATH" during setup
  echo   4. Re-run this script after installing.
  echo.
  pause
  exit /b 1
)

echo Checking Google Chrome is installed...
if not exist "%ProgramFiles%\Google\Chrome\Application\chrome.exe" (
  if not exist "%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe" (
    echo [WARNING] Google Chrome was not found in the usual install location.
    echo This tool needs Chrome installed to work. Install it from https://www.google.com/chrome/
    echo Continuing anyway in case it's installed somewhere else...
    echo.
  )
)

echo Installing/updating required Python packages (flask, selenium)...
py -m pip install --quiet flask selenium
if errorlevel 1 (
  echo [ERROR] Failed to install required packages. Check your internet connection and try again.
  pause
  exit /b 1
)

echo.
echo Starting the LPG Bulk Search tool...
echo Once it says "Running on http://127.0.0.1:9196", leave this window open
echo and open that address in your browser (or use the LPG Search page in the CRM).
echo Press CTRL+C in this window to stop it.
echo.
py app.py

pause
