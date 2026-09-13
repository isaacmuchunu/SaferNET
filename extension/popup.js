document.addEventListener('DOMContentLoaded', async () => {
  const schoolEl = document.getElementById('school-name');
  const policyEl = document.getElementById('policy-name');
  const countEl = document.getElementById('rules-count');
  const syncedEl = document.getElementById('last-synced');
  const syncBtn = document.getElementById('sync-now-btn');
  const settingsBtn = document.getElementById('open-settings-btn');

  async function refreshPopupData() {
    const config = await chrome.storage.local.get([
      'institution',
      'policy_name',
      'rulesCount',
      'last_synced_at'
    ]);

    if (config.institution) schoolEl.textContent = config.institution;
    if (config.policy_name) policyEl.textContent = config.policy_name;
    if (config.rulesCount !== undefined) countEl.textContent = config.rulesCount;

    if (config.last_synced_at) {
      const d = new Date(config.last_synced_at);
      syncedEl.textContent = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    } else {
      syncedEl.textContent = 'Never';
    }
  }

  await refreshPopupData();

  syncBtn.addEventListener('click', () => {
    syncBtn.disabled = true;
    syncBtn.textContent = 'Syncing...';

    chrome.runtime.sendMessage({ action: 'FORCE_SYNC_POLICY' }, async (res) => {
      syncBtn.disabled = false;
      syncBtn.textContent = 'Sync Policy';
      await refreshPopupData();
    });
  });

  settingsBtn.addEventListener('click', () => {
    chrome.runtime.openOptionsPage();
  });
});
