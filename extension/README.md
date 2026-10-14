# SaferNET Shield (K-12 Browser Filtering Extension)

Official Kiambu County K-12 learner internet safety, dynamic policy enforcement, and classroom telemetry Chrome/Chromium extension (Manifest V3).

## Features
- **Declarative Net Request (DNR)**: High-speed hardware-accelerated domain blocking.
- **Strict SafeSearch Enforcement**: Automatically forces SafeSearch across Google, Bing, DuckDuckGo, and YouTube.
- **Classroom Live Focus Mode**: Allows teachers to restrict browsing to designated educational domains during lessons.
- **Workstation PIN Authentication**: Learners authenticate using their student number and 4-digit PIN.
- **Resilient Telemetry Queue**: Buffers events in chrome.storage.local during internet outages.

## Installation & Configuration
1. Load unpacked in Chrome from chrome://extensions or force-install via Windows Policy:
   HKLM:\SOFTWARE\Policies\Google\Chrome\ExtensionInstallForcelist
2. Enroll a device, then issue a device-bound service token with
   `php artisan safernet:issue-service-token <NEMIS_CODE> --device=<DEVICE_ID>`.
3. Run the Windows installer as Administrator with `-ForceInstallExtension -ExtensionId <CHROME_EXTENSION_ID>`.
   It writes `apiBaseUrl`, `token`, `device_id`, and `workstation_id` to
   `HKLM:\SOFTWARE\Policies\Google\Chrome\3rdparty\extensions\<CHROME_EXTENSION_ID>\policy`.
   Force-installed extension debugging is disabled while normal page debugging remains available (an existing stricter setting is preserved).
   The managed schema is `managed-schema.json`; the options page shows enrollment without exposing or editing the token.
   HTTPS is required except for loopback development APIs.
4. On upgrade, apply the additive database migration and replace existing school-wide tokens with one token per enrolled device.
   There is no fallback to editable local settings. Missing enrollment or invalid policy responses retain the previous rules and report an error.
5. Verify the extension's managed policy in `chrome://policy`, reload Chrome, and confirm policy sync and the enrolled device's session lookup.

For complete architecture and developer details, see [Extension Documentation](../docs/extension.md).
