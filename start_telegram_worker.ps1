# Starts just the Telegram worker (port 8091), without touching the main
# PHP server (port 9196) - split out of run_crm_server.ps1's own worker-
# start block (2026-08-19) so CRMServerService.bat's watchdog loop can
# restart the worker on its own if it dies independently of the main
# server. Before this script existed, the watchdog only checked port 9196's
# HTTP health - a worker crash (confirmed live 2026-08-19: dead for 5 days
# after a connection reset, `AUTH_KEY_DUPLICATED` on the next reconnect
# attempt) went completely undetected as long as the main server kept
# answering requests, since every OTHER page on the site works fine without
# the worker - only api/pan_india.php actually depends on it.
#
# Safe to run repeatedly/concurrently with the main server already up:
# only touches the worker's own process, session files, and log files -
# never the port-9196 listener. Does NOT loop or wait; the CALLER (the
# watchdog's own timed loop) is what provides "keep retrying" behaviour.
$ErrorActionPreference = 'Stop'

$projectRoot = $PSScriptRoot
$php = Join-Path $projectRoot '.runtime\php83\php.exe'
$workerScript = Join-Path $projectRoot 'cli\telegram_worker.php'
$runtimeDirectory = Join-Path $projectRoot '.runtime'
$workerOutput = Join-Path $runtimeDirectory 'telegram-worker-output.log'
$workerError = Join-Path $runtimeDirectory 'telegram-worker-error.log'
$workerInput = Join-Path $runtimeDirectory 'telegram-worker-input.txt'

function Test-LocalPort {
    param([int] $Port)
    $socket = New-Object System.Net.Sockets.TcpClient
    try {
        $result = $socket.BeginConnect('127.0.0.1', $Port, $null, $null)
        if (-not $result.AsyncWaitHandle.WaitOne(1000)) { return $false }
        $socket.EndConnect($result)
        return $true
    } catch {
        return $false
    } finally {
        $socket.Dispose()
    }
}

if (Test-LocalPort -Port 8091) {
    # Already up (a concurrent watchdog tick, or it recovered on its own
    # between the caller's check and this script actually running) -
    # starting a second instance against the same session is exactly what
    # produces AUTH_KEY_DUPLICATED, so this is a hard stop, not a retry.
    Write-Output 'Telegram worker already listening on 8091 - not starting a second instance.'
    exit 0
}

if (-not (Test-Path -LiteralPath $workerInput)) {
    New-Item -ItemType File -Path $workerInput -Force | Out-Null
}

Start-Process `
    -FilePath $php `
    -ArgumentList @("`"$workerScript`"") `
    -WorkingDirectory $projectRoot `
    -WindowStyle Hidden `
    -RedirectStandardInput $workerInput `
    -RedirectStandardOutput $workerOutput `
    -RedirectStandardError $workerError | Out-Null

Write-Output 'Telegram worker start requested.'
