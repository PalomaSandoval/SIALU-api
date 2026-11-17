<#
.SYNOPSIS
  Recompila SialuScheduler.exe y reinicia el servicio existente (sin reinstalarlo ni cambiar cuenta, dependencias o permisos).
  Requiere PowerShell como administrador. Ver docs/programador.md.
.EXAMPLE
  powershell -ExecutionPolicy Bypass -File scripts\windows-service\update-scheduler-service.ps1
#>
param([string]$PhpPath = 'C:\xampp\php\php.exe')
$ErrorActionPreference = 'Stop'
$serviceName = 'SialuScheduler'
$admin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $admin) { throw 'La actualización requiere PowerShell como administrador.' }
if (-not (Get-Service -Name $serviceName -ErrorAction SilentlyContinue)) { throw "No existe el servicio $serviceName; usar install-scheduler-service.ps1." }

# Detener primero: el ejecutable está bloqueado mientras corre. Stop espera a que terminen las ejecuciones en curso.
Stop-Service -Name $serviceName
(Get-Service -Name $serviceName).WaitForStatus('Stopped', [TimeSpan]::FromMinutes(3))
try {
    & (Join-Path $PSScriptRoot 'install-scheduler-service.ps1') -PhpPath $PhpPath -BuildOnly
} finally {
    Start-Service -Name $serviceName
}
Get-Service -Name $serviceName | Format-Table Name, Status, StartType -AutoSize
