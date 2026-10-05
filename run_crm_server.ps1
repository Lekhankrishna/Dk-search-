$ErrorActionPreference = 'Stop'

$projectRoot = $PSScriptRoot
$php = Join-Path $projectRoot '.runtime\php83\php.exe'
$workerScript = Join-Path $projectRoot 'cli\telegram_worker.php'
$runtimeDirectory = Join-Path $projectRoot '.runtime'
$workerOutput = Join-Path $runtimeDirectory 'telegram-worker-output.log'
$workerError = Join-Path $runtimeDirectory 'telegram-worker-error.log'
$workerInput = Join-Path $runtimeDirectory 'telegram-worker-input.txt'
$telegramConfig = Join-Path $projectRoot 'config\telegram.php'
$routerScript = Join-Path $runtimeDirectory 'router.php'
$workerProcess = $null

if (-not (Test-Path -LiteralPath $php)) {
    throw "PHP 8.3 runtime is missing: $php"
}

$configCheck = & $php -l $telegramConfig 2>&1
if ($LASTEXITCODE -ne 0) {
    throw "Telegram configuration is invalid.`n$($configCheck -join [Environment]::NewLine)"
}

function Test-LocalPort {
    param([int] $Port)
    $socket = New-Object System.Net.Sockets.TcpClient
    try {
        $result = $socket.BeginConnect('127.0.0.1', $Port, $null, $null)
        if (-not $result.AsyncWaitHandle.WaitOne(250)) { return $false }
        $socket.EndConnect($result)
        return $true
    } catch {
        return $false
    } finally {
        $socket.Dispose()
    }
}

try {
    # Best-effort, not a hard dependency (2026-08-19, per a real outage this
    # caused): this used to `throw` if the Telegram worker didn't come up
    # within 30s, which - since $ErrorActionPreference is 'Stop' and there
    # was no catch around just this block - aborted the ENTIRE script
    # before ever reaching the main server's own `& $php -S ...` line
    # below. That meant a broken Telegram worker (confirmed live: stuck for
    # hours on a login timeout unrelated to the main site at all) took the
    # WHOLE CRM down with it, and every one of the watchdog's 20s restart
    # retries independently blocked on the same 30s worker wait before
    # failing - 211 stuck cmd.exe processes accumulated from exactly this
    # before it was caught. The main PHP server and the Telegram worker are
    # independent services (only api/pan_india.php depends on the worker;
    # every other page works fine without it) and a failure in one must
    # never prevent the other from starting - a caught, logged warning here
    # instead of a thrown exception is what actually enforces that.
    if (-not (Test-LocalPort -Port 8091)) {
        try {
            Write-Host 'Starting Telegram connection...'
            # Redirected from a file (not the console) so a 2FA password can
            # be supplied ahead of time (found 2026-07-29): this worker runs
            # -WindowStyle Hidden with no attachable console (AttachConsole
            # fails against it even from an elevated session, so there's no
            # way to type into it interactively after the fact), and
            # Tools::readLine() blocks on stdin only if/when two-step
            # verification is actually enabled on the account. A file-
            # redirected stream just sits ready in the pipe until that read
            # actually happens - harmless if it's never consumed, and means
            # the QR-scan step (which takes several attempts on this
            # network) never has to be repeated just to reach the password
            # prompt again.
            if (-not (Test-Path -LiteralPath $workerInput)) {
                New-Item -ItemType File -Path $workerInput -Force | Out-Null
            }
            $workerProcess = Start-Process `
                -FilePath $php `
                -ArgumentList @("`"$workerScript`"") `
                -WorkingDirectory $projectRoot `
                -WindowStyle Hidden `
                -RedirectStandardInput $workerInput `
                -RedirectStandardOutput $workerOutput `
                -RedirectStandardError $workerError `
                -PassThru

            $ready = $false
            for ($attempt = 0; $attempt -lt 60; $attempt++) {
                Start-Sleep -Milliseconds 500
                if ($workerProcess.HasExited) {
                    $details = if (Test-Path $workerError) {
                        (Get-Content $workerError -Tail 20) -join [Environment]::NewLine
                    } else {
                        'No worker error log was produced.'
                    }
                    throw "Telegram worker stopped during startup.`n$details"
                }
                if (Test-LocalPort -Port 8091) {
                    $ready = $true
                    break
                }
            }
            if (-not $ready) {
                throw "Telegram worker did not become ready. Check $workerError"
            }
            Write-Host 'Telegram connected.'
        } catch {
            Write-Warning "Telegram worker did not start (Pan India Search will be unavailable, everything else is unaffected): $($_.Exception.Message)"
        }
    } else {
        Write-Host 'Telegram connected.'
    }

    Write-Host 'CRM (local):  http://127.0.0.1:9196/'
    Write-Host 'CRM (public): http://datasearch.in:9196/crm-app/  (router-forwarded to this machine, 2026-08-07)'
    Write-Host 'Press Ctrl+C to stop everything.'
    Write-Host ''
    # 0.0.0.0 (not 127.0.0.1) so the router-forwarded public URL can reach
    # this - PHP's built-in server isn't hardened for public traffic and
    # this is plain HTTP with no TLS, both accepted trade-offs for now
    # (2026-08-07) given the alternative (IIS as a reverse proxy) needs
    # admin access this environment doesn't have.
    #
    # Router script (not just -t $projectRoot) - without it, PHP's built-in
    # server serves EVERY file under the project root as a raw static
    # file/script with no access control, including config/*.php and
    # anything under .runtime/ - confirmed 2026-07-29 by directly fetching
    # real credentials over http://127.0.0.1:8080/. It also now strips a
    # /crm-app/ prefix, since that's what the public URL is routed under.
    # See router.php's own comment for the full explanation of both.
    & $php -S 0.0.0.0:9196 -t $projectRoot $routerScript
} finally {
    if ($workerProcess -and -not $workerProcess.HasExited) {
        Stop-Process -Id $workerProcess.Id -Force -ErrorAction SilentlyContinue
    }
}
