LPG Bulk Search Tool — Setup Instructions
==========================================

This tool automates looking up mobile numbers in your IndianOil SDMS
account. It needs to run on EACH computer that will use it — it opens and
controls a Chrome window on whichever computer it's running on, so it can't
be shared centrally from one machine to another.

Requirements on every computer that will use this:
  1. Python 3.x — download from https://www.python.org/downloads/ if not
     already installed. During setup, tick "Add python.exe to PATH".
  2. Google Chrome — download from https://www.google.com/chrome/ if not
     already installed.

RECOMMENDED SETUP — auto-launch from the CRM (do this once per computer):
  1. Copy this whole "lpg_web" folder to the computer.
  2. Double-click "setup_and_run.bat" once, to install the required Python
     packages (this also starts the tool — you can close that window after,
     it's just to get the packages installed the first time).
  3. Double-click "install_lpgtool_protocol.bat". This registers a
     "lpgtool://" link handler for your Windows user account only — no
     administrator rights needed, and it doesn't affect other accounts on
     this computer.
  4. Done. From now on, clicking "LPG Search" in the CRM on this computer
     will show a one-time browser permission prompt ("Open LPG Tool?"),
     then automatically start the tool in the background if it isn't
     already running, and load it right there on the page.

MANUAL ALTERNATIVE — if you'd rather not register the link handler:
  Double-click "setup_and_run.bat" every time before using the tool, and
  leave that window open while you use it. The CRM page will find it
  running either way.

Troubleshooting:
  - "Python was not found" — install Python (see Requirements above) and
    make sure "Add python.exe to PATH" was checked during install, then
    restart the computer if it still isn't found.
  - The CRM's LPG Search page shows "Can't reach the LPG Search tool" after
    the permission prompt — the packages probably aren't installed yet on
    this computer. Run setup_and_run.bat once manually first, then try again.
  - No permission prompt appears when clicking LPG Search — the one-time
    install_lpgtool_protocol.bat step hasn't been run on this computer yet.
  - Login to SDMS fails — double check the SDMS username/password stored
    inside lpg_search.py (USERNAME / PASSWORD near the top of the file) are
    still correct.
