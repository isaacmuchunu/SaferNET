const DEFAULT_BACKEND_URL = 'http://127.0.0.1:8000/api/v1';
const EXTENSION_VERSION = '2.7.0';
const SYNC_ALARM_NAME = 'safernet_policy_sync_alarm';
const HEARTBEAT_ALARM_NAME = 'safernet_heartbeat_alarm';
const COMMAND_ALARM_NAME = 'safernet_classroom_command_alarm';
const QUEUE_ALARM_NAME = 'safernet_telemetry_flush_alarm';

const REQUEST_TIMEOUT_MS = 8000;

// The queue is bounded: on a long outage the oldest events are dropped rather
// than letting storage grow without limit, and the drop is counted so the loss
// is visible instead of silent.
const MAX_QUEUED_EVENTS = 500;
const MAX_ATTEMPTS = 8;
const BASE_BACKOFF_MS = 5000;
const MAX_BACKOFF_MS = 15 * 60 * 1000;

// An event no session can own. Dropped and counted rather than held forever.
const UNATTRIBUTABLE = 'unattributable';

// chrome.storage.local has no compare-and-swap, and a burst of tab events runs
// several queue writers at once. Every read-modify-write of the queue goes
// through this chain, so an append cannot be overwritten by a flush that read
// the same snapshot.
let queueLock = Promise.resolve();

function withQueueLock(work) {
  const result = queueLock.then(work, work);
  queueLock = result.then(() => undefined, () => undefined);

  return result;
}

async function ensureAlarms() {
  await chrome.alarms.create(SYNC_ALARM_NAME, { periodInMinutes: 2 });
  await chrome.alarms.create(HEARTBEAT_ALARM_NAME, { periodInMinutes: 5 });
  await chrome.alarms.create(COMMAND_ALARM_NAME, { periodInMinutes: 0.5 });
  await chrome.alarms.create(QUEUE_ALARM_NAME, { periodInMinutes: 0.5 });
}

async function startUp() {
  await ensureAlarms();
  await syncPoliciesFromDatabase();
  await refreshLearnerSession();
  await flushTelemetryQueue();
  await sendHeartbeat();
  await pollClassroomCommands();
}

chrome.runtime.onInstalled.addListener(startUp);
chrome.runtime.onStartup.addListener(startUp);

chrome.alarms.onAlarm.addListener(async (alarm) => {
  if (alarm.name === SYNC_ALARM_NAME) await syncPoliciesFromDatabase();
  if (alarm.name === HEARTBEAT_ALARM_NAME) await sendHeartbeat();
  if (alarm.name === COMMAND_ALARM_NAME) {
    await pollClassroomCommands();
    await refreshLearnerSession();
  }
  // The worker is torn down between alarms, so a flush on every wake-up is what
  // makes delivery survive a restart rather than depending on a live timer.
  if (alarm.name === QUEUE_ALARM_NAME) await flushTelemetryQueue();
});

async function apiConfig() {
  const config = await chrome.storage.local.get([
    'apiBaseUrl',
    'token',
    'workstation_id',
    'device_id',
    'laboratory_id',
    'learner_session_id',
    'learner_session_started_at',
    'learner_session_pinned',
    'rulesCount',
    'last_command_ids',
    'last_synced_at',
    'applied_revision',
    'policy_complete',
    'focus'
  ]);

  return {
    ...config,
    apiBaseUrl: (config.apiBaseUrl || DEFAULT_BACKEND_URL).replace(/\/$/, '')
  };
}

async function apiFetch(path, options = {}) {
  const config = await apiConfig();
  if (!config.token) throw new Error('A school service token is required.');

  // Every request is bounded. Enforcement must never wait on a slow network,
  // and a request without a deadline is exactly how that happens.
  const signal = AbortSignal.timeout(options.timeoutMs ?? REQUEST_TIMEOUT_MS);

  const response = await fetch(`${config.apiBaseUrl}${path}`, {
    ...options,
    signal,
    headers: {
      Accept: 'application/json',
      Authorization: `Bearer ${config.token}`,
      ...(options.body ? { 'Content-Type': 'application/json' } : {}),
      ...(options.headers || {})
    }
  });

  if (!response.ok) {
    const payload = await response.json().catch(() => null);
    const error = new Error(payload?.message || `SaferNET API returned HTTP ${response.status}.`);
    error.status = response.status;
    throw error;
  }

  return response.json();
}

async function syncPoliciesFromDatabase() {
  try {
    const data = await apiFetch('/extension/sync');
    const currentRules = await chrome.declarativeNetRequest.getDynamicRules();

    await chrome.declarativeNetRequest.updateDynamicRules({
      removeRuleIds: currentRules.map((rule) => rule.id),
      addRules: Array.isArray(data.dnr_rules) ? data.dnr_rules : []
    });

    await chrome.storage.local.set({
      revision: data.revision,
      content_hash: data.content_hash,
      institution: data.institution,
      nemis_code: data.nemis_code,
      policy_name: data.policy_name,
      enforce_safesearch: data.enforce_safesearch ?? true,
      enforce_youtube_strict: data.enforce_youtube_strict ?? true,
      blocked_domains: data.blocked_domains || [],
      allowed_domains: data.allowed_domains || [],
      blocked_categories: data.blocked_categories || [],
      // The browser holds a fixed number of dynamic rules, so the school's
      // policy may be larger than what is installed here. Record what the
      // server delivered rather than letting a partial ruleset read as whole.
      policy_complete: data.delivery?.complete ?? null,
      policy_total_domains: data.delivery?.total_domains ?? null,
      policy_installed_domains: data.delivery?.installed_domains ?? null,
      rulesCount: data.dnr_rules?.length || 0,
      // Written only on success, so it is the last time a policy was actually
      // installed rather than the last time one was attempted.
      last_synced_at: new Date().toISOString(),
      applied_revision: data.revision,
      last_error: null
    });

    return { success: true, count: data.dnr_rules?.length || 0 };
  } catch (error) {
    await chrome.storage.local.set({ last_error: error.message });
    return { success: false, message: error.message };
  }
}

/**
 * Ask the server which learner is signed in at this workstation.
 *
 * Attribution should not depend on someone pasting a session identifier into
 * the options page, and a learner change must be picked up on its own. A
 * manually pinned session still wins, for the enrolment cases that need it.
 */
async function refreshLearnerSession() {
  try {
    const config = await apiConfig();
    if (config.learner_session_pinned) return;
    if (!config.device_id && !config.workstation_id) return;

    const query = config.device_id
      ? `?device_id=${encodeURIComponent(config.device_id)}`
      : `?workstation_id=${encodeURIComponent(config.workstation_id)}`;

    const data = await apiFetch(`/extension/session${query}`);
    const resolved = data.session?.id ?? null;

    if (resolved !== (config.learner_session_id ?? null)) {
      await chrome.storage.local.set({
        learner_session_id: resolved,
        learner_session_started_at: data.session?.started_at ?? null,
        learner_name: data.session?.learner_name ?? null,
        session_reason: data.reason ?? null
      });
    }
  } catch (error) {
    await chrome.storage.local.set({ last_error: error.message });
  }
}

async function sendHeartbeat() {
  try {
    const config = await apiConfig();
    if (!config.workstation_id) throw new Error('A workstation identifier is required.');

    const stored = await chrome.storage.local.get(['last_error']);

    await apiFetch('/extension/heartbeat', {
      method: 'POST',
      body: JSON.stringify({
        workstation_id: config.workstation_id,
        device_id: positiveInteger(config.device_id),
        version: EXTENSION_VERSION,
        rules_count: config.rulesCount || 0,
        // Contact and enforcement are reported separately: this says when a
        // policy was last actually installed, which is absent while every sync
        // is failing.
        last_synced_at: config.last_synced_at ?? null,
        applied_revision: config.applied_revision ?? null,
        policy_complete: config.policy_complete ?? null,
        last_error: stored.last_error ? String(stored.last_error).slice(0, 500) : null
      })
    });
  } catch (error) {
    await chrome.storage.local.set({ last_error: error.message });
  }
}

async function pollClassroomCommands() {
  try {
    const config = await apiConfig();
    const laboratoryId = positiveInteger(config.laboratory_id);
    const suffix = laboratoryId ? `?laboratory_id=${laboratoryId}` : '';
    const data = await apiFetch(`/extension/commands${suffix}`);

    // Focus is state the server holds, with its own target, revision and
    // expiry. It is no longer inferred from whichever command happens to be
    // cached, so a lock without a target cannot read as a lock.
    const focus = data.focus ?? { locked: false, url: null, revision: 0 };
    await chrome.storage.local.set({
      focus,
      focus_locked: Boolean(focus.locked),
      focus_url: focus.locked ? focus.url : null
    });

    // Each command kind is acknowledged separately, so a nudge arriving between
    // a lesson push and this poll no longer hides the lesson.
    const seen = config.last_command_ids || {};
    const next = { ...seen };

    for (const command of data.commands ?? (data.command ? [data.command] : [])) {
      if (!command?.command_id || seen[command.type] === command.command_id) continue;

      next[command.type] = command.command_id;
      await broadcastToTabs(command);

      if (command.type === 'CLASSROOM_PUSH_URL' && command.url) {
        const tabs = await chrome.tabs.query({});
        await Promise.all(tabs
          .filter((tab) => tab.id && isWebUrl(tab.url))
          .map((tab) => chrome.tabs.update(tab.id, { url: command.url }).catch(() => null)));
      }
    }

    await chrome.storage.local.set({ last_command_ids: next });
  } catch (error) {
    await chrome.storage.local.set({ last_error: error.message });
  }
}

async function broadcastToTabs(message) {
  const tabs = await chrome.tabs.query({});
  await Promise.all(tabs
    .filter((tab) => tab.id && isWebUrl(tab.url))
    .map((tab) => chrome.tabs.sendMessage(tab.id, message).catch(() => null)));
}

/**
 * Whether the school's policy blocks a URL, using the cached policy.
 *
 * An approved exception outranks a parent-domain entry on an upstream list, so
 * the allowlist is consulted first and settles the question when it hits.
 */
function policyBlocks(url, config) {
  let hostname;

  try {
    hostname = new URL(url).hostname;
  } catch (_) {
    return false;
  }

  const covers = (entry) => hostname === entry || hostname.endsWith(`.${entry}`);

  if ((config.allowed_domains || []).some(covers)) return false;

  return (config.blocked_domains || []).some(covers);
}

// The last page view recorded per tab, so one navigation is not recorded twice
// when onUpdated and onActivated both fire for it.
const lastPageView = new Map();
const PAGE_VIEW_DEBOUNCE_MS = 4000;

/**
 * Records a page the learner actually reached.
 *
 * A blocked navigation is recorded once, by the navigation handler, as a block.
 * Recording it again here would put an "allowed" row for a blocked site in the
 * learner's history — the opposite of what happened, in the record most likely
 * to be read back during a safeguarding conversation.
 */
async function recordPageView(tabId, url, title) {
  const config = await chrome.storage.local.get(['blocked_domains', 'allowed_domains']);
  if (policyBlocks(url, config)) return;

  const previous = lastPageView.get(tabId);
  const now = Date.now();

  if (previous && previous.url === url && now - previous.at < PAGE_VIEW_DEBOUNCE_MS) return;

  lastPageView.set(tabId, { url, at: now });
  await queueTelemetry(url, title);
}

chrome.tabs.onRemoved.addListener((tabId) => lastPageView.delete(tabId));

chrome.webNavigation.onBeforeNavigate.addListener(async (details) => {
  if (details.frameId !== 0 || !isWebUrl(details.url)) return;

  try {
    const config = await chrome.storage.local.get(['blocked_domains', 'allowed_domains', 'focus_locked', 'focus_url']);
    const destination = new URL(details.url);

    if (config.focus_locked && isWebUrl(config.focus_url)) {
      const focusUrl = new URL(config.focus_url);
      if (destination.origin !== focusUrl.origin) {
        // Queued, never awaited: the redirect must not wait on the network.
        queueTelemetry(details.url, '', 'restrict', 'Classroom focus mode');
        await chrome.tabs.update(details.tabId, { url: focusUrl.href });
        return;
      }
    }

    if (policyBlocks(details.url, config)) {
      queueTelemetry(details.url, '', 'block', 'County policy domain block');
      const blockUrl = chrome.runtime.getURL('blocked.html')
        + `?url=${encodeURIComponent(details.url)}&category=Restricted+Domain&rule=County+Policy+Block`;
      await chrome.tabs.update(details.tabId, { url: blockUrl });
    }
  } catch (_) {
    // A malformed or browser-internal URL is ignored.
  }
});

chrome.tabs.onActivated.addListener(async ({ tabId }) => {
  try {
    const tab = await chrome.tabs.get(tabId);
    if (isWebUrl(tab.url)) await recordPageView(tabId, tab.url, tab.title || '');
  } catch (_) {}
});

chrome.tabs.onUpdated.addListener(async (tabId, changeInfo, tab) => {
  if (changeInfo.status === 'complete' && isWebUrl(tab.url)) {
    await recordPageView(tabId, tab.url, tab.title || '');
  }
});

/**
 * Record an event for delivery.
 *
 * The event is written to storage with a stable UUID and sent by the queue, so
 * it survives a failed request, a network outage and a service-worker restart.
 * Nothing here waits on the network, which is what keeps enforcement prompt.
 */
async function queueTelemetry(url, title, action = 'allow', reason = 'Learner navigation captured by SaferNET Shield') {
  try {
    const config = await apiConfig();
    const destination = new URL(url);

    const event = {
      // Generated once and reused across retries: the server deduplicates on
      // it, so a retry after an ambiguous failure cannot double-count.
      event_uuid: crypto.randomUUID(),
      // Bound here, not at send time. A queued event belongs to the learner who
      // was signed in when it happened; resolving the session on flush would
      // hand one learner's browsing to whoever signed in next.
      learner_session_id: positiveInteger(config.learner_session_id),
      url,
      domain: destination.hostname,
      request_kind: 'top_level',
      action,
      enforcement_source: 'extension',
      severity: action === 'block' ? 'medium' : 'low',
      reason,
      occurred_at: new Date().toISOString(),
      metadata: {
        page_title: title,
        workstation_id: config.workstation_id || null
      }
    };

    await withQueueLock(async () => {
      const { telemetry_queue: queue = [], telemetry_dropped: dropped = 0 } =
        await chrome.storage.local.get(['telemetry_queue', 'telemetry_dropped']);

      const updated = [...queue, { event, attempts: 0, next_attempt_at: 0 }];
      const overflow = Math.max(0, updated.length - MAX_QUEUED_EVENTS);

      await chrome.storage.local.set({
        telemetry_queue: updated.slice(overflow),
        telemetry_dropped: dropped + overflow
      });
    });

    await flushTelemetryQueue();
  } catch (error) {
    await chrome.storage.local.set({ last_error: error.message });
  }
}

/**
 * Deliver what is queued, oldest first.
 *
 * A failure a retry could fix is backed off and kept; one the server has
 * rejected outright is dropped, because retrying it forever would block
 * everything behind it.
 */
async function flushTelemetryQueue() {
  return withQueueLock(async () => {
    const { telemetry_queue: queue = [] } = await chrome.storage.local.get(['telemetry_queue']);
    if (queue.length === 0) return;

    const config = await apiConfig();
    const currentSession = positiveInteger(config.learner_session_id);
    const sessionStartedAt = config.learner_session_started_at
      ? Date.parse(config.learner_session_started_at)
      : null;

    const now = Date.now();
    const remaining = [];
    let dropped = 0;
    let lastError = null;

    for (const entry of queue) {
      if (entry.next_attempt_at > now) {
        remaining.push(entry);
        continue;
      }

      const sessionId = sessionFor(entry.event, currentSession, sessionStartedAt);

      // Nobody was signed in when this happened and nobody can own it now.
      // Holding it forever would clog the queue behind attributable events.
      if (sessionId === UNATTRIBUTABLE) {
        dropped++;
        continue;
      }

      // Held, not discarded: the session usually resolves within a poll or two,
      // and the browsing still happened.
      if (!sessionId) {
        remaining.push(entry);
        continue;
      }

      try {
        await apiFetch('/web-events', {
          method: 'POST',
          body: JSON.stringify({ ...entry.event, learner_session_id: sessionId })
        });
      } catch (error) {
        lastError = error.message;
        // A rejected event will be rejected again however often it is sent.
        const permanent = error.status >= 400 && error.status < 500 && error.status !== 429;
        const attempts = entry.attempts + 1;

        if (permanent || attempts >= MAX_ATTEMPTS) {
          dropped++;
          continue;
        }

        remaining.push({
          ...entry,
          attempts,
          next_attempt_at: now + Math.min(MAX_BACKOFF_MS, BASE_BACKOFF_MS * 2 ** (attempts - 1))
        });
      }
    }

    const stored = await chrome.storage.local.get(['telemetry_dropped']);
    await chrome.storage.local.set({
      telemetry_queue: remaining,
      telemetry_dropped: (stored.telemetry_dropped || 0) + dropped,
      ...(lastError ? { last_error: lastError } : {})
    });
  });
}

/**
 * The session an event belongs to: the one stamped on it when it was recorded,
 * always.
 *
 * An event queued before any session was known may fall back to the current one
 * only if that session was already open when the event happened. Attaching it
 * otherwise would put one learner's browsing on the next learner's record,
 * which is the whole reason the stamp exists.
 *
 * Returns the session id, UNATTRIBUTABLE when no session can ever own it, or
 * null to hold it until one resolves.
 */
function sessionFor(event, currentSession, sessionStartedAt) {
  if (event.learner_session_id) return event.learner_session_id;
  if (!currentSession || sessionStartedAt === null) return null;

  return Date.parse(event.occurred_at) >= sessionStartedAt ? currentSession : UNATTRIBUTABLE;
}

chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
  if (message?.action === 'FORCE_SYNC_POLICY') {
    (async () => {
      const result = await syncPoliciesFromDatabase();
      await refreshLearnerSession();
      sendResponse(result);
    })();
    return true;
  }

  // The content script reports the page it loaded on. This was previously sent
  // and never handled, so in-page navigations went unrecorded.
  if (message?.action === 'PAGE_VIEW_TELEMETRY') {
    queueTelemetry(message.url, message.title || '');
    sendResponse({ queued: true });
    return true;
  }

  if (message?.action === 'SUBMIT_EXCEPTION_REQUEST') {
    (async () => {
      try {
        const config = await apiConfig();
        const domain = message.domain || new URL(message.url).hostname;
        const response = await apiFetch('/extension/exception-requests', {
          method: 'POST',
          body: JSON.stringify({
            domain,
            reason: message.reason,
            workstation_id: config.workstation_id || null
          })
        });
        sendResponse({ success: true, requestId: response.request_id });
      } catch (error) {
        sendResponse({ success: false, error: error.message });
      }
    })();
    return true;
  }
});

function positiveInteger(value) {
  const parsed = Number(value);
  return Number.isInteger(parsed) && parsed > 0 ? parsed : null;
}

function isWebUrl(value) {
  return typeof value === 'string' && (value.startsWith('https://') || value.startsWith('http://'));
}
