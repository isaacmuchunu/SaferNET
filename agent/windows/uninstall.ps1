<#
.SYNOPSIS
    Removes the SAFERNET endpoint agent and restores the original DNS configuration.

.DESCRIPTION
    DNS is restored before the resolver is stopped, so the machine never sits
    pointed at a resolver that is no longer running. Adapters are matched by
    their interface GUID, which survives a rename, and an adapter that no longer
    exists is reported rather than failing the uninstall — a removed dock or
    disabled Wi-Fi card must not leave the agent installed.

    The backup is kept when any adapter could not be restored, so a second
    attempt still has something to work from.
#>
param([switch]$KeepConfiguration)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$ServiceName = 'SaferNetAgent'

$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = [Security.Principal.WindowsPrincipal]::new($identity)
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { throw 'Run this uninstaller as Administrator.' }

$dataDirectory = Join-Path $env:ProgramData 'SaferNET'
$backupPath = Join-Path $dataDirectory 'dns-backup.json'

function Resolve-InterfaceIndex {
    <#
        Finds the adapter a backup entry describes. The GUID is authoritative
        because it survives a rename; the recorded alias and index are only
        fallbacks, and either may now belong to a different adapter.
    #>
    param([Parameter(Mandatory)]$Adapter)

    if ($Adapter.PSObject.Properties.Name -contains 'InterfaceGuid' -and $Adapter.InterfaceGuid) {
        $matched = Get-NetAdapter -ErrorAction SilentlyContinue |
            Where-Object { $_.InterfaceGuid -eq $Adapter.InterfaceGuid } |
            Select-Object -First 1

        if ($matched) { return $matched.InterfaceIndex }
    }

    if ($Adapter.PSObject.Properties.Name -contains 'InterfaceAlias' -and $Adapter.InterfaceAlias) {
        $matched = Get-NetAdapter -Name $Adapter.InterfaceAlias -ErrorAction SilentlyContinue | Select-Object -First 1
        if ($matched) { return $matched.InterfaceIndex }
    }

    if (Get-NetAdapter -InterfaceIndex $Adapter.InterfaceIndex -ErrorAction SilentlyContinue) {
        return $Adapter.InterfaceIndex
    }

    return $null
}

$unrestored = 0

if (Test-Path $backupPath) {
    $backup = Get-Content $backupPath -Raw | ConvertFrom-Json

    # Accepts both the current shape and a flat array written by an older installer.
    $adapters = if ($backup.PSObject.Properties.Name -contains 'Adapters') { @($backup.Adapters) } else { @($backup) }

    foreach ($adapter in $adapters) {
        $index = Resolve-InterfaceIndex -Adapter $adapter

        if ($null -eq $index) {
            Write-Warning "Adapter '$($adapter.InterfaceAlias)' is no longer present; skipping its DNS restore."
            continue
        }

        try {
            $fromDhcp = $adapter.PSObject.Properties.Name -contains 'DnsFromDhcp' -and $adapter.DnsFromDhcp

            if ($fromDhcp) {
                # Putting the addresses back would leave it statically configured
                # forever; it has to be handed back to DHCP.
                Set-DnsClientServerAddress -InterfaceIndex $index -ResetServerAddresses
                Write-Host "Restored '$($adapter.InterfaceAlias)' to DHCP-supplied DNS."
            } else {
                Set-DnsClientServerAddress -InterfaceIndex $index -ServerAddresses @($adapter.ServerAddresses)
                Write-Host "Restored '$($adapter.InterfaceAlias)' to $($adapter.ServerAddresses -join ', ')."
            }
        } catch {
            $unrestored++
            Write-Warning "Could not restore '$($adapter.InterfaceAlias)': $($_.Exception.Message)"
        }
    }
} else {
    Write-Warning "No DNS backup at $backupPath. Any adapter left pointing at 127.0.0.1 must be reset by hand."
}

if (Get-Service $ServiceName -ErrorAction SilentlyContinue) {
    Stop-Service $ServiceName -Force
    sc.exe delete $ServiceName | Out-Null
    Start-Sleep -Seconds 2
}

Remove-Item -LiteralPath (Join-Path $env:ProgramFiles 'SaferNET Agent') -Recurse -Force -ErrorAction SilentlyContinue

if ($unrestored -gt 0) {
    Write-Warning "$unrestored adapter(s) were not restored, so $backupPath has been kept. Re-run this uninstaller once they are available."
} elseif (-not $KeepConfiguration) {
    Remove-Item -LiteralPath $dataDirectory -Recurse -Force -ErrorAction SilentlyContinue
}

Write-Host 'SAFERNET Endpoint Agent uninstalled.'
