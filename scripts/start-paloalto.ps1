param(
    [string]$PhpPath = 'C:\xampp\php\php.exe',
    [string]$Since = '2026-10-22'
)
$ErrorActionPreference = 'Stop'
$projectPath = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
if (-not (Test-Path -LiteralPath $PhpPath -PathType Leaf)) { throw 'No se encontró PHP.' }
if ($Since -notmatch '^\d{4}-\d{2}-\d{2}$') { throw 'La fecha debe usar YYYY-MM-DD.' }
$logPath = Join-Path $projectPath 'storage\logs'
$runId = [Guid]::NewGuid().ToString('N').Substring(0, 10)
$outFile = Join-Path $logPath "paloalto-worker-$runId.log"
$errFile = Join-Path $logPath "paloalto-worker-$runId-error.log"
$worker = Start-Process -FilePath $PhpPath -ArgumentList @('artisan', 'sialu:paloalto-watch', '--persist', "--since=$Since", '--interval=60', '--page-size=10', '--max-pages=1') -WorkingDirectory $projectPath -WindowStyle Hidden -RedirectStandardOutput $outFile -RedirectStandardError $errFile -PassThru
Write-Output "Proceso solicitado. PID: $($worker.Id). Log: $outFile"
Write-Output 'Para detenerlo: powershell -File scripts/stop-paloalto.ps1'
