param([switch]$KeepConfiguration)

$ErrorActionPreference = 'Stop'
$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = [Security.Principal.WindowsPrincipal]::new($identity)
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { throw 'Run this uninstaller as Administrator.' }

$dataDirectory = Join-Path $env:ProgramData 'SaferNET'
$backup = Join-Path $dataDirectory 'dns-backup.json'
if (Test-Path $backup) {
    foreach ($adapter in @(Get-Content $backup -Raw | ConvertFrom-Json)) {
        Set-DnsClientServerAddress -InterfaceIndex $adapter.InterfaceIndex -ServerAddresses @($adapter.ServerAddresses)
    }
}

if (Get-Service 'SaferNetAgent' -ErrorAction SilentlyContinue) { Stop-Service 'SaferNetAgent' -Force; sc.exe delete SaferNetAgent | Out-Null }
Remove-Item -LiteralPath (Join-Path $env:ProgramFiles 'SaferNET Agent') -Recurse -Force -ErrorAction SilentlyContinue
if (-not $KeepConfiguration) { Remove-Item -LiteralPath $dataDirectory -Recurse -Force -ErrorAction SilentlyContinue }
Write-Host 'SAFERNET Endpoint Agent uninstalled.'
