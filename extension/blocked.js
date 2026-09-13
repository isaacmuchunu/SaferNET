document.addEventListener('DOMContentLoaded', async () => {
  const params = new URLSearchParams(window.location.search);
  const targetUrl = params.get('url') || 'Unknown Destination';
  const category = params.get('category') || 'Restricted Material';
  const rule = params.get('rule') || 'County Safeguarding Rule';

  const urlEl = document.getElementById('blocked-url');
  const catEl = document.getElementById('blocked-category');
  const ruleEl = document.getElementById('blocked-rule');
  const schoolEl = document.getElementById('school-name');
  const statusEl = document.getElementById('request-status');
  const submitBtn = document.getElementById('submit-btn');
  const backBtn = document.getElementById('back-btn');
  const reasonInput = document.getElementById('request-reason');

  urlEl.textContent = targetUrl;
  catEl.textContent = category;
  ruleEl.textContent = rule;

  // Retrieve cached school name
  try {
    const config = await chrome.storage.local.get(['institution']);
    if (config.institution) {
      schoolEl.textContent = config.institution;
    }
  } catch (e) {}

  backBtn.addEventListener('click', () => {
    if (window.history.length > 1) {
      window.history.back();
    } else {
      window.location.href = 'https://www.google.com';
    }
  });

  submitBtn.addEventListener('click', () => {
    const reason = reasonInput.value.trim();
    if (!reason) {
      statusEl.className = 'status-msg error';
      statusEl.textContent = 'Please explain why you need access to this website.';
      return;
    }

    submitBtn.disabled = true;
    submitBtn.textContent = 'Submitting Request...';

    chrome.runtime.sendMessage(
      {
        action: 'SUBMIT_EXCEPTION_REQUEST',
        url: targetUrl,
        reason: reason
      },
      (res) => {
        if (res && res.success) {
          statusEl.className = 'status-msg success';
          statusEl.textContent = 'Access request submitted to your School Head / Computer Lab Manager for review.';
          reasonInput.value = '';
          submitBtn.textContent = 'Submitted Successfully';
        } else {
          statusEl.className = 'status-msg error';
          statusEl.textContent = 'Failed to submit request. Please notify your computer lab instructor.';
          submitBtn.disabled = false;
          submitBtn.textContent = 'Retry Submission';
        }
      }
    );
  });
});
