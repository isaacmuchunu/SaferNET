# SaferNET Windows Endpoint Agent

## 1. Overview & Purpose

The **SaferNET Windows Endpoint Agent** (`SaferNet.Agent.exe`) is an unattended Windows Service written in native **.NET 10**. 

While browser extensions can be removed or bypassed if a learner boots an alternate browser, portable app, or terminal command, the Endpoint Agent enforces digital safeguarding policies at the **operating system network transport level**. By binding to the local loopback adapter (`127.0.0.1:53`), all outbound DNS lookups from any application on the workstation pass through the SaferNET filter before network resolution.

---

## 2. Technical Specifications

- **Target Framework**: .NET 10.0 (`net10.0`)
- **Execution Model**: Windows Background Service (`Microsoft.Extensions.Hosting.WindowsServices`)
- **Transports Supported**: UDP and TCP on port 53 (`127.0.0.1:53` and `[::1]:53`)
- **Forwarding Protocol**: Standard RFC 1035 UDP/TCP client forwarding to upstream resolvers (default: `1.1.1.1` or school gateway)
- **Process Memory Footprint**: ~35–50 MB typical working set
- **Installation Location**: `C:\Program Files\SaferNET Agent\`
- **Data & Cache Location**: `C:\ProgramData\SaferNET\`

---

## 3. Core Capabilities

### 3.1 Sub-Millisecond In-Memory Domain Matching
The agent downloads the institution's effective policy from the central API (`/api/v1/agent/policy`). 
- Domains are indexed in an optimized in-memory `HashSet<string>` with case-insensitive normalization.
- Subdomain matching uses parent-suffix hierarchy walks (e.g., a lookup for `math.games.badsite.example` automatically tests `badsite.example` and `games.badsite.example`).
- Matching domains return standard `NXDOMAIN` (or sinkhole `0.0.0.0` / `::`), rendering the prohibited host unreachable.

### 3.2 Offline Policy Resilience & Corrupt-Cache Safety
- **Durable Disk Cache**: Policies are persisted to `%ProgramData%\SaferNET\policy.json`.
- **Atomic Writes**: Policy files are written to a temporary sibling file and swapped atomically using transactional file replacement, preventing 0-byte or corrupted files during power cuts.
- **Fail-Safe Startup**: If `policy.json` contains malformed JSON or corrupted bits, the agent catches the error, archives the corrupted cache to `policy.corrupt.json`, logs the incident to the Windows Event Log, and loads an empty baseline while continuing network DNS forwarding.

### 3.3 Heartbeat & Security Telemetry Reporting
The agent periodically communicates with the central API:
- Sends component health telemetry (service status, policy version hash, installed domain count, memory consumption).
- Buffers blocked-domain attempts and forwards security telemetry to `/api/v1/agent/events` with batching and exponential backoff retry.

---

## 4. Build, Packaging & Verification

### Build from Source
Requirements: .NET 10 SDK on a Windows 10/11 x64 or ARM64 machine.

```powershell
# Run the test suite and publish single-file release package
pwsh ./agent/windows/build.ps1 -Runtime win-x64
```

The build script will:
1. Execute the entire xUnit test suite (`SaferNet.Agent.Tests`).
2. Publish a self-contained single-file executable (`SaferNet.Agent.exe`).
3. Embed official application icons and metadata (`safernet.ico`, company, copyright).
4. Verify PowerShell installation script syntax.
5. Create a standalone zip package in `agent/windows/dist/SaferNET-Agent-win-x64.zip`.

---

## 5. Installation & Lifecycle Management

### 5.1 Pre-Requisites
Before installing on a workstation, enroll the device and issue a service token from the management portal or CLI:
```bash
php artisan safernet:enroll-device <INSTITUTION-CODE> LAB-01-PC-01 --laboratory="Lab 1"
php artisan safernet:issue-service-token <INSTITUTION-CODE> --name="agent-LAB-01-PC-01"
```

### 5.2 Interactive or Automated Installation
Run PowerShell as Administrator:
```powershell
.\install.ps1 `
    -ApiBaseUrl "https://safernet.kiambu.go.ke/api/v1" `
    -ServiceToken "<GENERATED_TOKEN>" `
    -ManagedDeviceId "101" `
    -WorkstationId "LAB-01-PC-01" `
    -ConfigureDns `
    -ExtensionId "YOUR_CHROME_EXTENSION_ID" `
    -ForceInstallExtension
```

### Parameter Breakdown:
- `-ConfigureDns`: Backs up existing network adapter DNS configurations to `%ProgramData%\SaferNET\dns-backup.json` and updates adapters to use `127.0.0.1`.
- `-ForceInstallExtension`: Adds the SaferNET browser extension into the Google Chrome Group Policy key (`HKLM:\SOFTWARE\Policies\Google\Chrome\ExtensionInstallForcelist`), preventing removal by learners.

### 5.3 Uninstallation & Safe Recovery
To restore the workstation to its original network state:
```powershell
.\uninstall.ps1 -RestoreDns
```
The script stops and removes the Windows Service, deletes binaries, and faithfully restores previous static or DHCP DNS settings from `dns-backup.json`.
