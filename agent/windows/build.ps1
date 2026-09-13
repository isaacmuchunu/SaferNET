<#
.SYNOPSIS
    Tests, publishes and packages the SAFERNET endpoint agent.

.DESCRIPTION
    `$ErrorActionPreference = 'Stop'` does not stop a native process that
    returns a non-zero exit code — dotnet failing a test run is not a
    PowerShell error — so every native call here is checked explicitly. Without
    that, a failing test suite still produced a shippable ZIP.

    Pass -SkipTests only for a local iteration; a release must run them.
#>
param(
    [ValidateSet('win-x64', 'win-arm64')][string]$Runtime = 'win-x64',
    [switch]$SkipTests
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$project = Join-Path $root 'src\SaferNet.Agent\SaferNet.Agent.csproj'
$tests = Join-Path $root 'tests\SaferNet.Agent.Tests\SaferNet.Agent.Tests.csproj'
$output = Join-Path $root "dist\$Runtime"
$package = Join-Path $root "dist\SaferNET-Agent-$Runtime.zip"

function Invoke-Native {
    <#
        Runs a native command and fails the build if it reports failure. This is
        the whole point of the script: packaging must be unreachable from a
        failed test or publish.
    #>
    param(
        [Parameter(Mandatory)][string]$Description,
        [Parameter(Mandatory)][scriptblock]$Command
    )

    Write-Host "==> $Description" -ForegroundColor Cyan
    & $Command

    if ($LASTEXITCODE -ne 0) {
        throw "$Description failed with exit code $LASTEXITCODE. No package was produced."
    }
}

if ($SkipTests) {
    Write-Warning 'Skipping the agent test suite. Do not ship a package built this way.'
} else {
    Invoke-Native -Description 'Agent test suite' -Command { dotnet test $tests -c Release --nologo }
}

# A stale publish directory can leave a removed file in the package.
if (Test-Path $output) { Remove-Item -LiteralPath $output -Recurse -Force }

Invoke-Native -Description "Publish ($Runtime)" -Command {
    dotnet publish $project -c Release -r $Runtime --self-contained true -p:PublishSingleFile=true -p:PublishTrimmed=false -o $output --nologo
}

$binary = Join-Path $output 'SaferNet.Agent.exe'
if (-not (Test-Path $binary)) { throw "The publish produced no SaferNet.Agent.exe in $output." }

foreach ($asset in @('config\appsettings.example.json', 'install.ps1', 'uninstall.ps1', 'README.md')) {
    $source = Join-Path $root $asset
    if (-not (Test-Path $source)) { throw "Packaging asset '$asset' is missing." }
    Copy-Item $source (Join-Path $output (Split-Path $asset -Leaf)) -Force
}

# The installer is what redirects a school's DNS; shipping one that cannot parse
# would be discovered on a workstation rather than here.
foreach ($script in @('install.ps1', 'uninstall.ps1')) {
    $errors = $null
    [void][System.Management.Automation.Language.Parser]::ParseFile((Join-Path $output $script), [ref]$null, [ref]$errors)
    if ($errors) { throw "$script has $($errors.Count) parse error(s); refusing to package." }
}

Compress-Archive -Path (Join-Path $output '*') -DestinationPath $package -Force

Write-Host "Agent package: $package" -ForegroundColor Green
