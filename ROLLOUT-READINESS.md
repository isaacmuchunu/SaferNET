# Rollout readiness — what is left, and why

**Date:** 2026-09-13
**Branch:** `review/production-readiness` (3 commits, 56 files)
**Baseline:** the static review in `findings.md` (F01–F13)

---

## The short answer

Twelve of the thirteen review findings are closed in code. The system is
still not complete, for three different kinds of reason, and it is worth
keeping them apart because they need very different work:

| Kind | Meaning | Share of what's left |
| --- | --- | --- |
| **Unverified** | Written and reviewed, never executed where it will run | Most of it |
| **Known open** | A defect identified and deliberately not yet fixed | Small, cheap |
| **Not built** | Never started — documentation, and two product decisions | Moderate |

The honest estimate is **~40% ready for a production pilot**, split as:

- **Implementation against the review: ~85%**
- **Evidence it works on a school device: ~20%**

The second number is the one that gates a pilot. Nothing below is a
criticism of the code; it is a statement about what has and has not been
*observed*.

> These are judgement estimates from the state of the repository, not
> measurements.

---

## Scorecard — the review's own exit checks

These are the eight checks `findings.md` set as the bar for a production
pilot. They are the definition of "complete" being used here.

| # | Exit check | State |
| --- | --- | --- |
| 1 | One policy fixture agrees across assessment, Windows DNS and the browser | Backend proven by tests; **never run against real DNS or a real browser** |
| 2 | Policy changes, exceptions and expiries propagate within a measured target | Implemented and exposed; **propagation never measured** |
| 3 | Listener loss, stale policy, failed sync produce accurate non-healthy states | Implemented and unit-tested; **not observed on a device** |
| 4 | Offline, first install without connectivity, corrupt cache recovery | **Partly done, one known defect open** (§B1) |
| 5 | Enrolment, learner change, retry, incident, reporting work end to end | Implemented; **never run end to end** |
| 6 | Install failure and repeat upgrades cannot destroy DNS or block uninstall | Implemented; **never executed** (§A1) |
| 7 | Supported versions pass real-environment tests; load measured | **CI written but never run; zero load measurement** |
| 8 | Security review and privacy/compliance review completed | **Not started** (§E) |

Two of eight are substantially met. That is the arithmetic behind the 40%.

---

## A. Unverified — written, reviewed, never executed

This is the bulk of the risk, and it is concentrated in the code with the
worst failure mode: a mistake here takes a school off the network, rather
than showing a wrong number on a dashboard.

### A1. The Windows installer has never been run

**What.** `agent/windows/install.ps1` and `uninstall.ps1` were substantially
rewritten for F05 and again after the cloud review. They parse cleanly and
the logic has been reviewed line by line. Neither has ever been executed.

**Why it matters.** These scripts repoint every adapter's DNS at
`127.0.0.1`. The failure mode of a bug is a machine with no working DNS —
not a degraded feature, an unusable workstation. The rollback path, the
DHCP-vs-static detection (read from the interface registry key) and the
adapter-merge logic on reinstall are all unexercised.

**How to address.** One disposable Windows VM, snapshotted between runs:

1. First install with `-ConfigureDns`; confirm the backup captures real
   servers and the DHCP flag per adapter.
2. Force a failed start (occupy UDP 53 first); confirm **no adapter was
   touched** and the machine still resolves.
3. Upgrade in place; confirm the original backup is preserved, not replaced.
4. Add a second adapter (USB NIC or VPN), re-run install; confirm the new
   adapter is *added* to the backup.
5. Uninstall; confirm DHCP adapters return to DHCP and static ones to their
   original servers.
6. Remove an adapter, then uninstall; confirm it is skipped with a warning
   and the backup is retained.

**Effort.** Half a day with a VM to hand. **This is the single highest-value
remaining task.**

### A2. The browser extension has never been loaded in Chrome

**What.** `extension/background.js` was heavily reworked: a durable
telemetry queue, automatic learner-session resolution, allowlist precedence,
focus state, a new heartbeat payload. It passes `node --check` and the
manifest references are verified, but nothing has run in a browser.

**Why it matters.** Service-worker lifecycle is the part most likely to
behave differently from how it reads. The queue is specifically designed to
survive worker teardown, and that property is unproven. `chrome.storage`
semantics, alarm timing and `declarativeNetRequest` rule limits are all
assumed.

**How to address.** Load unpacked against a local API:

1. Enrol a workstation; confirm the learner session resolves with the
   options field left blank.
2. Change the learner in the portal; confirm attribution follows within a
   poll.
3. Pull the network mid-browsing, restore it; confirm queued events arrive
   once, with correct attribution.
4. Restart the service worker (`chrome://extensions` → reload) with a
   non-empty queue; confirm nothing is lost.
5. Visit a blocked domain; confirm the redirect is immediate and does not
   wait on the API. Make the API unreachable and repeat.
6. Approve an exception for a subdomain of a blocked parent; confirm access.

**Effort.** Half a day. Second-highest value.

### A3. DNS has never served a real client

**What.** The resolver is covered by 24 tests over real sockets on an
ephemeral port — NXDOMAIN over UDP and TCP, SERVFAIL on upstream failure,
malformed packets, port conflicts, idle TCP, connection reuse.

**Why it matters.** Those tests drive it with hand-built packets from the
same process. They do not prove a Windows stub resolver, Chrome's built-in
resolver, or `nslookup` are satisfied by the responses — particularly the
locally-generated NXDOMAIN and SERVFAIL, which are trimmed to the question
section.

**How to address.** On the same VM as A1: `nslookup`, `Resolve-DnsName`,
`dig +tcp`, a browser, and an A/AAAA/TXT sweep against `127.0.0.1`. Confirm
a blocked name fails fast rather than hanging.

**Effort.** Two hours, combined with A1.

### A4. CI has never run

**What.** `.github/workflows/ci.yml` defines three jobs — Laravel against
PostgreSQL 17, portal + extension checks, Windows agent tests plus a real
package build. It has never executed, because this branch was only just
pushed.

**Why it matters.** An untested workflow usually fails on its first run
(missing extension, wrong runner image, a path). Until it runs green it
provides no assurance.

**How to address.** Open the PR and let it run; fix what breaks. Note the
workflow installs `gd`, which should clear the three local failures in §F.

**Effort.** An hour, mostly waiting.

---

## B. Known open defects

### B1. A corrupt policy cache stops the agent from starting

**Evidence.** `PolicyStore.LoadAsync` deserialises `policy.json` with no
error handling, and `AgentWorker.ExecuteAsync` calls it on line 25 —
outside any `try`:

```csharp
// PolicyStore.cs
public async Task LoadAsync(CancellationToken cancellationToken)
{
    if (!File.Exists(_path)) return;
    await using var stream = File.OpenRead(_path);
    var policy = await JsonSerializer.DeserializeAsync<FilterPolicy>(stream, ...);
    _current = policy?.Normalised() ?? FilterPolicy.Empty;
}
```

A malformed file throws `JsonException` straight out of `ExecuteAsync`, so
the service fails to start. Windows recovery restarts it, it reads the same
corrupt file, and it fails again — a restart loop with no filtering at all.

**Why it is still open.** F12 explicitly asked for cache reload/corruption
coverage. I implemented the reload half and did not do the corruption half.
This is an acknowledged omission, not an oversight discovered later.

**Why it matters.** `ReplaceAsync` writes to a temp file and moves, so a
torn write is unlikely — but disk corruption, a full volume, or an
interrupted antivirus scan are not. The consequence is a lab of machines
that boot with no filtering and no obvious cause.

**How to address.** Catch `JsonException`/`IOException` in `LoadAsync`, log
loudly, delete the unreadable file and continue from `FilterPolicy.Empty` —
the agent then fetches a fresh policy on its first cycle. Add two tests: a
truncated file and a file of garbage, both asserting the store loads empty
rather than throwing.

**Effort.** One hour. **Do this before the VM testing**, so A1 exercises the
fixed version.

### B2. Three local test failures

**Evidence.** `IncidentPdfReportTest` and both `UserAvatarUploadTest` cases
fail with *"The PHP GD extension is required, but is not installed"*;
`php -m` confirms `gd` is absent locally.

**Why it matters.** Only that it masks real regressions — a developer
learns to ignore three red tests, which is how a fourth gets ignored too.

**How to address.** Enable `extension=gd` in the local `php.ini`. CI already
installs it.

**Effort.** Five minutes.

---

## C. Not built

### C1. F13 — product documentation (the one unaddressed finding)

**Evidence.** `README.md` is still the stock Laravel template, opening with
the Laravel logo and framework links. `agent/windows/README.md` is 20 lines.
There is no extension README and no end-to-end deployment guide.

**Why it matters.** This is what stops *someone else* running a pilot. An
operator cannot currently determine supported platforms, required runtime
services, how a learner session begins, what happens offline, how to upgrade,
or what the classifier actually does. Every deployment needs whoever wrote
the code.

**How to address.** Replace the root README with product setup and support
boundaries; add an extension README; write one end-to-end deployment guide.
The review set the acceptance test: *a new operator can deploy an isolated
test school, enrol a device, install both clients, associate a learner,
verify a block and an exception, test an outage, and uninstall — using only
the documented steps.*

Document Windows and supported Chromium browsers only. Do not imply other
platforms exist.

**Effort.** One to two days, and it should be written *after* A1 and A2, so
it documents observed behaviour rather than intended behaviour.

### C2. The classifier is advisory and unused by any client

**Evidence.** `POST filtering/assess` is routed to
`FilteringAssessmentController`; neither the extension nor the agent calls
it. `DomainAdvisor` and `ContentClassifier` are reachable only from the
portal.

**Why it matters.** Not a defect — but it is easy to believe the product
does AI content classification at the endpoint. It does not. Enforcement is
DNS-domain and browser-domain filtering; the classifier advises on unknown
domains when the portal asks.

**How to address.** A product decision, not a bug fix. Either integrate it
into a client with its own tests, or document it explicitly as an advisory
backend capability. Until then, avoid describing the product as doing URL or
content-level assessment.

**Effort.** Documentation: an hour. Integration: a project.

### C3. A learner cannot start their own session at the workstation

**Evidence.** Sessions are created by `POST devices/{device}/sessions`,
called from the officer portal (`DevicesPage`). Neither client calls it.

**Why it matters.** F08's goal — attribution without copying session
identifiers — is met: the extension resolves the open session on its own.
But *somebody in the portal* must still start that session. In a lab of 40
learners changing every period, that is a real operational cost and the most
likely reason attribution goes stale in practice.

**How to address.** A product decision. Options: a workstation sign-in page
in the extension that posts to the existing endpoint; a PIN kiosk; or accept
the portal flow and document it as a teacher's start-of-lesson step.

**Effort.** Decision first. Extension sign-in: two to three days.

---

## D. Missing validation infrastructure

### D1. No frontend or extension tests

**What.** `npm run check` parses every extension script and verifies the
manifest references real files, then builds the portal. That catches syntax
errors and broken references — nothing about behaviour.

**Why not done.** `CLAUDE.md` requires approval before changing
dependencies, and a test runner (Vitest) plus a linter (ESLint) are new
dependencies. This was deliberately left for a decision.

**How to address.** Approve Vitest + ESLint, then cover the logic worth
testing: the telemetry queue's retry/backoff and attribution rules, the
allowlist-beats-blocklist check, and focus-state handling. These are pure
functions today and would test cleanly.

**Effort.** Half a day to set up, then ongoing.

### D2. No load or performance measurement

**What.** F11's query fix is proven *structurally* — one `DISTINCT ON`
query, constant query count regardless of history. Nothing has been measured
under realistic volume.

**Why it matters.** The review asked for measured targets: rows read, memory
and latency with concurrent dashboard polling, and DNS latency under
sustained bursts. "It is one query now" is not the same as "it is fast
enough for 48 tiles polling every 8 seconds across 400 schools."

**How to address.** Seed a realistic dataset (400 institutions, a full day
of events), then measure the classroom endpoint under concurrent polling and
the DNS resolver under a burst. Agree targets before measuring, so the
numbers mean something.

**Effort.** One day.

---

## E. Hard gates — no amount of code clears these

### E1. Specialist security review

`findings.md` is explicit that it is *not* a vulnerability audit and implies
no security assurance. This branch changes authentication-adjacent surfaces
(a new unauthenticated-shaped session-lookup endpoint behind a service
token, policy delivery, telemetry ingestion) and none of it has had a
security review.

**How to address.** Commission the dedicated security review the report
recommends, scoped to include this branch.

### E2. Privacy and compliance review

The system records the browsing of named children and attributes it to them
by name and admission number. That is among the most sensitive categories of
personal data, and it is processed under Kenyan data-protection law.

**How to address.** A privacy impact assessment covering retention, access
control, the lawful basis, parental transparency and subject-access
handling — completed **before any real learner data is processed**, not
before general release.

> This is a gate, not a task. A pilot with real learners cannot proceed
> without it regardless of engineering readiness.

---

## Sequenced plan

Ordered by risk retired per unit of effort.

| # | Task | Ref | Effort | Gets to |
| --- | --- | --- | --- | --- |
| 1 | Fix the corrupt-cache restart loop | B1 | 1 h | |
| 2 | Enable `gd` locally | B2 | 5 min | |
| 3 | Open the PR; get CI green | A4 | 1 h | **~50%** |
| 4 | Installer + DNS on a disposable VM | A1, A3 | 1 d | **~65%** |
| 5 | Extension in real Chrome, end to end | A2 | 0.5 d | **~75%** |
| 6 | Decide C2 and C3; write F13 docs | C1–C3 | 2 d | **~85%** |
| 7 | Load and propagation measurement | D2 | 1 d | |
| 8 | Frontend/extension tests (needs approval) | D1 | 0.5 d | |
| 9 | Security review | E1 | external | |
| 10 | Privacy/compliance review | E2 | external | **pilot-ready** |

Steps 1–5 are roughly a week and retire most of the risk, because they move
the agent and extension from *reviewed* to *observed*.

---

## What is genuinely solid

So the picture is not lopsided:

- **The backend is well covered.** 141 passing tests, including cross-client
  policy fixtures, tenant isolation, health derivation, focus state, late
  event delivery and query-cost assertions.
- **The agent has real socket-level tests.** 24 passing, verified stable
  over eight consecutive runs after a flaky port probe was fixed.
- **The design problems the review found are solved**, not papered over:
  one effective-policy resolver, supervised tasks, derived health,
  transactional DNS changes, explicit focus state.
- **Three latent bugs were found and fixed** along the way that the review
  did not catch — a missing icon import that crashed a page, optional
  request keys read unguarded, and IPv4 TCP treated as best-effort so the
  agent could serve UDP alone while reporting itself healthy.

The remaining work is mostly *confirmation*, and confirmation is exactly
what a pilot with real children's devices requires.
