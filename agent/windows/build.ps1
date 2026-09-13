param([ValidateSet('win-x64','win-arm64')][string]$Runtime = 'win-x64')

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$project = Join-Path $root 'src\SaferNet.Agent\SaferNet.Agent.csproj'
$output = Join-Path $root "dist\$Runtime"

dotnet test (Join-Path $root 'tests\SaferNet.Agent.Tests\SaferNet.Agent.Tests.csproj') -c Release
dotnet publish $project -c Release -r $Runtime --self-contained true -p:PublishSingleFile=true -p:PublishTrimmed=false -o $output
Copy-Item (Join-Path $root 'config\appsettings.example.json') (Join-Path $output 'appsettings.example.json') -Force
Copy-Item (Join-Path $root 'install.ps1') (Join-Path $output 'install.ps1') -Force
Copy-Item (Join-Path $root 'uninstall.ps1') (Join-Path $output 'uninstall.ps1') -Force
Copy-Item (Join-Path $root 'README.md') (Join-Path $output 'README.md') -Force
Compress-Archive -Path (Join-Path $output '*') -DestinationPath (Join-Path $root "dist\SaferNET-Agent-$Runtime.zip") -Force

Write-Host "Agent package: $(Join-Path $root "dist\SaferNET-Agent-$Runtime.zip")"
