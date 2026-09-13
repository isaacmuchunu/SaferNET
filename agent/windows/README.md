# SAFERNET Endpoint Agent for Windows

This .NET Windows Service provides device-wide DNS enforcement in addition to the browser extension. It downloads the authenticated school policy, keeps the last known policy on disk for offline enforcement, reports component health, and records blocked-domain security events.

Issue a school-bound token and enrol the device first:

```powershell
php artisan safernet:enroll-device 12345678 LAB-01-PC-001 --laboratory="Computer Lab"
php artisan safernet:issue-service-token 12345678 --name=windows-agent-LAB-01-PC-001
```

Build on a Windows development workstation with .NET 10:

```powershell
.\agent\windows\build.ps1
```

Run `install.ps1` from the published folder as Administrator. Pass `-ConfigureDns` only when the workstation should use the local filter as its DNS resolver; the installer records the prior adapter DNS settings and `uninstall.ps1` restores them.

The service token is stored with SYSTEM/Administrators-only ACLs. Treat the generated token as a credential and rotate it if a machine is lost or re-imaged.
