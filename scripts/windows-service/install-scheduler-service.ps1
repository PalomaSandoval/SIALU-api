<#
.SYNOPSIS
  Compila e instala el servicio de Windows SialuScheduler (Laravel Scheduler de SIALU). Ver docs/programador.md.
.DESCRIPTION
  -BuildOnly solo compila (no requiere administrador). La instalación requiere PowerShell como administrador,
  no sobrescribe un servicio existente y no inicia el servicio salvo con -Start.
.EXAMPLE
  powershell -ExecutionPolicy Bypass -File scripts\windows-service\install-scheduler-service.ps1 -BuildOnly
.EXAMPLE
  powershell -ExecutionPolicy Bypass -File scripts\windows-service\install-scheduler-service.ps1 -Start
.EXAMPLE
  powershell -ExecutionPolicy Bypass -File scripts\windows-service\install-scheduler-service.ps1 -Credential (Get-Credential DOMINIO\svc-sialu)
#>
param(
    [string]$PhpPath = 'C:\xampp\php\php.exe',
    # Cuenta virtual por defecto (sin contraseña). Necesita un login en SQL Server; ver docs/programador.md.
    [string]$Account = 'NT SERVICE\SialuScheduler',
    # Cuenta con contraseña (local o de dominio) en lugar de la cuenta virtual.
    [pscredential]$Credential,
    # Servicios de los que depende; por defecto MSSQLSERVER si existe en este equipo.
    [string[]]$DependsOn,
    [switch]$BuildOnly,
    [switch]$Start
)
$ErrorActionPreference = 'Stop'
$serviceName = 'SialuScheduler'
$here = $PSScriptRoot
$appPath = [System.IO.Path]::GetFullPath((Join-Path $here '..\..'))
$source = Join-Path $here 'SialuSchedulerService.cs'
$binDir = Join-Path $here 'bin'
$exe = Join-Path $binDir 'SialuScheduler.exe'
$csc = Join-Path $env:WINDIR 'Microsoft.NET\Framework64\v4.0.30319\csc.exe'

if (-not (Test-Path -LiteralPath $PhpPath -PathType Leaf)) { throw "No se encontró PHP: $PhpPath" }
if (-not (Test-Path -LiteralPath (Join-Path $appPath 'artisan') -PathType Leaf)) { throw "No se encontró artisan en $appPath" }
if (-not (Test-Path -LiteralPath $csc -PathType Leaf)) { throw "No se encontró el compilador de .NET Framework 4: $csc" }

# 1. Compilar desde el código fuente del repositorio (sin binarios de terceros).
New-Item -ItemType Directory -Force -Path $binDir | Out-Null
& $csc /nologo /target:exe /optimize+ /platform:anycpu "/out:$exe" /reference:System.ServiceProcess.dll $source
if ($LASTEXITCODE -ne 0) { throw 'Falló la compilación de SialuScheduler.exe.' }
Write-Output "Compilado: $exe"
if ($BuildOnly) { return }

# 2. Comprobaciones previas.
$admin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $admin) { throw 'La instalación requiere PowerShell como administrador (o usar -BuildOnly).' }
if (Get-Service -Name $serviceName -ErrorAction SilentlyContinue) {
    throw "El servicio $serviceName ya existe; no se duplica. Para reinstalar: scripts\windows-service\uninstall-scheduler-service.ps1"
}
if (schtasks /Query /FO LIST /V 2>$null | Select-String -SimpleMatch 'sialu:orion-status') { throw 'Existe una tarea programada de Windows que ya ejecuta sialu:orion-status; eliminarla antes para no duplicar.' }
$envFile = Join-Path $appPath '.env'
if (-not (Select-String -LiteralPath $envFile -Pattern '^\s*ORION_STATUS_SCHEDULE_ENABLED\s*=\s*true\s*$' -Quiet)) {
    Write-Warning 'ORION_STATUS_SCHEDULE_ENABLED no es true en .env: el servicio correrá, pero sin ejecutar sialu:orion-status.'
}
if (-not $PSBoundParameters.ContainsKey('DependsOn')) {
    $DependsOn = @(Get-Service -Name 'MSSQLSERVER' -ErrorAction SilentlyContinue | ForEach-Object Name)
}
$runAs = if ($Credential) { $Credential.UserName } else { $Account }

# 3. Origen del registro de eventos (Application) para avisos y errores.
if (-not [System.Diagnostics.EventLog]::SourceExists($serviceName)) {
    New-EventLog -LogName Application -Source $serviceName
}

# 4. Crear el servicio: inicio automático retrasado (después de red y SQL Server).
$binPath = "`"$exe`" --php `"$PhpPath`" --app `"$appPath`""
$scArgs = @('create', $serviceName, 'binPath=', $binPath, 'start=', 'delayed-auto', 'DisplayName=', 'SIALU Programador (Laravel Scheduler)')
if ($DependsOn) { $scArgs += @('depend=', ($DependsOn -join '/')) }
if ($Credential) {
    $scArgs += @('obj=', $Credential.UserName, 'password=', $Credential.GetNetworkCredential().Password)
} else {
    $scArgs += @('obj=', $Account)
}
& sc.exe @scArgs | Out-Null
if ($LASTEXITCODE -ne 0) { throw "sc.exe create falló (código $LASTEXITCODE)." }
& sc.exe description $serviceName 'Ejecuta php artisan schedule:run cada minuto (SIALU-api, routes/console.php). Registro: storage\logs\scheduler-service.log.' | Out-Null
# Si el proceso termina inesperadamente: reiniciar a 1 min, 1 min y 5 min; el contador se reinicia cada día.
& sc.exe failure $serviceName reset= 86400 actions= restart/60000/restart/60000/restart/300000 | Out-Null
& sc.exe failureflag $serviceName 1 | Out-Null

# 5. Permisos: la cuenta escribe en storage (logs, caché del bloqueo, lotes crudos) y lee el proyecto.
$storage = Join-Path $appPath 'storage'
& icacls.exe $storage /grant "${runAs}:(OI)(CI)M" /T /C /Q | Out-Null
if ($LASTEXITCODE -ne 0) { throw "icacls no pudo dar permisos de modificación en $storage a $runAs." }
& icacls.exe $appPath /grant "${runAs}:(OI)(CI)RX" /C /Q | Out-Null
& icacls.exe (Split-Path $PhpPath) /grant "${runAs}:(OI)(CI)RX" /C /Q | Out-Null

Write-Output "Servicio $serviceName instalado. Cuenta: $runAs. Dependencias: $($DependsOn -join ', ')"
Write-Output "Línea de comandos: $binPath"
Write-Output "Pendiente: login de SQL Server para $runAs con los permisos de SIALU en la base (ver docs/programador.md)."
if ($Start) {
    Start-Service -Name $serviceName
    Get-Service -Name $serviceName | Format-Table Name, Status, StartType -AutoSize
} else {
    Write-Output "Para iniciarlo: Start-Service $serviceName"
}
