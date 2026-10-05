# Starts DK Search on http://<this-PC>:8180/ with several app copies behind
# .runtime/lb.php, so agents' requests run in parallel instead of queuing
# behind one PHP built-in server (2026-10-03). Safe to re-run: anything
# already listening is left alone.
#
# Uses crm-app-v2's bundled PHP 8.3 - the php.exe Windows Firewall already
# allows - so the front door needs no new firewall rule.

$ErrorActionPreference = 'Stop'
$root    = $PSScriptRoot
$php     = 'C:\crm-app-v2\.runtime\php83\php.exe'
$router  = Join-Path $root '.runtime\router.php'
$lb      = Join-Path $root '.runtime\lb.php'
$public  = 8180
$workers = 8181..8190

function Test-Listening([int] $Port) {
    [bool](Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue)
}

# max_execution_time: crm-app-v2's php.ini (shared runtime, left untouched)
# sets 30s, and on Windows that counts wall-clock time spent waiting on the
# vendor - so any search the vendor took >30s to answer was killed mid-wait
# and the browser got a PHP error page instead of JSON ("could not reach
# the server"; found live 2026-10-03 when Cyfuture slowed to ~35s/number).
# 180s comfortably covers the slowest tool (locateme scan + login ~60s).
foreach ($p in $workers) {
    if (-not (Test-Listening $p)) {
        Start-Process -FilePath $php -ArgumentList '-d', 'max_execution_time=180', '-S', "127.0.0.1:$p", '-t', $root, $router `
            -WorkingDirectory $root -WindowStyle Hidden
    }
}

if (-not (Test-Listening $public)) {
    $backendArgs = $workers | ForEach-Object { "127.0.0.1:$_" }
    Start-Process -FilePath $php -ArgumentList (@($lb, "0.0.0.0:$public") + $backendArgs) `
        -WorkingDirectory $root -WindowStyle Hidden `
        -RedirectStandardError (Join-Path $root '.runtime\lb-error.log')
}

# --- Python/Selenium service (HP Gas, Indane Gas, Tracing 2.0, RC Print,
# Aadhaar to Family, Indane Gas Pro) on 127.0.0.1:9197 ---
# Checked with a real HTTP request, not just "is the port listening": when
# the service is stopped, the headless Chrome/ChromeDriver processes it
# started keep its listening socket alive (found 2026-10-03 - a dead
# service's port kept "listening" and every request was refused for hours).
# So if it doesn't answer, those leftovers are cleared before a fresh start.
# Only automation Chrome (--headless) and ChromeDriver are touched - never
# anyone's normal Chrome windows.
function Test-PythonService {
    try {
        Invoke-WebRequest 'http://127.0.0.1:9197/api/search/healthcheck' -UseBasicParsing -TimeoutSec 5 | Out-Null
        return $true
    } catch {
        return [bool]$_.Exception.Response   # any HTTP reply (even 404) means it's alive
    }
}

$lpgDir  = Join-Path $root 'Gas\lpg_web'
$pythonw = Join-Path $env:LOCALAPPDATA 'Programs\Python\Python312\pythonw.exe'
if (-not (Test-PythonService)) {
    Get-CimInstance Win32_Process | Where-Object {
        $_.Name -eq 'chromedriver.exe' -or
        ($_.Name -eq 'chrome.exe' -and $_.CommandLine -match '--headless') -or
        ($_.Name -eq 'pythonw.exe' -and $_.CommandLine -match 'app\.py')
    } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
    Start-Sleep -Seconds 2
    Start-Process -FilePath $pythonw -ArgumentList 'app.py' -WorkingDirectory $lpgDir -WindowStyle Hidden `
        -RedirectStandardOutput (Join-Path $lpgDir 'service.log') `
        -RedirectStandardError (Join-Path $lpgDir 'service_error.log')
    for ($i = 0; $i -lt 20 -and -not (Test-PythonService); $i++) { Start-Sleep -Seconds 1 }
}

Start-Sleep -Seconds 2
$up = @($workers | Where-Object { Test-Listening $_ }).Count
Write-Host "Python service on 9197: $(if (Test-PythonService) {'UP'} else {'DOWN'})"
Write-Host "DK Search: front door on $public $(if (Test-Listening $public) {'UP'} else {'DOWN'}), $up of $($workers.Count) app copies up"
Write-Host "  http://127.0.0.1:$public/   (this PC)"
Write-Host "  http://192.168.0.4:$public/ (other devices on the network)"
