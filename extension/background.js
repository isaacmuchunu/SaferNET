const DEFAULT_BACKEND_URL = 'http://127.0.0.1:8000/api/v1';
const EXTENSION_VERSION = '2.6.0';
const SYNC_ALARM_NAME = 'safernet_policy_sync_alarm';
const HEARTBEAT_ALARM_NAME = 'safernet_heartbeat_alarm';
const COMMAND_ALARM_NAME = 'safernet_classroom_command_alarm';

async function ensureAlarms() {
  await chrome.alarms.create(SYNC_ALARM_NAME, { periodInMinutes: 2 });
  await chrome.alarms.create(HEARTBEAT_ALARM_NAME, { periodInMinutes: 5 });
  await chrome.alarms.create(COMMAND_ALARM_NAME, { periodInMinutes: 0.5 });
}

chrome.runtime.onInstalled.addListener(async () => {
  await ensureAlarms();
  await syncPoliciesFromDatabase();
  await sendHeartbeat();
  await pollClassroomCommands();
});

chrome.runtime.onStartup.addListener(async () => {
  await ensureAlarms();
  await syncPoliciesFromDatabase();
  await sendHeartbeat();
  await pollClassroomCommands();
});

chrome.alarms.onAlarm.addListener(async (alarm) => {
  if (alarm.name === SYNC_ALARM_NAME) await syncPoliciesFromDatabase();
  if (alarm.name === HEARTBEAT_ALARM_NAME) await sendHeartbeat();
  if (alarm.name === COMMAND_ALARM_NAME) await pollClassroomCommands();
});

async function apiConfig() {
  const config = await chrome.storage.local.get([
    'apiBaseUrl',
    'token',
    'workstation_id',
    'device_id',
    'laboratory_id',
    'learner_session_id',
    'rulesCount',
    'last_command_id',
    'focus_locked',
    'focus_url'
  ]);

  return {
    ...config,
    apiBaseUrl: (config.apiBaseUrl || DEFAULT_BACKEND_URL).replace(/\/$/, '')
  };
}

async function apiFetch(path, options = {}) {
  const config = await apiConfig();
  if (!config.token) throw new Error('A school service token is required.');

  const response = await fetch(`${config.apiBaseUrl}${path}`, {
    ...options,
    headers: {
      Accept: 'application/json',
      Authorization: `Bearer ${config.token}`,
      ...(options.body ? { 'Content-Type': 'application/json' } : {}),
      ...(options.headers || {})
    }
  });

  if (!response.ok) {
    const payload = await response.json().catch(() => null);
    throw new Error(payload?.message || `SaferNET API returned HTTP ${response.status}.`);
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
      institution: data.institution,
      nemis_code: data.nemis_code,
      policy_name: data.policy_name,
      enforce_safesearch: data.enforce_safesearch ?? true,
      enforce_youtube_strict: data.enforce_youtube_strict ?? true,
      blocked_domains: data.blocked_domains || [],
      allowed_domains: data.allowed_domains || [],
      blocked_categories: data.blocked_categories || [],
      rulesCount: data.dnr_rules?.length || 0,
      last_synced_at: new Date().toISOString(),
      last_error: null
    });

    return { success: true, count: data.dnr_rules?.length || 0 };
  } catch (error) {
    await chrome.storage.local.set({ last_error: error.message });
    return { success: false, message: error.message };
  }
}

async function sendHeartbeat() {
  try {
    const config = await apiConfig();
    if (!config.workstation_id) throw new Error('A workstation identifier is required.');

    await apiFetch('/extension/heartbeat', {
      method: 'POST',
      body: JSON.stringify({
        workstation_id: config.workstation_id,
        device_id: positiveInteger(config.device_id),
        version: EXTENSION_VERSION,
        rules_count: config.rulesCount || 0
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
    const command = data.command || null;
    const nextFocusUrl = command?.type === 'CLASSROOM_PUSH_URL'
      ? command.url
      : config.focus_url || null;

    await chrome.storage.local.set({
      focus_locked: Boolean(data.focus_locked),
      focus_url: data.focus_locked ? nextFocusUrl : null,
      last_command_id: command?.command_id || config.last_command_id || null
    });

    if (command?.command_id && command.command_id !== config.last_command_id) {
      await broadcastToTabs(command);

      if (command.type === 'CLASSROOM_PUSH_URL' && command.url) {
        const tabs = await chrome.tabs.query({});
        await Promise.all(tabs
          .filter((tab) => tab.id && isWebUrl(tab.url))
          .map((tab) => chrome.tabs.update(tab.id, { url: command.url }).catch(() => null)));
      }
    }
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

chrome.webNavigation.onBeforeNavigate.addListener(async (details) => {
  if (details.frameId !== 0 || !isWebUrl(details.url)) return;

  try {
    const config = await chrome.storage.local.get(['blocked_domains', 'focus_locked', 'focus_url']);
    const destination = new URL(details.url);

    if (config.focus_locked && isWebUrl(config.focus_url)) {
      const focusUrl = new URL(config.focus_url);
      if (destination.origin !== focusUrl.origin) {
        await sendTelemetry(details.url, '', 'restrict', 'Classroom focus mode');
        await chrome.tabs.update(details.tabId, { url: focusUrl.href });
        return;
      }
    }

    const blocked = (config.blocked_domains || []).some(
      (entry) => destination.hostname === entry || destination.hostname.endsWith(`.${entry}`)
    );

    if (blocked) {
      await sendTelemetry(details.url, '', 'block', 'County policy domain block');
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
    if (isWebUrl(tab.url)) await sendTelemetry(tab.url, tab.title || '');
  } catch (_) {}
});

chrome.tabs.onUpdated.addListener(async (_tabId, changeInfo, tab) => {
  if (changeInfo.status === 'complete' && isWebUrl(tab.url)) {
    await sendTelemetry(tab.url, tab.title || '');
  }
});

async function sendTelemetry(url, title, action = 'allow', reason = 'Learner navigation captured by SaferNET Shield') {
  try {
    const config = await apiConfig();
    const sessionId = positiveInteger(config.learner_session_id);
    if (!sessionId) return;

    const destination = new URL(url);
    await apiFetch('/web-events', {
      method: 'POST',
      body: JSON.stringify({
        event_uuid: crypto.randomUUID(),
        learner_session_id: sessionId,
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
      })
    });
  } catch (error) {
    await chrome.storage.local.set({ last_error: error.message });
  }
}

chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
  if (message?.action === 'FORCE_SYNC_POLICY') {
    syncPoliciesFromDatabase().then(sendResponse);
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
