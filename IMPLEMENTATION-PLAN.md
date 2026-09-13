# Implementation plan — from reviewed to pilot-ready

**Companion to:** `ROLLOUT-READINESS.md` (why the work exists)
**Baseline:** branch `review/production-readiness`
**Target:** clear all eight production-pilot exit checks in `findings.md`

---

## How this plan is ordered

By **risk retired per unit of effort**, not by area. The agent and extension
are written and reviewed but never observed running; the fastest way to
retire risk is to move them from *reviewed* to *observed*, not to write more
code.

Two consequences worth stating up front:

- **Fix B1 before the VM work.** It is an hour, and it changes agent startup
  behaviour — testing the unfixed version wastes the VM session.
- **Write the documentation after the VM and browser work**, not before.
  Documentation written ahead of observation records intent; written after,
  it records behaviour. Only the second kind is usable by an operator.

| Phase | Outcome | Effort | Readiness |
| --- | --- | --- | --- |
| 0 | Green baseline, defect closed | ~2 h | ~50% |
| 1 | Agent observed on real Windows | ~1 d | ~65% |
| 2 | Extension observed in real Chrome | ~0.5 d | ~75% |
| 3 | Product decisions + documentation | ~2 d | ~85% |
| 4 | Measurement and test infrastructure | ~1.5 d | — |
| 5 | External reviews | external | pilot-ready |

Phases 0–2 are about a week's work and retire most of the risk. Phases 4 and
5 can run in parallel with 3.

---

## Phase 0 — Green baseline (~2 hours) — ✅ COMPLETE (2026-09-13)

| Step | State | Evidence |
| --- | --- | --- |
| 0.1 Corrupt-cache restart loop | ✅ Done | `566e519`; agent suite **27 passing** (was 24) |
| 0.2 Enable `gd` locally | ✅ Done | PHP suite **144 passing, 0 failing** (was 141 + 3 failing) |
| 0.3 CI green | ⏳ Blocked | Needs the PR opened — see note below |

**0.3 note.** `.github/workflows/ci.yml` triggers on `pull_request` and on
push to `main`. Pushing the branch alone does not start it, so CI cannot run
until the PR exists. The branch is pushed and ready at
`review/production-readiness`; the description is written. Opening the PR is
a one-click action under the repository owner's GitHub identity.

### 0.1 Close the corrupt-cache restart loop

**Problem.** `PolicyStore.LoadAsync` has no error handling and is called at
`AgentWorker.cs:25`, outside any `try`. A malformed `policy.json` throws
`JsonException` out of `ExecuteAsync`; Windows recovery restarts the service,
it reads the same file, and loops — a machine that boots with no filtering
and no obvious cause.

**Change.** `agent/windows/src/SaferNet.Agent/PolicyStore.cs`:

```csharp
public async Task LoadAsync(CancellationToken cancellationToken)
{
    if (!File.Exists(_path)) return;

    try
    {
        await using var stream = File.OpenRead(_path);
        var policy = await JsonSerializer.DeserializeAsync<FilterPolicy>(stream, cancellationToken: cancellationToken);
        _current = policy?.Normalised() ?? FilterPolicy.Empty;
    }
    catch (Exception exception) when (exception is JsonException or IOException)
    {
        // An unreadable cache must not stop the agent starting: it would
        // restart into the same file forever and filter nothing. Discard it
        // and let the first policy cycle fetch a fresh one.
        logger.LogError(exception, "The cached policy at {Path} is unreadable and has been discarded", _path);
        _current = FilterPolicy.Empty;
        TryDelete();
    }
}
```

`PolicyStore` takes no logger today — add `ILogger<PolicyStore>` to its
primary constructor (it is registered as a singleton in `Program.cs`, so DI
supplies it).

**Acceptance.** ✅ Met. Three tests added to `PolicyStoreTests.cs`:

- a truncated cache → loads `FilterPolicy.Empty`, does not throw
- a cache of binary garbage → same, and the file is deleted afterwards
- an agent whose cache was discarded still applies the next policy it fetches

`PolicyStore` gained an `ILogger<PolicyStore>`; it is a DI singleton, so the
host supplies it. Test call sites pass `NullLogger`.

```
dotnet test agent/windows/tests/SaferNet.Agent.Tests --nologo
# Passed! - Failed: 0, Passed: 27
```

**Effort.** 1 hour (actual: as estimated). **Blocks:** Phase 1 — now unblocked.

### 0.2 Enable `gd` locally

Three tests failed with *"The PHP GD extension is required"*. `php_gd.dll`
already shipped with the winget PHP 8.4 build; only the `php.ini` line was
commented out.

Enabled `extension=gd` in
`…/WinGet/Packages/PHP.PHP.8.4_…/php.ini` (backed up alongside first).

**Acceptance.** ✅ Met — `php artisan test` reports **144 passing, 0 failing**.
This confirms all three failures were environmental, as reported, and none
masked a real regression.

**Effort.** 5 minutes (actual: as estimated).

### 0.3 Get CI green

Open the PR and let `.github/workflows/ci.yml` run for the first time. Expect
first-run failures — a missing extension, a runner image detail, a path.

**Acceptance.** All three jobs green; the `safernet-agent-win-x64` artifact
is produced.

**Effort.** 1 hour, mostly waiting. **Depends on:** 0.1, 0.2.

---

## Phase 1 — Observe the agent on real Windows (~1 day)

A disposable Windows 11 VM, snapshotted clean before each scenario. Build the
package first:

```powershell
./agent/windows/build.ps1 -Runtime win-x64
```

### 1.1 Installer scenario matrix

Restore the clean snapshot between every row.

| # | Scenario | Must be true afterwards |
| --- | --- | --- |
| 1 | First install, `-ConfigureDns`, DHCP adapter | Backup records real servers and `DnsFromDhcp = true`; adapter points at `127.0.0.1`; name resolution works |
| 2 | First install, static DNS adapter | Backup records the static servers and `DnsFromDhcp = false` |
| 3 | **Failed start** — occupy UDP 53 first | Install throws; **no adapter changed**; machine resolves exactly as before |
| 4 | Upgrade in place (re-run installer) | Original backup preserved, not replaced; `CapturedAt` unchanged |
| 5 | Add a second adapter, then re-run | New adapter *added* to the backup; existing entries untouched |
| 6 | Uninstall after 1 | Adapter returns to **DHCP**, not static |
| 7 | Uninstall after 2 | Adapter returns to its original static servers |
| 8 | Remove an adapter, then uninstall | Skipped with a warning; backup retained; uninstall completes |
| 9 | Two adapters, one fails mid-redirect | Both rolled back from the backup |

Scenario 3 is the one that matters most: it is the difference between a
failed install and an unusable workstation.

### 1.2 Resolver behaviour against real clients

With the agent running and adapters redirected:

```powershell
Resolve-DnsName example.com -Server 127.0.0.1
Resolve-DnsName <a-blocked-domain> -Server 127.0.0.1   # expect NXDOMAIN, fast
nslookup -type=AAAA example.com 127.0.0.1
nslookup -vc example.com 127.0.0.1                      # forces TCP
```

Then browse normally and confirm a blocked domain fails immediately rather
than hanging. Check the Application event log for the bound-endpoint line —
it must list **both** `udp/127.0.0.1:53` and `tcp/127.0.0.1:53`.

**Acceptance.** Every row of 1.1 passes; blocked names return NXDOMAIN within
a second over both transports; a browser is genuinely filtered.

### 1.3 Health reporting end to end

- Stop the service → console shows the component **offline** within the
  configured threshold, not healthy.
- Occupy port 53, restart → the service does not sit reporting healthy.
- Block the API (firewall rule), leave the agent running → filtering
  continues on cache; health degrades; `policy_synced_at` stops advancing.

**Acceptance.** No configuration produces a healthy status without a working
resolver and a recent policy.

**Effort.** 1 day. **Depends on:** 0.1.

---

## Phase 2 — Observe the extension in real Chrome (~0.5 day)

Local API, real Chromium, extension loaded unpacked. Prepare a school:

```bash
php artisan safernet:enroll-device <NEMIS-CODE> LAB-A-07 --laboratory="Lab A"
php artisan safernet:issue-service-token <NEMIS-CODE>
```

| # | Scenario | Must be true |
| --- | --- | --- |
| 1 | Enrol with the session field **blank** | Session resolves on its own; learner name appears |
| 2 | Change the learner in the portal | Attribution follows within one poll (~30 s) |
| 3 | Visit a blocked domain | Redirect is immediate; block page renders |
| 4 | Make the API unreachable, repeat 3 | Redirect still immediate — enforcement does not wait on telemetry |
| 5 | Browse offline, then restore the network | Queued events arrive **once**, attributed to the right learner |
| 6 | Reload the worker with a non-empty queue | Nothing lost — this is the property the queue exists for |
| 7 | Sign learner A out, sign B in, then restore the network | A's queued events land on **A**, never on B |
| 8 | Approve an exception for a subdomain of a blocked parent | Access granted; expire it → blocking resumes |
| 9 | Lock focus mode from the portal | Browser confined to the origin; release lifts it |
| 10 | Seed >4,500 domains | `delivery.complete = false` recorded; no DNR rule-limit error |

Scenario 7 is the cloud-review regression and deserves explicit sign-off —
one learner's browsing on another's record is a safeguarding failure, not a
reporting bug.

**Acceptance.** All ten pass with the options page's session field left blank
throughout.

**Effort.** 0.5 day. **Depends on:** 0.3.

---

## Phase 3 — Decisions and documentation (~2 days)

### 3.1 Two product decisions (do these first — they change what gets written)

**C3 — how does a learner session start?** Today an officer starts it in the
portal. In a lab of 40 learners changing every period that is the likeliest
reason attribution goes stale.

| Option | Effort | Note |
| --- | --- | --- |
| Accept the portal flow | 0 | Document as a start-of-lesson teacher step |
| Sign-in page in the extension | 2–3 d | Posts to the existing `devices/{device}/sessions` |
| PIN kiosk on the workstation | 1 w | Best UX, most work |

**C2 — what is the classifier's status?** `POST filtering/assess` exists and
no client calls it. Either integrate it (a project) or document it as an
advisory backend capability. Until then the product must not be described as
doing URL or content-level assessment — it does DNS-domain and
browser-domain filtering.

### 3.2 Documentation (F13 — the one unaddressed finding)

Three deliverables:

1. **Root `README.md`** — replace the Laravel template. Product summary,
   architecture, supported platforms (**Windows and Chromium only** — do not
   imply others exist), local setup, the `safernet:*` commands, the
   `safernet:doctor` readiness check.
2. **`extension/README.md`** — new. Install, enrol, configure, the session
   model, what happens offline, how to read `telemetry_dropped`.
3. **`docs/deployment.md`** — the end-to-end operator guide: provision a
   school, enrol devices, issue tokens, install both clients, associate a
   learner, verify a block and an exception, handle an outage, upgrade,
   uninstall.

**Acceptance** (the review's own wording): *a new operator can deploy an
isolated test school, enrol a device, install both clients, associate a
learner, verify a block and an exception, test an outage, and uninstall —
using only the documented steps.*

Test it by having someone who did not write the code follow it on a clean VM.

**Effort.** 2 days. **Depends on:** Phases 1 and 2, so it documents observed
behaviour.

---

## Phase 4 — Measurement and test infrastructure (~1.5 days)

### 4.1 Load and propagation measurement

Agree targets **before** measuring, or the numbers mean nothing. Suggested:

| Measure | Proposed target |
| --- | --- |
| Classroom endpoint, 48 tiles, 20 concurrent pollers | p95 < 300 ms |
| Rows read per classroom poll | Constant in session history |
| Policy change → enforced on a client | < 5 min (browser), < 10 min (agent) |
| DNS resolution added latency | p95 < 15 ms over upstream |
| DNS under 500 queries/s burst | No dropped queries, stable memory |

Seed with `KiambuCountySeeder` extended to a realistic day of `web_events`.

### 4.2 Frontend and extension tests (needs dependency approval)

`CLAUDE.md` requires approval before adding dependencies. Vitest + ESLint are
new ones — hence the gap. On approval, cover the logic that is already pure
and would test cleanly:

- `sessionFor()` — attribution rules including the unattributable case
- queue retry, backoff, the 4xx-permanent rule, overflow accounting
- allowlist-beats-blocklist in `onBeforeNavigate`
- focus-state handling from a `commands` payload

Add `npm test` to the `frontend` CI job.

**Effort.** 1 day (4.1) + 0.5 day (4.2).

---

## Phase 5 — External gates

Neither is engineering work; both are prerequisites for real learner data.

- **E1 Security review.** Scope to include this branch: the service-token
  session lookup, policy delivery, telemetry ingestion. `findings.md` is
  explicit that it provides no security assurance.
- **E2 Privacy / compliance review.** The system attributes browsing to named
  children by admission number. A privacy impact assessment covering
  retention, access control, lawful basis, parental transparency and
  subject-access handling must complete **before any real learner data is
  processed**.

> E2 is a gate, not a task. A pilot with real learners cannot proceed
> without it regardless of engineering readiness.

---

## Definition of done

The plan is complete when all eight exit checks in `findings.md` pass:

- [ ] One policy fixture agrees across assessment, Windows DNS and the browser
- [ ] Changes, exceptions and expiries propagate within the measured target
- [ ] Listener loss, stale policy and failed sync produce accurate non-healthy states
- [ ] Offline, first-install-without-connectivity and corrupt-cache behaviour agreed and tested
- [ ] Enrolment, learner change, retry, incident and reporting work end to end
- [ ] Install failure and repeat upgrades cannot destroy DNS or block uninstall
- [ ] Supported versions pass automated and real-environment tests; load meets targets
- [ ] Security and privacy reviews complete

---

## Risks to the plan itself

| Risk | Mitigation |
| --- | --- |
| No Windows VM available | Phase 1 is the highest-value work and cannot be substituted. Secure the VM before starting. |
| Phase 2 finds a service-worker lifecycle problem | Most likely single source of rework; budget a contingency day. |
| Dependency approval for 4.2 refused | Phases 0–3 are unaffected; extension logic stays covered only by review. |
| Documentation written before Phases 1–2 | Records intent rather than behaviour and will need rewriting. Hold the order. |
| Security review returns findings late | Start E1 in parallel with Phase 3, not after it. |
