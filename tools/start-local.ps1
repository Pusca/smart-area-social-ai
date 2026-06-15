param(
    [switch]$NoRestart
)

$ErrorActionPreference = "Stop"

$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

$php = "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe"
if (-not (Test-Path $php)) {
    $php = (Get-Command php).Source
}

$logDir = Join-Path $root "storage\logs"
if (-not (Test-Path $logDir)) {
    New-Item -ItemType Directory -Path $logDir | Out-Null
}

function Stop-IfRunning([string]$pattern) {
    $procs = Get-CimInstance Win32_Process | Where-Object { $_.CommandLine -like "*$pattern*" }
    foreach ($p in $procs) {
        try { Stop-Process -Id $p.ProcessId -Force -ErrorAction Stop } catch {}
    }
}

if (-not $NoRestart) {
    Stop-IfRunning "artisan serve --host=127.0.0.1 --port=8000"
    Stop-IfRunning "artisan queue:work --queue=default"
    Stop-IfRunning "vite.js --host 127.0.0.1 --port 5173"
}

& $php artisan queue:restart | Out-Null

$serveOut = Join-Path $logDir "local.serve.out.log"
$serveErr = Join-Path $logDir "local.serve.err.log"
$queueOut = Join-Path $logDir "local.queue.out.log"
$queueErr = Join-Path $logDir "local.queue.err.log"
$viteOut = Join-Path $logDir "local.vite.out.log"
$viteErr = Join-Path $logDir "local.vite.err.log"

$serve = Start-Process -FilePath $php `
    -ArgumentList "artisan","serve","--host=127.0.0.1","--port=8000" `
    -WorkingDirectory $root `
    -RedirectStandardOutput $serveOut `
    -RedirectStandardError $serveErr `
    -PassThru

$queue = Start-Process -FilePath $php `
    -ArgumentList "artisan","queue:work","--queue=default","--sleep=1","--tries=1","--timeout=180","-v" `
    -WorkingDirectory $root `
    -RedirectStandardOutput $queueOut `
    -RedirectStandardError $queueErr `
    -PassThru

$vite = Start-Process -FilePath "cmd.exe" `
    -ArgumentList "/c","npm.cmd run dev -- --host 127.0.0.1 --port 5173" `
    -WorkingDirectory $root `
    -RedirectStandardOutput $viteOut `
    -RedirectStandardError $viteErr `
    -PassThru

Write-Host "Local stack started."
Write-Host "serve pid: $($serve.Id)  -> http://127.0.0.1:8000"
Write-Host "queue pid: $($queue.Id)"
Write-Host "vite  pid: $($vite.Id)   -> http://127.0.0.1:5173"
Write-Host "logs: $logDir"
