' Registered as the handler for the "lpgtool://" link protocol (see
' install_lpgtool_protocol.bat). Silently starts the local Flask server if
' it isn't already running — a second attempt while one is already running
' just fails fast on the port-already-in-use error and exits, which is fine,
' that's the desired "start it only if needed" behavior with no extra
' detection logic required.
Set objShell = CreateObject("WScript.Shell")
Set objFSO = CreateObject("Scripting.FileSystemObject")
scriptDir = objFSO.GetParentFolderName(WScript.ScriptFullName)
objShell.CurrentDirectory = scriptDir

' 0 = hidden window, False = don't wait (the server runs indefinitely)
objShell.Run "py """ & scriptDir & "\app.py""", 0, False
