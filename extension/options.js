document.addEventListener('DOMContentLoaded', async () => {
  const status = document.getElementById('status-msg');
  try {
    const config = await chrome.storage.managed.get(['apiBaseUrl', 'workstation_id', 'device_id']);
    document.getElementById('apiBaseUrl').textContent = config.apiBaseUrl || 'Not enrolled';
    document.getElementById('workstationId').textContent = config.workstation_id || 'Not enrolled';
    document.getElementById('deviceId').textContent = config.device_id || 'Not enrolled';
  } catch (_) {
    status.textContent = 'Enrollment is unavailable. Contact your administrator.';
  }
  document.getElementById('sync-btn').addEventListener('click', () => {
    chrome.runtime.sendMessage({ action: 'FORCE_SYNC_POLICY' }, (result) => {
      status.textContent = result?.success ? 'Policy synchronized.' : result?.message || 'Synchronization failed.';
    });
  });
});
