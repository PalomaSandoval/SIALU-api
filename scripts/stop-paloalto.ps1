param([string]$PhpPath = 'C:\xampp\php\php.exe')
$ErrorActionPreference = 'Stop'
$projectPath = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
Push-Location -LiteralPath $projectPath
try { & $PhpPath artisan sialu:paloalto-watch --stop; exit $LASTEXITCODE }
finally { Pop-Location }
