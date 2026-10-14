# SAFERNET Endpoint Agent for Windows

This .NET Windows Service provides device-wide DNS enforcement in addition to the browser extension. It downloads the authenticated school policy, keeps the last known policy on disk for offline enforcement, reports component health, and records blocked-domain security events.

Issue a device-bound token and enrol the device first:

```powershell
php artisan safernet:enroll-device 12345678 LAB-01-PC-001 --laboratory="Computer Lab"
php artisan safernet:issue-service-token 12345678 --device=101 --name=windows-agent-LAB-01-PC-001
```

Build on a Windows development workstation with .NET 10:

```powershell
.\agent\windows\build.ps1
```

Run `install.ps1` from the published folder as Administrator. Pass `-ConfigureDns` only when the workstation should use the local filter as its DNS resolver; the installer records the prior adapter DNS settings and `uninstall.ps1` restores them.

The agent configuration is stored with SYSTEM/Administrators-only ACLs. Chrome reads its device-bound token from managed registry policy; standard users have read access to that policy and cannot edit it. Treat the generated token as a credential and rotate it if a machine is lost or re-imaged.

For upgrades, run `php artisan migrate --force` before deploying the new API. Existing school-wide identities have no device binding and are rejected; issue a new token for each enrolled device ID, then rerun the installer with that token. Do not backfill a shared identity onto multiple devices.

`-ForceInstallExtension -ExtensionId <CHROME_EXTENSION_ID>` writes administrator-managed Chrome enrollment as well as force-install policy. The installer verifies the token's device and resolves the canonical asset tag before changing files or DNS. The uninstaller removes only this extension's enrollment fields and force-install entry. Restart Chrome after a policy update.

DNS block reports use a single worker and a bounded queue (256 by default), with at most three retries using the same event UUID. Overflow and exhausted retries are counted in heartbeat metadata. Filtering continues during API outages; a process restart discards unsent in-memory reports.

The installer sets Chrome `DeveloperToolsAvailability=0` to prevent debugging force-installed extensions and preserves an existing value of `2`. Its first-run backup is restored on uninstall unless the value has since changed. Verify effective Chrome policy does not grant a conflicting DevTools allowlist for SaferNET.
