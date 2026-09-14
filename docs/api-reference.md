# SaferNET API Reference Specification

## 1. Architectural Overview & Conventions

All endpoints are versioned under `/api/v1` and communicate using standard JSON (`application/json`).

### 1.1 Authentication & Authorization Schemes
- **Sanctum User Token (`abilities: ["portal:access"]`)**:
  - Issued to human operators (County Directors `cde`, Sub-County Directors `scde`, Heads of Institution `hoi`, Classroom Managers `clm`).
  - Scoped by tenant context (`tenant` middleware) and role-based policies.
  - Automatically logged to the central immutable audit log via `audit.api` middleware on all state mutations.
- **Service Token (`abilities: ["telemetry:write"]`)**:
  - Issued to endpoints (Windows Agent service, Chrome Browser Extension).
  - Bound to an approved school (`institution.approved` middleware).
  - Restricted strictly to telemetry ingestion, policy synchronization, and workstation session binding.
- **Onboarding Temporary Token (`abilities: ["onboarding:password"]` or `["onboarding:mfa"]`)**:
  - Short-lived token issued during initial account activation or forced password reset.

### 1.2 Global Rate Limiting & Throttling
| Limiter Name | Limit | Target Endpoints | Purpose |
|---|---|---|---|
| `login` | 5 attempts / min | `POST /auth/login` | Prevents portal credential brute-force |
| `mfa` | 5 attempts / min | `POST /auth/mfa/*` | Prevents TOTP guessing |
| `telemetry` | 600 requests / min | Machine endpoints | Allows high-frequency client heartbeats |
| `workstation-signin` | 5 attempts / 5 min | `POST /extension/sign-in` | Prevents student 4-digit PIN brute-forcing |
| `api` | 120 requests / min | General portal APIs | Prevents portal denial-of-service |

---

## 2. Authentication & Session Management

### `POST /api/v1/auth/login`
- **Purpose**: Authenticates portal operators using email and password.
- **Middleware**: `throttle:login`
- **Request Body**:
  ```json
  {
    "email": "officer@kiambu.education.go.ke",
    "password": "SecurePassword123!"
  }
  ```
- **Responses**:
  - `200 OK`: Successful login. Returns bearer token and user profile if MFA is satisfied or not required.
  - `200 OK (MFA Challenge)`: Returns a temporary token with `abilities: ["onboarding:mfa"]` and `mfa_required: true`.
  - `422 Unprocessable Content`: Invalid credentials or inactive account status.
  - `429 Too Many Requests`: Rate limit exceeded.

### `GET /api/v1/auth/context`
- **Purpose**: Retrieves the active authenticated session context, tenant institution, user permissions, and office scope.
- **Middleware**: `auth:sanctum`, `account.active`, `tenant`
- **Response**: Returns current user model, role, permissions list, and institution metadata.

### `DELETE /api/v1/auth/logout`
- **Purpose**: Revokes the current Sanctum bearer token and terminates the portal session.

### `POST /api/v1/auth/onboarding/password`
- **Purpose**: Forces a newly invited or reset user to replace their temporary one-time password with a strong permanent password.
- **Middleware**: `abilities:onboarding:password`, `throttle:mfa`

### `POST /api/v1/auth/onboarding/mfa/setup` & `confirm`
- **Purpose**: Generates a standard RFC 6238 TOTP QR code / secret and confirms the first code verification to enable two-factor authentication.

### `POST /api/v1/auth/mfa/verify`
- **Purpose**: Verifies a 6-digit TOTP code during multi-factor challenge and promotes the session to full `portal:access`.

### `GET /api/v1/auth/sessions` & `DELETE /api/v1/auth/sessions/{id}`
- **Purpose**: Lists all active web/browser login sessions for the officer and allows revoking remote sessions.

---

## 3. Machine Telemetry & Client Synchronization (Agent & Extension)

### `GET /api/v1/extension/sync`
- **Purpose**: Primary policy synchronization endpoint for the **SaferNET Shield Chrome Extension**.
- **Middleware**: `abilities:telemetry:write`, `institution.approved`, `throttle:telemetry`
- **Query Parameters**: `workstation_id` (optional), `revision` (optional)
- **Response Details**:
  - `content_hash`: Cryptographic SHA-256 hash of the effective policy.
  - `revision`: Incremental policy version integer.
  - `blocked_domains`: Normalized, deduplicated domain rules with dynamic DNR rule IDs.
  - `blocked_categories`: Active restricted categories (e.g., gambling, adult, extremist, social-media).
  - `allowed_domains`: Administrative exception domains that supersede county blocklists.
  - `delivery`: Object containing `limit`, `installed`, and `complete` (boolean indicating if client capacity truncated rules).

### `GET /api/v1/agent/policy`
- **Purpose**: Authoritative policy download endpoint for the **Windows DNS Endpoint Agent**.
- **Middleware**: `abilities:telemetry:write`, `institution.approved`, `throttle:telemetry`
- **Response Details**:
  - Delivers complete, uncapped domain blocklist tailored for system-level hash-table lookups.
  - Returns `content_hash` and strict wildcard domain hierarchies.

### `GET /api/v1/agent/resolve-device`
- **Purpose**: Maps a workstation asset tag (e.g., `LAB1-WS03`) to its primary key `device_id` during automated agent installation.
- **Parameters**: `?identifier=LAB1-WS03`
- **Response**: `{ "data": { "id": 42, "asset_tag": "LAB1-WS03", "laboratory_id": 2 } }`

### `GET /api/v1/extension/commands`
- **Purpose**: Polls real-time classroom control directives broadcast by teachers (such as focus mode locking, push URL, or learner nudges).

### `GET /api/v1/extension/session`
- **Purpose**: Resolves the active learner session assigned to the workstation, allowing the browser extension to automatically discover who is logged in without manual configuration.

### `POST /api/v1/extension/sign-in`
- **Purpose**: Authenticates a student directly at the workstation terminal.
- **Middleware**: `throttle:workstation-signin`
- **Request Body**:
  ```json
  {
    "workstation_id": "LAB1-WS03",
    "learner_number": "ST-2026-0891",
    "pin": "4821"
  }
  ```
- **Security Logic**:
  - Checks learner enrollment against device laboratory assignment.
  - Validates PIN against bcrypt hash (`pin_hash`).
  - Supersedes any previous learner session at this workstation.
  - Returns unified error messages to prevent learner number enumeration.
  - Never logs or echoes the PIN payload in audit trails.

### `POST /api/v1/extension/sign-out`
- **Purpose**: Ends the current student session at the workstation without requiring a PIN.
- **Request Body**: `{ "workstation_id": "LAB1-WS03" }`

### `POST /api/v1/extension/heartbeat`
- **Purpose**: Periodically reports extension health, installed DNR rules count, and telemetry queue size.

### `POST /api/v1/extension/exception-requests`
- **Purpose**: Allows a student or teacher from the block screen (`blocked.html`) to submit a temporary exception request for an educational website.

### `POST /api/v1/web-events`
- **Purpose**: Batch ingestion of learner browsing telemetry from the browser extension.
- **Payload**: Array of web navigation events containing `event_uuid`, `url`, `domain`, `action` (`allow` / `block`), `occurred_at`, `learner_session_id`. Idempotent by `event_uuid`.

### `POST /api/v1/security-events`
- **Purpose**: Ingestion of DNS-layer security blocks from the Windows Endpoint Agent.
- **Payload**: Blocked DNS lookup attempts, client process metadata, and sinkholed timestamps.

### `POST /api/v1/protection-components`
- **Purpose**: Registers and updates endpoint service telemetry (`component_type`: `agent` or `extension`, version, memory usage, OS version).

### `POST /api/v1/filtering/assess`
- **Purpose**: Queries the backend AI content classifier to evaluate a URL/domain and return risk scores, category predictions, and recommended enforcement actions.

---

## 4. Officer Portal & Administration APIs

### 4.1 Dashboard, Reports & Analytics
- `GET /api/v1/dashboard`: High-level county or school statistics (active learners, protected devices, blocked attempts, policy status).
- `GET /api/v1/reports/protection-summary`: Aggregated breakdown of web traffic by category, severity, and compliance rate.
- `GET /api/v1/reports/incident-trend`: Time-series incident volume for trending analysis.

### 4.2 School Roster & Workstation Management
- `GET|POST /api/v1/institutions`: Lists or provisions schools across Kiambu sub-counties.
- `POST /api/v1/institutions/{id}/reviews`: CDE approval workflow to authorize or suspend an institution on the platform.
- `GET|POST /api/v1/laboratories`: Manages computer labs / classrooms within a school.
- `GET|POST /api/v1/devices`: Manages workstation hardware inventory and asset tags.
- `POST /api/v1/devices/{device}/assignments`: Assigns a workstation to specific learners or classes.
- `POST /api/v1/devices/{device}/sessions`: Allows an authorized teacher to manually initiate or override a student workstation session.
- `GET|POST /api/v1/learners`: Manages student rosters, admission identifiers, and enrollment status.

### 4.3 Classroom Live Supervision
- `GET /api/v1/classrooms/live`: Returns a real-time grid of all workstations in a laboratory, showing active learner name, current web domain, focus state, and protection health.
- `POST /api/v1/classrooms/push-url`: Remotely opens a designated curriculum URL on all lab workstations.
- `POST /api/v1/classrooms/focus-mode`: Restricts browsing across all laboratory machines to designated educational domains.
- `POST /api/v1/classrooms/nudge`: Dispatches an attention reminder message to a specific workstation screen.

### 4.4 Policy Authoring & Exception Management
- `GET|POST|PUT /api/v1/filtering-policies`: Authors county baselines or school custom policies.
- `GET|POST|PUT|DELETE /api/v1/filtering-policies/{id}/rules`: Manages specific allow or block rules within a policy.
- `GET|PUT /api/v1/blocklist-sources`: Configures external threat intelligence and URL blocklist sources.
- `POST /api/v1/blocklist-sources/{id}/sync`: Triggers immediate background synchronization of a blocklist feed.
- `GET /api/v1/exception-requests`: Lists student/teacher exception requests.
- `POST /api/v1/exception-requests/{id}/reviews`: Approves (with expiration window) or rejects an exception request.

### 4.5 Incidents & Legal Evidence Dossiers
- `GET|PUT /api/v1/incidents`: Investigates high-severity safeguarding incidents (e.g. repeated attempts to access illegal or violent content).
- `GET /api/v1/incidents/{id}/report`: Generates a tamper-evident, court-ready PDF safeguarding dossier.
- `POST /api/v1/incidents/{id}/actions`: Records formal disciplinary or safeguarding interventions taken by school heads.

### 4.6 County Domain Triage (AI-Assisted Governance)
- `GET /api/v1/domain-reviews`: Displays the queue of newly discovered domains visited by learners that lack definitive category classifications.
- `PUT /api/v1/domain-reviews/{id}`: Enables CDE / SCDE officers to make a binding county-wide ruling (`blocked` or `allowed`), assign content categories, and push updates to all schools.

### 4.7 Governance, Audit & Integrations
- `GET /api/v1/audit-logs`: Searchable, tamper-evident audit trail capturing every administrative mutation.
- `GET /api/v1/integrations`: Operational status of external integrations (NEMIS sync, county mail servers, SMS gateways).
- `GET /api/v1/protection-components`: Fleet-wide health view of all installed agents and browser extensions.
