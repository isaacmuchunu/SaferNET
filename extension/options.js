document.addEventListener('DOMContentLoaded', async () => {
  const apiInput = document.getElementById('apiBaseUrl');
  const stationInput = document.getElementById('workstationId');
  const deviceInput = document.getElementById('deviceId');
  const laboratoryInput = document.getElementById('laboratoryId');
  const learnerSessionInput = document.getElementById('learnerSessionId');
  const tokenInput = document.getElementById('token');
  const form = document.getElementById('settings-form');
  const syncBtn = document.getElementById('sync-btn');
  const statusEl = document.getElementById('status-msg');

  // Load stored settings
  const config = await chrome.storage.local.get([
    'apiBaseUrl',
    'workstation_id',
    'device_id',
    'laboratory_id',
    'learner_session_id',
    'token'
  ]);

  apiInput.value = config.apiBaseUrl || 'http://127.0.0.1:8000/api/v1';
  stationInput.value = config.workstation_id || '';
  deviceInput.value = config.device_id || '';
  laboratoryInput.value = config.laboratory_id || '';
  learnerSessionInput.value = config.learner_session_id || '';
  tokenInput.value = config.token || '';

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    await chrome.storage.local.set({
      apiBaseUrl: apiInput.value.trim().replace(/\/$/, ''),
      workstation_id: stationInput.value.trim(),
      device_id: deviceInput.value ? Number(deviceInput.value) : null,
      laboratory_id: laboratoryInput.value ? Number(laboratoryInput.value) : null,
      learner_session_id: learnerSessionInput.value ? Number(learnerSessionInput.value) : null,
      token: tokenInput.value.trim()
    });

    statusEl.className = 'status-msg success';
    statusEl.textContent = 'Settings saved. Triggering policy sync...';

    chrome.runtime.sendMessage({ action: 'FORCE_SYNC_POLICY' }, (res) => {
      if (res && res.success) {
        statusEl.textContent = `Settings saved and ${res.count} dynamic rules installed successfully.`;
      } else {
        statusEl.className = 'status-msg error';
        statusEl.textContent = `Settings saved, but policy sync failed: ${res?.message || 'Server unreachable'}`;
      }
    });
  });

  syncBtn.addEventListener('click', () => {
    syncBtn.disabled = true;
    syncBtn.textContent = 'Synchronizing...';
    statusEl.className = 'status-msg';
    statusEl.textContent = 'Contacting SaferNET policy engine...';

    chrome.runtime.sendMessage({ action: 'FORCE_SYNC_POLICY' }, (res) => {
      syncBtn.disabled = false;
      syncBtn.textContent = 'Force Policy Sync Now';
      if (res && res.success) {
        statusEl.className = 'status-msg success';
        statusEl.textContent = `Policy sync complete. ${res.count} active dynamic rules deployed.`;
      } else {
        statusEl.className = 'status-msg error';
        statusEl.textContent = `Sync failed: ${res?.message || 'Check network / server status'}`;
      }
    });
  });
});
