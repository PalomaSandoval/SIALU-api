<#
.SYNOPSIS
  Detiene y elimina el servicio SialuScheduler. Conserva los registros en storage\logs.
  -RemoveEventSource también elimina el origen "SialuScheduler" del registro de eventos.
#>
param([switch]$RemoveEventSource)
$ErrorActionPreference = 'Stop'
$serviceName = 'SialuScheduler'
$admin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $admin) { throw 'Requiere PowerShell como administrador.' }
$service = Get-Service -Name $serviceName -ErrorAction SilentlyContinue
if ($service) {
    # El servicio espera hasta 90 s a que termine una ejecución en curso antes de detenerse.
    if ($service.Status -ne 'Stopped') { Stop-Service -Name $serviceName; $service.WaitForStatus('Stopped', '00:02:00') }
    & sc.exe delete $serviceName | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "sc.exe delete falló (código $LASTEXITCODE)." }
    Write-Output "Servicio $serviceName eliminado."
} else {
    Write-Output "El servicio $serviceName no existe."
}
if ($RemoveEventSource -and [System.Diagnostics.EventLog]::SourceExists($serviceName)) {
    Remove-EventLog -Source $serviceName
    Write-Output 'Origen del registro de eventos eliminado.'
}
