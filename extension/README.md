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
2. Configure workstation credentials in options.html:
   - **API Base URL**: https://safernet.kiambu.go.ke/api/v1
   - **Workstation Tag**: e.g., LAB1-WS03
   - **Service Token**: Scoped school service token

For complete architecture and developer details, see [Extension Documentation](../docs/extension.md).
