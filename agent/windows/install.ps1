<#
.SYNOPSIS
    Installs or upgrades the SAFERNET endpoint agent.

.DESCRIPTION
    Adapter DNS is the dangerous part of this installer: pointing a machine at a
    local resolver that is not answering takes it off the network entirely. So
    the order here is deliberate.

      1. The first known-good DNS configuration is captured, including whether
         each adapter took its servers from DHCP. Existing entries are never
         overwritten; an adapter that appeared since the last run is added, so
         everything this run redirects can be restored.
      2. The service is started and proven to resolve before any adapter is
         touched.
      3. Any failure while redirecting adapters rolls every adapter back.

    Running it twice is safe: a second run upgrades in place and keeps the
    original settings on record.
#>
param(
    [Parameter(Mandatory)][string]$ApiBaseUrl,
    [Parameter(Mandatory)][string]$ServiceToken,
    [Parameter(Mandatory)][string]$ManagedDeviceId,
    [string]$WorkstationId = $env:COMPUTERNAME,
    [string]$UpstreamDns = '1.1.1.1',
    [string]$Source = $PSScriptRoot,
    [int]$ReadinessTimeoutSeconds = 45,
    [switch]$ConfigureDns,

    # Chrome Web Store id of SaferNET Shield. Supplying it force-installs the
    # extension for every user on this machine.
    [string]$ExtensionId,
    [string]$ExtensionUpdateUrl = 'https://clients2.google.com/service/update2/crx',
    [switch]$ForceInstallExtension
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$ServiceName = 'SaferNetAgent'

$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = [Security.Principal.WindowsPrincipal]::new($identity)
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { throw 'Run this installer as Administrator.' }

function Get-ManagedAdapter {
    <#
        Physical, DNS-carrying IPv4 adapters. Loopback and any adapter already
        pointed at the local resolver are excluded: capturing 127.0.0.1 as the
        "original" configuration is exactly how a reinstall destroys the real one.
    #>
    Get-DnsClientServerAddress -AddressFamily IPv4 |
        Where-Object {
            $_.ServerAddresses.Count -gt 0 -and
            $_.InterfaceAlias -notmatch 'Loopback' -and
            ($_.ServerAddresses | Where-Object { $_ -ne '127.0.0.1' -and $_ -ne '::1' }).Count -gt 0
        }
}

function Test-DnsFromDhcp {
    <#
        Whether an adapter's DNS servers came from DHCP rather than being set
        statically. Restoring addresses onto an adapter that should be asking
        DHCP leaves it statically configured forever, so the mode is recorded
        alongside the addresses.
    #>
    param([Parameter(Mandatory)][string]$InterfaceGuid)

    $key = "HKLM:\SYSTEM\CurrentControlSet\Services\Tcpip\Parameters\Interfaces\$InterfaceGuid"
    if (-not (Test-Path $key)) { return $false }

    $static = (Get-ItemProperty -Path $key -Name 'NameServer' -ErrorAction SilentlyContinue).NameServer
    return [string]::IsNullOrWhiteSpace($static)
}

function Save-DnsBackup {
    <#
        Captures the original configuration, and keeps it.

        An existing backup is never overwritten: it holds the settings from
        before this machine was ever redirected, and replacing it with today's
        127.0.0.1 is exactly what makes the original unrecoverable.

        It is still *extended*, though. An adapter added since the first install
        — a dock, a USB NIC, a VPN — carries real DNS that this run is about to
        redirect, so it has to be recorded now or the uninstaller will have
        nothing to restore it from and will leave it pointing at a resolver that
        no longer runs.
    #>
    param([Parameter(Mandatory)][string]$Path)

    $captured = @(Get-ManagedAdapter | ForEach-Object {
        $configuration = Get-NetAdapter -InterfaceIndex $_.InterfaceIndex -ErrorAction SilentlyContinue
        [pscustomobject]@{
            InterfaceGuid   = if ($configuration) { $configuration.InterfaceGuid } else { $null }
            InterfaceAlias  = $_.InterfaceAlias
            InterfaceIndex  = $_.InterfaceIndex
            ServerAddresses = @($_.ServerAddresses)
            DnsFromDhcp     = if ($configuration) { Test-DnsFromDhcp -InterfaceGuid $configuration.InterfaceGuid } else { $false }
        }
    })

    $existing = @()
    $capturedAt = (Get-Date).ToString('o')

    if (Test-Path $Path) {
        $backup = Get-Content $Path -Raw | ConvertFrom-Json
        $existing = @(if ($backup.PSObject.Properties.Name -contains 'Adapters') { $backup.Adapters } else { $backup })
        if ($backup.PSObject.Properties.Name -contains 'CapturedAt' -and $backup.CapturedAt) { $capturedAt = $backup.CapturedAt }
    }

    # Anything already on record keeps its original entry; only genuinely new
    # adapters are added.
    $knownGuids = @($existing | ForEach-Object { $_.InterfaceGuid } | Where-Object { $_ })
    $knownAliases = @($existing | ForEach-Object { $_.InterfaceAlias } | Where-Object { $_ })

    $added = @($captured | Where-Object {
        ($_.InterfaceGuid -and $knownGuids -notcontains $_.InterfaceGuid) -or
        (-not $_.InterfaceGuid -and $knownAliases -notcontains $_.InterfaceAlias)
    })

    $adapters = @($existing) + $added

    [pscustomobject]@{
        CapturedAt = $capturedAt
        UpdatedAt  = (Get-Date).ToString('o')
        Adapters   = $adapters
    } | ConvertTo-Json -Depth 5 | Set-Content -Path $Path -Encoding UTF8

    if ($adapters.Count -eq 0) {
        Write-Warning "No adapter carries a DNS server other than the local resolver, and no earlier backup exists. This machine's original DNS configuration cannot be recovered by the uninstaller; set it by hand if you remove the agent."
    } elseif ($existing.Count -eq 0) {
        Write-Host "Captured the DNS configuration of $($adapters.Count) adapter(s) to $Path."
    } elseif ($added.Count -gt 0) {
        Write-Host "Added $($added.Count) newly-present adapter(s) to the existing DNS backup at $Path; the original $($existing.Count) entr(y/ies) were left untouched."
    } else {
        Write-Host "Keeping the existing DNS backup at $Path (captured before this machine was redirected)."
    }
}

function Set-ForcedExtension {
    <#
        Force-installs the browser extension through Chrome policy.

        This is the only thing that actually stops a learner removing it. An
        extension cannot defend itself: anything installed normally can be
        switched off from chrome://extensions in two clicks, and no amount of
        extension code changes that. Listed in ExtensionInstallForcelist, its
        Remove and Disable controls are greyed out and the browser reinstalls it
        if the files are deleted.

        It is not a complete answer either. A learner who opens a different
        browser is outside Chrome policy entirely — which is why the endpoint
        agent filters at DNS, below whichever browser they choose. The extension
        is the layer that sees page titles and classroom state; the agent is the
        layer that cannot be walked around.
    #>
    param(
        [Parameter(Mandatory)][string]$Id,
        [Parameter(Mandatory)][string]$UpdateUrl
    )

    $policyKey = 'HKLM:\SOFTWARE\Policies\Google\Chrome\ExtensionInstallForcelist'
    if (-not (Test-Path $policyKey)) { New-Item -Path $policyKey -Force | Out-Null }

    $entry = "$Id;$UpdateUrl"
    $existing = (Get-Item $policyKey).GetValueNames() | ForEach-Object {
        [pscustomobject]@{ Name = $_; Value = (Get-ItemProperty -Path $policyKey -Name $_).$_ }
    }

    if ($existing | Where-Object { $_.Value -eq $entry }) {
        Write-Host 'The extension is already force-installed by policy.'
        return
    }

    # Entries are numbered strings; take the next free slot rather than
    # overwriting another product's policy.
    $used = @($existing | ForEach-Object { [int]$_.Name } | Where-Object { $_ -gt 0 })
    $slot = if ($used) { ($used | Measure-Object -Maximum).Maximum + 1 } else { 1 }

    New-ItemProperty -Path $policyKey -Name "$slot" -Value $entry -PropertyType String -Force | Out-Null
    Write-Host "Force-installed the extension by policy (slot $slot). Learners cannot remove or disable it."
}

function Wait-AgentReady {
    <#
        Proves the resolver is answering, not merely that the service reports
        Running. Until this returns, no adapter has been redirected, so a failed
        start leaves the machine resolving exactly as it did before.
    #>
    param([Parameter(Mandatory)][int]$TimeoutSeconds)

    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)

    while ((Get-Date) -lt $deadline) {
        $service = Get-Service $ServiceName -ErrorAction SilentlyContinue

        if ($null -eq $service -or $service.Status -ne 'Running') {
            Start-Sleep -Seconds 1
            continue
        }

        try {
            $null = Resolve-DnsName -Name 'example.com' -Server '127.0.0.1' -DnsOnly -QuickTimeout -ErrorAction Stop
            return $true
        } catch {
            Start-Sleep -Seconds 1
        }
    }

    return $false
}

function Restore-Adapter {
    param([Parameter(Mandatory)]$Adapter)

    if ($Adapter.DnsFromDhcp) {
        Set-DnsClientServerAddress -InterfaceIndex $Adapter.InterfaceIndex -ResetServerAddresses
    } else {
        Set-DnsClientServerAddress -InterfaceIndex $Adapter.InterfaceIndex -ServerAddresses @($Adapter.ServerAddresses)
    }
}

# --------------------------------------------------------------- device identity

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

# ------------------------------------------------------------------- files

$installDirectory = Join-Path $env:ProgramFiles 'SaferNET Agent'
$dataDirectory = Join-Path $env:ProgramData 'SaferNET'
$backupPath = Join-Path $dataDirectory 'dns-backup.json'
New-Item -ItemType Directory -Force $installDirectory, $dataDirectory | Out-Null

# The original configuration is captured before anything is stopped or changed,
# so an upgrade that fails halfway still has something to restore from.
if ($ConfigureDns) { Save-DnsBackup -Path $backupPath }

if (Get-Service $ServiceName -ErrorAction SilentlyContinue) {
    Write-Host 'Upgrading the installed agent in place.'
    Stop-Service $ServiceName -Force
    sc.exe delete $ServiceName | Out-Null
    # The service manager releases the binary asynchronously.
    Start-Sleep -Seconds 2
}

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
        ListenOnIpv6 = $true
        UpstreamTimeoutSeconds = 4
        TcpIdleSeconds = 10
        MaxConcurrentQueries = 256
        PolicyRefreshSeconds = 300
        HeartbeatSeconds = 60
        PolicyStaleSeconds = 1800
        DataDirectory = $dataDirectory
    }
} | ConvertTo-Json -Depth 5
$configuration | Set-Content (Join-Path $installDirectory 'appsettings.json') -Encoding UTF8
icacls (Join-Path $installDirectory 'appsettings.json') /inheritance:r /grant:r 'SYSTEM:F' 'Administrators:F' | Out-Null

sc.exe create $ServiceName binPath= ('"' + (Join-Path $installDirectory 'SaferNet.Agent.exe') + '"') start= auto DisplayName= 'SAFERNET Endpoint Agent' | Out-Null
if ($LASTEXITCODE -ne 0) { throw "Could not register the $ServiceName service (sc.exe exit code $LASTEXITCODE)." }
sc.exe failure $ServiceName reset= 86400 actions= restart/5000/restart/15000/restart/60000 | Out-Null

Start-Service $ServiceName

if (-not (Wait-AgentReady -TimeoutSeconds $ReadinessTimeoutSeconds)) {
    throw "The agent did not answer DNS on 127.0.0.1 within $ReadinessTimeoutSeconds seconds. No adapter was changed; this machine resolves exactly as it did before. Check the Application event log, and that nothing else holds UDP port 53."
}

Write-Host 'The agent is running and resolving on 127.0.0.1.' -ForegroundColor Green

# ------------------------------------------------------- adapter redirection

if ($ConfigureDns) {
    # Roll back from the captured backup rather than from what was read a moment
    # ago: only the backup records whether an adapter should be asking DHCP.
    $backup = Get-Content $backupPath -Raw | ConvertFrom-Json
    $redirected = @()

    try {
        foreach ($adapter in Get-ManagedAdapter) {
            Set-DnsClientServerAddress -InterfaceIndex $adapter.InterfaceIndex -ServerAddresses @('127.0.0.1')
            $redirected += $adapter.InterfaceIndex
        }

        Write-Host "Redirected $($redirected.Count) adapter(s) to the local resolver."
    } catch {
        Write-Warning "Redirecting adapter DNS failed: $($_.Exception.Message). Rolling back."

        foreach ($index in $redirected) {
            $original = $backup.Adapters | Where-Object { $_.InterfaceIndex -eq $index } | Select-Object -First 1

            if ($null -eq $original) {
                Write-Warning "No captured configuration for interface $index; restore it by hand."
                continue
            }

            try {
                Restore-Adapter -Adapter $original
            } catch {
                Write-Warning "Could not roll back interface ${index}: $($_.Exception.Message). Restore it from $backupPath."
            }
        }

        throw
    }
}

if ($ForceInstallExtension) {
    if (-not $ExtensionId) {
        Write-Warning 'ForceInstallExtension was requested without an ExtensionId, so no browser policy was written. The extension remains removable by the learner.'
    } else {
        Set-ForcedExtension -Id $ExtensionId -UpdateUrl $ExtensionUpdateUrl
    }
}

Write-Host 'SAFERNET Endpoint Agent installed and running.'
