$ErrorActionPreference = 'Stop'

$projectRoot = $PSScriptRoot
$php = Join-Path $projectRoot '.runtime\php83\php.exe'
$workerScript = Join-Path $projectRoot 'cli\telegram_worker.php'
$runtimeDirectory = Join-Path $projectRoot '.runtime'
$workerOutput = Join-Path $runtimeDirectory 'telegram-worker-output.log'
$workerError = Join-Path $runtimeDirectory 'telegram-worker-error.log'
$telegramConfig = Join-Path $projectRoot 'config\telegram.php'
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
        $workerProcess = Start-Process `
            -FilePath $php `
            -ArgumentList @("`"$workerScript`"") `
            -WorkingDirectory $projectRoot `
            -WindowStyle Hidden `
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
    & $php -S 127.0.0.1:8080 -t $projectRoot
} finally {
    if ($workerProcess -and -not $workerProcess.HasExited) {
        Stop-Process -Id $workerProcess.Id -Force -ErrorAction SilentlyContinue
    }
}
