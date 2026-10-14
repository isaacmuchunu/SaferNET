$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$root = Split-Path -Parent $PSScriptRoot
$install = [System.Management.Automation.Language.Parser]::ParseFile((Join-Path $root 'agent/windows/install.ps1'), [ref]$null, [ref]$null)
$uninstall = [System.Management.Automation.Language.Parser]::ParseFile((Join-Path $root 'agent/windows/uninstall.ps1'), [ref]$null, [ref]$null)
$installBlock = $install.FindAll({ param($node)
    $node -is [System.Management.Automation.Language.IfStatementAst] -and $node.Clauses[0].Item1.Extent.Text -eq '$ForceInstallExtension'
}, $true) | Select-Object -Last 1
$removeBlock = $uninstall.FindAll({ param($node)
    $node -is [System.Management.Automation.Language.IfStatementAst] -and $node.Extent.Text.StartsWith('if (Test-Path -LiteralPath $browserEnrollment)')
}, $true) | Select-Object -First 1

$script:MockRegistry = @{}
$script:MockFiles = @{}
function Test-Path { param($LiteralPath, $Path) $target = if ($LiteralPath) { $LiteralPath } else { $Path }; return $script:MockRegistry.ContainsKey($target) -or $script:MockFiles.ContainsKey($target) }
function Get-Item {
    param($LiteralPath, $ErrorAction)
    if (-not $script:MockRegistry.ContainsKey($LiteralPath)) { return $null }
    $key = [pscustomobject]@{ Location = $LiteralPath }
    $key | Add-Member ScriptMethod GetValue { param($name, $fallback = $null) if ($script:MockRegistry[$this.Location].ContainsKey($name)) { return $script:MockRegistry[$this.Location][$name] }; return $fallback }
    $key | Add-Member ScriptMethod GetValueNames { return @($script:MockRegistry[$this.Location].Keys) }
    return $key
}
function New-Item {
    param($Path, [switch]$Force)
    if ($script:MockRegistry.ContainsKey($Path)) { throw 'Existing policy key must not be recreated' }
    $script:MockRegistry[$Path] = @{}
}
function New-ItemProperty { param($Path, $Name, $Value, $PropertyType, [switch]$Force) $script:MockRegistry[$Path][$Name] = $Value }
function Remove-ItemProperty { param($LiteralPath, $Name, $ErrorAction) if ($script:MockRegistry.ContainsKey($LiteralPath)) { $script:MockRegistry[$LiteralPath].Remove($Name) } }
function Get-Content { param($LiteralPath, [switch]$Raw) return $script:MockFiles[$LiteralPath] }
function Set-Content { param($LiteralPath, [Parameter(ValueFromPipeline)]$Value) process { $script:MockFiles[$LiteralPath] = $Value } }
function Set-ForcedExtension { param($Id, $UpdateUrl) $script:ForcedId = $Id }
function Set-Acl {
    param($LiteralPath, $AclObject)
    if ($LiteralPath.StartsWith('HKLM:')) { $script:ManagedAcl = $AclObject }
    else { $script:BackupAcl = $AclObject }
}
function Assert-Equal { param($Actual, $Expected, $Message) if ($Actual -cne $Expected) { throw "$Message (actual=$Actual expected=$Expected)" } }

$ForceInstallExtension = $true
$ExtensionId = 'a' * 32
$ExtensionUpdateUrl = 'https://clients2.google.com/service/update2/crx'
$ApiBaseUrl = 'https://school.test/api/v1/'
$ServiceToken = 'secret-test-token'
$deviceIdInt = 7
$WorkstationId = 'LAB-7'
$dataDirectory = 'D:\MockSaferNET'
$browserEnrollment = Join-Path $dataDirectory 'browser-enrollment.json'
$chrome = 'HKLM:\SOFTWARE\Policies\Google\Chrome'
$managedPolicy = "$chrome\3rdparty\extensions\$ExtensionId\policy"
$forceList = "$chrome\ExtensionInstallForcelist"

foreach ($original in @($null, 0, 1, 2)) {
    $script:MockRegistry = @{ $chrome = @{ OtherPolicy = 'keep' } }
    if ($null -ne $original) { $script:MockRegistry[$chrome]['DeveloperToolsAvailability'] = $original }
    $script:MockFiles = @{}
    & ([scriptblock]::Create($installBlock.Clauses[0].Item2.Extent.Text.TrimStart('{').TrimEnd('}')))
    $expected = if ($original -eq 2) { 2 } else { 0 }
    Assert-Equal $script:MockRegistry[$chrome]['DeveloperToolsAvailability'] $expected 'Debugging policy'
    Assert-Equal $script:MockRegistry[$chrome]['OtherPolicy'] 'keep' 'Unrelated Chrome policy'
    Assert-Equal $script:MockRegistry[$managedPolicy]['apiBaseUrl'] 'https://school.test/api/v1' 'API URL'
    Assert-Equal $script:MockRegistry[$managedPolicy]['device_id'] 7 'Device enrollment'
    Assert-Equal $script:MockRegistry[$managedPolicy]['workstation_id'] 'LAB-7' 'Workstation enrollment'
    Assert-Equal $script:MockRegistry[$managedPolicy]['token'] 'secret-test-token' 'Managed token'
    Assert-Equal $script:ForcedId $ExtensionId 'Force installation'
    Assert-Equal $script:ManagedAcl.AreAccessRulesProtected $true 'Managed policy ACL inheritance disabled'
    $readRule = @($script:ManagedAcl.GetAccessRules($true, $false, [Security.Principal.SecurityIdentifier]) | Where-Object { $_.IdentityReference.Value -eq 'S-1-5-11' })
    Assert-Equal $readRule.Count 1 'Authenticated user access rule'
    Assert-Equal $readRule[0].RegistryRights ([Security.AccessControl.RegistryRights]::ReadKey) 'Learners only have read access'
    Assert-Equal $script:BackupAcl.AreAccessRulesProtected $true 'Uninstall backup is protected'
    Assert-Equal @($script:BackupAcl.GetAccessRules($true, $false, [Security.Principal.SecurityIdentifier])).Count 2 'Only SYSTEM and administrators access the backup'
    if ($script:MockFiles[$browserEnrollment].Contains($ServiceToken)) { throw 'Backup must not contain the token' }

    # An upgrade keeps the first backup and does not recreate existing keys.
    & ([scriptblock]::Create($installBlock.Clauses[0].Item2.Extent.Text.TrimStart('{').TrimEnd('}')))
    $script:MockRegistry[$forceList] = @{ '1' = "$ExtensionId;update"; '2' = 'other-extension;update' }
    & ([scriptblock]::Create($removeBlock.Extent.Text))
    Assert-Equal $script:MockRegistry[$chrome]['OtherPolicy'] 'keep' 'Unrelated policy on uninstall'
    Assert-Equal $script:MockRegistry[$forceList]['2'] 'other-extension;update' 'Unrelated force-install entry'
    Assert-Equal $script:MockRegistry[$forceList].ContainsKey('1') $false 'Owned force-install entry removed'
    Assert-Equal $script:MockRegistry[$managedPolicy].ContainsKey('token') $false 'Managed token removed'
    if ($null -eq $original) {
        Assert-Equal $script:MockRegistry[$chrome].ContainsKey('DeveloperToolsAvailability') $false 'Absent debugging policy restored'
    } else {
        Assert-Equal $script:MockRegistry[$chrome]['DeveloperToolsAvailability'] $original 'Original debugging policy restored'
    }
}
'Installer enrollment, upgrade and uninstall regression checks passed (four policy states; registry and filesystem mocked).'
