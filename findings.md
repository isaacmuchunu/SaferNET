# SaferNET repository review

**Review date:** 2026-09-13  
**Reviewed baseline:** [`08bafb7c2f23a95c8c86568271b4af7a495ea352`](https://gitlab.com/the-cmmc-guide-group/SaferNET/-/commit/08bafb7c2f23a95c8c86568271b4af7a495ea352) on `main`  
**Intended product:** K–12 web filtering with an installable endpoint agent and browser extension.

## Executive assessment

SaferNET contains a substantial management application and working-shaped endpoint implementations, rather than just a UI prototype. However, the reviewed code does **not yet establish production readiness**. The main blockers are inconsistent effective-policy behavior, silently incomplete domain distribution, unreliable deployment-health signals, and gaps in agent lifecycle and client telemetry handling.

Prioritize a verifiable end-to-end filtering contract over additional dashboard features. A school policy change should produce a traceable policy revision, reach both clients, change actual browsing behavior, and appear accurately in deployment status and learner reporting.

This is a **static architecture, functionality, reliability, and maintainability review**, not a vulnerability audit or compliance certification. For a dedicated security review, select **Security Analyst Agent** using the new Chat icon. No security assurance is implied here.

## Scope and limitations

The repository tree was enumerated across all five returned pages. Source inspection focused on the policy controller and advisor, blocklist synchronization, Windows agent source and installation scripts, extension JavaScript and manifest, classroom APIs, event ingestion, health reporting, selected portal files, dependency manifests, and relevant tests. Not every application file was read; large files were read selectively.

No shell, browser, Windows host, database, or test runner was available in this review session. Builds, tests, DNS behavior, browser rule installation, deployment behavior, accessibility, and load performance were **not executed or measured**. Findings below identify observable code behavior and specify validation work; they do not claim reproduced runtime failures. Evidence links are repository-relative, and the reviewed baseline above identifies the source snapshot.

Only this report is added; application code and deployment configuration are unchanged.

## Architecture observed

| Component | Implementation found | Assessment |
| --- | --- | --- |
| Management/API | Laravel 13, PHP 8.4+, PostgreSQL requirement; schools, learners, devices, policies, incidents, exceptions and reporting | Broad management foundation |
| Officer portal | React 19, React Router 7, TanStack Query, Vite 7, Tailwind 4 | Shared API and component patterns; needs runtime validation |
| Browser extension | Manifest V3, dynamic request rules, classroom polling, telemetry, options and block page | Implemented, but policy/session integration is incomplete |
| Endpoint agent | .NET 10 Windows service; loopback UDP DNS filtering, disk policy cache, heartbeat and block-event reporting | Windows-only implementation with lifecycle and DNS limitations |
| Distribution | PowerShell build/install/uninstall; x64 and ARM64 ZIP output | Script-based installation exists; fleet upgrade behavior is not established |
| Classification | Backend domain advisor and provider-fallback classifier | Advisory API exists; shipped clients do not call it |
| Tests | PHP feature suites and one Windows policy-store test | Backend test foundation; client and end-to-end validation gaps |

### Existing strengths

- The agent and extension share the policy endpoint implementation: `agent/policy` and `extension/sync` both resolve to `ExtensionController::sync` in [routes/api.php](routes/api.php).
- [PolicyStore.cs](agent/windows/src/SaferNet.Agent/PolicyStore.cs) persists through a temporary file replacement and supports parent-domain matching. Extension synchronization retains existing dynamic rules when the API fetch fails.
- [BlocklistSynchroniser.php](app/Services/Blocklists/BlocklistSynchroniser.php) uses checksums and a database transaction when replacing a source, and preserves existing entries on fetch failure.
- [RecordWebEvent.php](app/Actions/Filtering/RecordWebEvent.php) includes event-UUID deduplication, session validation, transactional recording, and incident processing.
- PHP tests cover numerous backend workflows. The reviewed [ClassroomAndExtensionTest.php](tests/Feature/Api/V1/ClassroomAndExtensionTest.php) checks policy response structure, heartbeat persistence, classroom broadcasting and device resolution. Test presence is not evidence that these tests currently pass.

## Prioritized findings

Priorities are delivery priorities, not vulnerability severity ratings: **P1** = address before a production pilot; **P2** = address before scaling or broad rollout.

### F01 — P1: Both clients receive only a truncated domain list

**Evidence:** [ExtensionController.php](app/Http/Controllers/Api/V1/ExtensionController.php), `sync`, orders `BlockedDomain` records and applies `limit(4500)` **before** normalization and deduplication. [routes/api.php](routes/api.php) reuses this response for the Windows agent.

**Impact:** Once the database exceeds 4,500 rows, later domains are omitted with no completeness indicator. Duplicate domains from different sources consume the row budget. The Windows agent inherits a browser-oriented cap even though its hash-set policy is not a browser ruleset.

**Recommendation:** Separate effective policy from client-specific delivery. Distribute a complete agent policy and an explicitly capacity-managed browser policy. Include total/installed counts, completeness, content hash and revision; do not silently treat a partial policy as complete.

**Acceptance check:** Seed more than 4,500 unique domains plus cross-source duplicates. Verify the final alphabetic domain is enforced by the intended layer and any client capacity limitation is visible.

### F02 — P1: Policy editing and delivered enforcement have different semantics

**Evidence:** [ExtensionController.php](app/Http/Controllers/Api/V1/ExtensionController.php) blocks every selected domain, regardless of source enabled state or category action. Its `blocked_categories` field is descriptive; all generated domain rules use `block`. It does not apply learner-group selection. [DomainAdvisor.php](app/Services/Filtering/DomainAdvisor.php), in contrast, filters enabled sources and resolves category actions. [BlocklistSourceController.php](app/Http/Controllers/Api/V1/BlocklistSourceController.php) changes `is_enabled` without deleting domains; [BlockedDomain.php](app/Models/BlockedDomain.php) has no automatic enabled-source scope.

**Impact:** Disabling a source or changing a category action can produce different answers between assessment and actual clients. Selecting one newest institution policy is not sufficient to express group-specific effective policy.

**Recommendation:** Implement one effective-policy resolver with explicitly documented precedence for county, institution, group and category rules. Have assessment and client compilation consume that same result. Decide which settings each client supports rather than returning unenforced metadata.

**Acceptance check:** Run shared fixtures through assessment, agent policy and browser compilation for enabled/disabled sources, school overrides, multiple active versions and learner-group rules; verify consistent intended actions.

### F03 — P1: Approved exceptions and allowlist metadata do not change filtering

**Evidence:** [ExceptionRequestReviewController.php](app/Http/Controllers/Api/V1/ExceptionRequestReviewController.php) records a decision and expiry. The policy compiler and [DomainAdvisor.php](app/Services/Filtering/DomainAdvisor.php) do not consult approved exceptions. The compiler returns a fixed `allowed_domains` array, but [background.js](extension/background.js) only stores it, and [PolicyStore.cs](agent/windows/src/SaferNet.Agent/PolicyStore.cs) represents blocked domains only.

**Impact:** Approval in the portal can leave the requested site blocked. Returned allowlist metadata does not establish precedence over a block.

**Recommendation:** Define exception scope and expiry semantics, compile approved exceptions into both client policies, and advance the effective revision when an exception starts or expires. If an exception cannot override a particular baseline rule, show that explicitly during review.

**Acceptance check:** Approve a blocked-domain exception, sync both clients, confirm the intended access, then expire it and confirm blocking resumes.

### F04 — P1: A failed DNS listener is not supervised by the agent worker

**Evidence:** [AgentWorker.cs](agent/windows/src/SaferNet.Agent/AgentWorker.cs) starts `dns.RunAsync` and awaits its task only after the periodic loop. [DnsFilter.cs](agent/windows/src/SaferNet.Agent/DnsFilter.cs) can fault while binding or receiving. [SaferNetApiClient.cs](agent/windows/src/SaferNet.Agent/SaferNetApiClient.cs) sends `health_status = "healthy"` without checking listener state.

**Impact:** A listener fault, such as a port conflict, can coexist with a continuing heartbeat loop reporting healthy. Windows service recovery is not a substitute for observing the failed task.

**Recommendation:** Supervise the listener and periodic tasks together, propagate fatal listener failures or explicitly recover, and derive health from successful DNS readiness and current policy state. Define clean cancellation and task-draining behavior.

**Acceptance check:** Occupy the configured DNS port, then simulate a listener failure after startup. Verify the service cannot remain healthy without a functioning resolver.

### F05 — P1: DNS installation and restoration are not transactional or upgrade-safe

**Evidence:** [install.ps1](agent/windows/install.ps1) changes adapter DNS before `Start-Service` and has no rollback around startup failure. Each `-ConfigureDns` run overwrites `dns-backup.json`. [uninstall.ps1](agent/windows/uninstall.ps1) restores the saved address array without preserving whether the original configuration came from DHCP.

**Impact:** A failed start can leave adapters pointed at a non-working local resolver. Reinstalling after DNS was already changed can overwrite the original backup with `127.0.0.1`, preventing correct restoration. Restoring addresses alone does not restore DHCP configuration mode.

**Recommendation:** Preserve the first known-good configuration and its mode; validate service readiness before committing adapter changes; roll back on error. Make install, upgrade and uninstall idempotent and handle removed/renamed adapters.

**Acceptance check:** Test first install, failed service startup, second install, upgrade, uninstall, DHCP and static DNS, multiple adapters, and an adapter removed after installation.

### F06 — P1: DNS implementation supports only a narrow transport path

**Evidence:** [DnsFilter.cs](agent/windows/src/SaferNet.Agent/DnsFilter.cs) listens only on IPv4 loopback UDP, forwards only over UDP, and has no TCP listener or upstream TCP fallback. It launches unbounded per-request tasks and does not send a defined DNS failure response on upstream timeout. Installation configures existing IPv4 adapter settings only.

**Impact:** Clients requiring DNS over TCP or truncated-response fallback are unsupported. Timeout behavior and burst handling are not controlled. This implementation should not be described as comprehensive device-wide filtering without a validated supported-network matrix. AAAA queries carried over IPv4 UDP are distinct from listening on IPv6.

**Recommendation:** Define supported DNS transports and adapter lifecycle behavior; implement TCP handling/fallback, bounded concurrency and explicit timeout responses. Document how other resolution paths are handled as a separate deployment requirement.

**Acceptance check:** Exercise A/AAAA queries, truncated upstream responses, TCP queries, upstream outage, IPv6 configuration, new adapters and sustained bursts. Measure latency, memory and recovery.

### F07 — P1: Health reporting confuses contact with successful enforcement

**Evidence:** [ExtensionController.php](app/Http/Controllers/Api/V1/ExtensionController.php), `heartbeat`, sets both `health_status = healthy` and `policy_synced_at = now()` on every heartbeat. [background.js](extension/background.js) sends no last-successful-sync timestamp or applied revision. The agent also sends healthy unconditionally. [DashboardController.php](app/Http/Controllers/Api/V1/DashboardController.php) tallies stored health values. The reviewed [ProtectionComponent.php](app/Models/ProtectionComponent.php) and [routes/console.php](routes/console.php) provide no age-based health transition.

**Impact:** A browser whose synchronization repeatedly fails can appear freshly synchronized. Previously healthy devices can continue contributing to healthy totals after they stop checking in. [DeploymentPage.jsx](resources/js/pages/DeploymentPage.jsx) also displays “All healthy” when there are no degraded/offline entries, including an empty inventory.

**Recommendation:** Separate last contact, last successful policy installation, applied revision, resolver readiness and effective health. Derive stale/offline state from timestamps, and show empty/unknown states explicitly.

**Acceptance check:** Fail sync while heartbeats succeed, stop a client beyond its freshness threshold, and inspect an empty deployment. None should imply verified healthy enforcement.

### F08 — P1: Learner telemetry requires manual session wiring and has no durable delivery

**Evidence:** [options.js](extension/options.js) accepts a manually entered `learner_session_id`; [background.js](extension/background.js), `sendTelemetry`, returns without sending when it is absent. Failed sends only set `last_error`; there is no durable event queue or retry. [content.js](extension/content.js) sends `PAGE_VIEW_TELEMETRY`, but the background message listener has no handler for that action. Tab activation/completion provide a separate, partial telemetry path. [RecordWebEvent.php](app/Actions/Filtering/RecordWebEvent.php) rejects ended sessions.

**Impact:** Filtering can operate while learner reporting remains empty or stops after session turnover. Network interruptions lose events. The agent reports device-level DNS events, not a replacement for learner web-session attribution.

**Recommendation:** Establish an explicit device-to-current-session lookup or provisioning flow. Reconcile session changes automatically. Persist events with stable UUIDs, bounded retries and backoff; define behavior for events arriving after session closure. Remove or integrate the unused content-script message.

**Acceptance check:** Enroll without manually copying a session ID, change learners, interrupt the network, restart the extension worker and recover. Verify correct attribution and deduplicated delivery.

### F09 — P2: Navigation handling waits for telemetry before redirecting

**Evidence:** [background.js](extension/background.js), `onBeforeNavigate`, awaits `sendTelemetry` before applying focus-mode or custom block-page navigation. `apiFetch` has no explicit request timeout. The navigation listener is not itself a synchronous network-blocking mechanism.

**Impact:** Slow telemetry delays the user-facing redirect and focus behavior. Dynamic rules independently block matching domains, so this is not a claim that every blocked request is allowed; the custom block page and focus flow still lack a reliable timing contract.

**Recommendation:** Decouple event delivery from enforcement. Use browser-supported request rules for behavior that must precede network access, and treat the custom block page as a separately tested presentation path.

**Acceptance check:** Make the API slow/unreachable while navigating to a blocked domain and away from the focus origin. Verify deterministic behavior without waiting for event upload.

### F10 — P2: Focus lock depends on an unrelated cached URL command

**Evidence:** [ClassroomLiveController.php](app/Http/Controllers/Api/V1/ClassroomLiveController.php), `focusMode`, stores only a lock boolean. [background.js](extension/background.js) derives `focus_url` from a current `CLASSROOM_PUSH_URL` command or a prior local value, and clears it when unlocked. Navigation restrictions require both a true lock and a valid focus URL.

**Impact:** Locking without a retained push-URL command can display a locked classroom while imposing no focus-origin restriction. Commands also occupy a single cache slot per scope, so a newer nudge can replace a URL command before a client polls it.

**Recommendation:** Represent focus as explicit state with target, revision and expiry, independent of transient messages. Distinguish command acceptance from endpoint acknowledgement.

**Acceptance check:** Lock without a preceding URL push, lock after command expiry, issue a nudge between push and poll, restart the worker, and unlock/relock. The UI and browser must agree.

### F11 — P2: Live classroom polling loads full event histories

**Evidence:** [ClassroomLiveController.php](app/Http/Controllers/Api/V1/ClassroomLiveController.php), `index`, loads all events for up to 48 active sessions, then keeps only the latest event per session in PHP. [ClassroomLivePage.jsx](resources/js/pages/ClassroomLivePage.jsx) requests refreshes every eight seconds when enabled.

**Impact:** Read volume and memory grow with session history rather than the number of visible tiles. Forty-eight sessions limit the tile count, not the number of event rows read.

**Recommendation:** Select the latest event per session in SQL using a suitable relationship, subquery or PostgreSQL query strategy. Verify supporting indexes with query plans and define a bounded aggregation window where appropriate.

**Acceptance check:** Populate long-running sessions with realistic event volumes and measure query count, rows read, memory and response latency during concurrent dashboard polling.

### F12 — P2: Automated release validation is incomplete

**Evidence:** [package.json](package.json) defines only development/build scripts, with no frontend test or lint command. No extension tests or repository CI configuration appear in the enumerated tree. [PolicyStoreTests.cs](agent/windows/tests/SaferNet.Agent.Tests/PolicyStoreTests.cs) contains one matching test and does not reload the on-disk cache. [build.ps1](agent/windows/build.ps1) invokes native `dotnet` commands without checking `$LASTEXITCODE`; `$ErrorActionPreference = 'Stop'` alone is not a portable guarantee that native failures stop packaging. [phpunit.xml](phpunit.xml) forces local PostgreSQL connection settings.

**Impact:** The repository does not demonstrate repeatable validation across the three deliverables. On affected PowerShell configurations, packaging can continue after a native test/build failure. Fixed database settings complicate isolated test environments.

**Recommendation:** Add reproducible PHP/PostgreSQL, frontend, extension and Windows validation. Explicitly check native exit codes; test cache reload/corruption, agent API contracts, installation recovery and browser lifecycle. Supply isolated database settings through the test environment. External automation may exist, but was not established by this review.

**Acceptance check:** A deliberately failing test or publish command must prevent package output; clean supported environments must reproduce validated artifacts.

### F13 — P2: Product documentation and capability boundaries are incomplete

**Evidence:** [README.md](README.md) remains the Laravel template. [agent/windows/README.md](agent/windows/README.md) gives a short agent build/enrollment overview. The tree contains only a Windows agent, with no extension-specific README or end-to-end deployment guide. [DomainAdvisor.php](app/Services/Filtering/DomainAdvisor.php) and [ContentClassifier.php](app/Services/Ai/ContentClassifier.php) implement backend assessment, but the inspected agent and extension do not call `filtering/assess`.

**Impact:** Operators cannot infer supported platforms, browser versions, required runtime services, learner-session setup, offline expectations, upgrade procedures or what classification actually participates in enforcement. DNS-domain filtering must not be confused with full URL/content assessment.

**Recommendation:** Replace the root template with product-specific setup and support boundaries. Document Windows and supported Chromium browsers first, rather than implying other platforms exist. Describe the advisory classifier as such until a tested client integration is implemented. Define first-run behavior with no cached policy separately from offline operation with a valid cache.

**Acceptance check:** A new operator can deploy an isolated test school, enroll a device, install both clients, associate a learner, verify a block and exception, test an outage, and uninstall using only documented steps.

## Recommended implementation sequence

1. **Unify policy:** resolve F01–F03 with a shared effective-policy contract and cross-client fixtures. Make revision and completeness observable.
2. **Make the agent dependable:** resolve F04–F06 and test Windows installation, recovery, DNS transports and uninstall in disposable VMs.
3. **Make reporting trustworthy:** resolve F07–F10 with real health, automatic session association, durable events and explicit focus state.
4. **Prepare rollout:** address F11–F13 with performance validation, repeatable build/test automation and operator documentation.

## Production-pilot exit checks

- The same policy fixture produces intended behavior in assessment, Windows DNS and the supported browser, with differences explicitly documented.
- Policy changes, exceptions and expirations propagate within a measured target and expose the actually installed revision.
- Loss of the DNS listener, missing/stale policy, failed sync and missed heartbeats produce accurate non-healthy states.
- Offline operation, first installation without connectivity and corrupt cache recovery have agreed, tested behavior.
- Enrollment, learner changes, event retry, incident creation and reporting work end to end without manually copying session identifiers.
- Installation failure and repeated upgrades cannot destroy the original DNS configuration or prevent correct uninstall.
- Supported Windows/browser versions pass automated and real-environment tests; load measurements meet agreed targets.
- A separate specialist security review and appropriate privacy/compliance review are completed before handling real learner data. This report does not substitute for either.