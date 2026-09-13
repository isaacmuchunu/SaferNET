/**
 * SaferNET Shield Content Script
 * Telemetry hook, SafeSearch assistance, and Classroom broadcast banner receiver.
 */

(() => {
  // 1. Report page view telemetry
  try {
    const pageData = {
      action: 'PAGE_VIEW_TELEMETRY',
      url: window.location.href,
      title: document.title,
      domain: window.location.hostname,
      referrer: document.referrer
    };
    chrome.runtime.sendMessage(pageData).catch(() => {});
  } catch (e) {}

  // 2. Listen for classroom push messages and teacher focus nudges
  chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
    if (message && message.type === 'CLASSROOM_PUSH_URL') {
      showTeacherBanner(`Teacher broadcast: ${message.title || 'Lesson resource'}`);
      if (message.url && message.url !== window.location.href) {
        setTimeout(() => {
          window.location.href = message.url;
        }, 1200);
      }
      sendResponse({ received: true });
    }

    if (message && message.type === 'CLASSROOM_ATTENTION_NUDGE') {
      showTeacherBanner(`Teacher notice: ${message.title || 'Please focus on the classroom lesson.'}`);
      sendResponse({ received: true });
    }
  });

  function showTeacherBanner(text) {
    const existing = document.getElementById('safernet-teacher-banner');
    if (existing) existing.remove();

    const banner = document.createElement('div');
    banner.id = 'safernet-teacher-banner';
    const message = document.createElement('div');
    message.style.cssText = 'position:fixed;top:16px;left:50%;transform:translateX(-50%);z-index:2147483647;background:#0f766e;color:#f8fafc;border:2px solid #5eead4;border-radius:8px;padding:12px 24px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;font-size:13.5px;font-weight:700;box-shadow:0 10px 30px rgba(15,118,110,.28);display:flex;align-items:center;gap:10px;';
    message.textContent = text;
    banner.appendChild(message);
    document.body.appendChild(banner);
    setTimeout(() => { banner.remove(); }, 6000);
  }
})();
