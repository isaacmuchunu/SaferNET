# SaferNET Shield Browser Extension

## 1. Overview & Capabilities

**SaferNET Shield** is a Google Chrome / Chromium enterprise extension built on **Manifest V3**. It provides in-browser content inspection, policy enforcement, student session attribution, and classroom management.

Key features include:
- **Declarative Net Request (DNR)**: High-speed hardware-accelerated domain blocking.
- **Forced Strict SafeSearch**: Mandates SafeSearch parameters across major search engines.
- **Classroom Live Focus Mode**: Allows teachers to restrict the browser to specific lesson domains.
- **Student Workstation Authentication**: Learner sign-in and sign-out directly from the browser.
- **Durable Telemetry Queue**: Buffers web navigation events during connectivity outages and dispatches them reliably upon reconnection.

---

## 2. Directory Structure

```
extension/
├── manifest.json         # Manifest V3 configuration & permission grants
├── background.js         # Core background service worker
├── content.js            # Content script for client-side injection
├── popup.html / .js / .css # Quick status popup for teachers/officers
├── options.html / .js / .css # Workstation enrollment & token settings
├── blocked.html / .js / .css # Friendly block & exception request page
└── icons/                # High-resolution brand icons (16, 32, 48, 128 px)
```

---

## 3. Core Subsystems

### 3.1 Declarative Net Request (DNR) Dynamic Filtering
When synchronized with the API (`/api/v1/extension/sync`), `background.js` downloads the school's dynamic rule list:
- Converts domain rules into Chromium DNR rules with unique incremental integer IDs.
- Evaluates allowlists before blocklists so administrative exceptions take priority.
- Fallback in-memory matching verifies query parameters and path rules where DNR limits apply.

### 3.2 Forced SafeSearch
Injected web request headers and query rewrites enforce strict safe-search:
- **Google**: Appends `safe=active`
- **Bing**: Appends `adlt=strict`
- **DuckDuckGo**: Appends `kp=1`
- **YouTube**: Appends YouTube Restricted Mode headers (`YouTube-Restrict: Strict`)

### 3.3 Learner Session & PIN Authentication
Rather than requiring teachers to manually activate 40 workstations in the portal, learners can sign in directly at the terminal:
1. Learner enters their **Learner Admission Number** and **4-digit PIN**.
2. Extension sends an authenticated request to `POST /api/v1/extension/sign-in`.
3. Central API validates device assignment, verifies bcrypt PIN hash, and establishes an active `learner_sessions` record.
4. Subsequent web navigation events are attributed to the active learner.
5. On signing out (`POST /api/v1/extension/sign-out`), the session closes cleanly.

### 3.4 Resilient Offline Telemetry Queue
- Navigation telemetry is buffered in `chrome.storage.local`.
- Survives browser crashes, service worker unloads, and network drops.
- When network connectivity is re-established, telemetry events are flushed in batches with UUID deduplication.

### 3.5 Tamper Resistance via Chrome Policy
Learners cannot disable or remove the extension when deployed via enterprise policy:
- **Registry Key**: `HKLM:\SOFTWARE\Policies\Google\Chrome\ExtensionInstallForcelist`
- **Effect**: The extension icon cannot be uninstalled or disabled in `chrome://extensions`.
