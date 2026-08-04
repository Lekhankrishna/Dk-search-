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
    if (-not (Test-LocalPort -Port 8091)) {
        Write-Host 'Starting Telegram connection...'
        # Redirected from a file (not the console) so a 2FA password can be
        # supplied ahead of time (found 2026-07-29): this worker runs
        # -WindowStyle Hidden with no attachable console (AttachConsole
        # fails against it even from an elevated session, so there's no way
        # to type into it interactively after the fact), and
        # Tools::readLine() blocks on stdin only if/when two-step
        # verification is actually enabled on the account. A file-redirected
        # stream just sits ready in the pipe until that read actually
        # happens - harmless if it's never consumed, and means the QR-scan
        # step (which takes several attempts on this network) never has to
        # be repeated just to reach the password prompt again.
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
    }

    Write-Host 'Telegram connected.'
    Write-Host 'CRM: http://127.0.0.1:8080/'
    Write-Host 'Press Ctrl+C to stop everything.'
    Write-Host ''
    # Router script (not just -t $projectRoot) - without it, PHP's built-in
    # server serves EVERY file under the project root as a raw static
    # file/script with no access control, including config/*.php and
    # anything under .runtime/ - confirmed 2026-07-29 by directly fetching
    # real credentials over http://127.0.0.1:8080/. See router.php's own
    # comment for the full explanation.
    & $php -S 127.0.0.1:8080 -t $projectRoot $routerScript
} finally {
    if ($workerProcess -and -not $workerProcess.HasExited) {
        Stop-Process -Id $workerProcess.Id -Force -ErrorAction SilentlyContinue
    }
}
