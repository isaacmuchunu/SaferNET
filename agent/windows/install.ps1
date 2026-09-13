param(
    [Parameter(Mandatory)][string]$ApiBaseUrl,
    [Parameter(Mandatory)][string]$ServiceToken,
    [Parameter(Mandatory)][string]$ManagedDeviceId,
    [string]$WorkstationId = $env:COMPUTERNAME,
    [string]$UpstreamDns = '1.1.1.1',
    [string]$Source = $PSScriptRoot,
    [switch]$ConfigureDns
)

$ErrorActionPreference = 'Stop'
$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = [Security.Principal.WindowsPrincipal]::new($identity)
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { throw 'Run this installer as Administrator.' }

# Resolve integer Device ID if string (Asset Tag or UUID) was supplied
$deviceIdInt = 0
if (-not [int]::TryParse($ManagedDeviceId, [ref]$deviceIdInt)) {
    Write-Host "Resolving device identifier '$ManagedDeviceId' via SaferNET API..."
    try {
        $headers = @{
            'Authorization' = "Bearer $ServiceToken"
            'Accept' = 'application/json'
        }
        $resolveUrl = "$($ApiBaseUrl.TrimEnd('/'))/agent/resolve-device?identifier=$([Uri]::EscapeDataString($ManagedDeviceId))"
        $response = Invoke-RestMethod -Uri $resolveUrl -Headers $headers -Method Get -TimeoutSec 15
        if ($response.data.id) {
            $deviceIdInt = [int]$response.data.id
            Write-Host "Successfully resolved to Managed Device ID: $deviceIdInt ($($response.data.asset_tag))" -ForegroundColor Green
        } else {
            throw "Invalid response format from server"
        }
    } catch {
        throw "Could not resolve device '$ManagedDeviceId' to a numeric ID. Please pass the integer ID (e.g. 101), which is displayed when running 'php artisan safernet:enroll-device'."
    }
} else {
    $deviceIdInt = [int]$ManagedDeviceId
}

$installDirectory = Join-Path $env:ProgramFiles 'SaferNET Agent'
$dataDirectory = Join-Path $env:ProgramData 'SaferNET'
New-Item -ItemType Directory -Force $installDirectory, $dataDirectory | Out-Null
Copy-Item (Join-Path $Source 'SaferNet.Agent.exe') $installDirectory -Force

$configuration = @{
    Logging = @{ LogLevel = @{ Default = 'Information'; 'Microsoft.Hosting.Lifetime' = 'Information' } }
    Agent = @{
        ApiBaseUrl = $ApiBaseUrl.TrimEnd('/')
        ServiceToken = $ServiceToken
        ManagedDeviceId = $deviceIdInt
        WorkstationId = $WorkstationId
        UpstreamDns = $UpstreamDns
        DnsPort = 53
        PolicyRefreshSeconds = 300
        HeartbeatSeconds = 60
        DataDirectory = $dataDirectory
    }
} | ConvertTo-Json -Depth 5
$configuration | Set-Content (Join-Path $installDirectory 'appsettings.json') -Encoding UTF8
icacls (Join-Path $installDirectory 'appsettings.json') /inheritance:r /grant:r 'SYSTEM:F' 'Administrators:F' | Out-Null

if (Get-Service 'SaferNetAgent' -ErrorAction SilentlyContinue) { Stop-Service 'SaferNetAgent' -Force; sc.exe delete SaferNetAgent | Out-Null }
sc.exe create SaferNetAgent binPath= ('"' + (Join-Path $installDirectory 'SaferNet.Agent.exe') + '"') start= auto DisplayName= 'SAFERNET Endpoint Agent' | Out-Null
sc.exe failure SaferNetAgent reset= 86400 actions= restart/5000/restart/15000/restart/60000 | Out-Null

if ($ConfigureDns) {
    $adapters = Get-DnsClientServerAddress -AddressFamily IPv4 | Where-Object { $_.ServerAddresses.Count -gt 0 -and $_.InterfaceAlias -notmatch 'Loopback' }
    $adapters | Select-Object InterfaceIndex, InterfaceAlias, ServerAddresses | ConvertTo-Json -Depth 4 | Set-Content (Join-Path $dataDirectory 'dns-backup.json')
    foreach ($adapter in $adapters) { Set-DnsClientServerAddress -InterfaceIndex $adapter.InterfaceIndex -ServerAddresses @('127.0.0.1') }
}

Start-Service 'SaferNetAgent'
Write-Host 'SAFERNET Endpoint Agent installed and running.'
