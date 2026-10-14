import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import { webcrypto } from 'node:crypto';

function worker(initial = {}, managedOverrides = {}) {
  const state = { blocked_domains: [], allowed_domains: [], ...initial };
  const managed = { apiBaseUrl: 'https://school.test/api/v1', token: 'device-token', device_id: 7, workstation_id: 'LAB-7', ...managedOverrides };
  const listeners = {};
  const listener = key => ({ addListener(fn) { listeners[key] = fn; } });
  const redirects = [];
  const requests = [];
  let response = {};
  let rules = [{ id: 999, action: { type: 'block' }, condition: { urlFilter: 'blocked' } }];
  let rejectInstall = false;
  const storage = {
    get: async keys => Object.fromEntries(keys.map(key => [key, state[key]])),
    set: async values => Object.assign(state, values),
    remove: async keys => keys.forEach(key => delete state[key]),
    setAccessLevel: async () => {},
  };
  const chrome = {
    runtime: { onInstalled: listener('installed'), onStartup: listener('startup'), onMessage: listener('message'), getURL: x => x },
    alarms: { onAlarm: listener('alarm'), create: async () => {} },
    storage: { local: storage, managed: { get: async () => managed, setAccessLevel: async () => {} } },
    tabs: { onRemoved: listener('removed'), onActivated: listener('activated'), onUpdated: listener('updated'), update: async (_id, value) => redirects.push(value), query: async () => [] },
    webNavigation: { onBeforeNavigate: listener('navigate') },
    declarativeNetRequest: { getDynamicRules: async () => rules, updateDynamicRules: async update => { if (rejectInstall) throw new Error('Chrome rejected rules'); rules = update.addRules; } },
  };
  const context = vm.createContext({ chrome, URL, AbortSignal, crypto: webcrypto,
    fetch: async (url, options) => { requests.push({ url, options }); return { ok: true, json: async () => response }; } });
  vm.runInContext(fs.readFileSync(new URL('../extension/background.js', import.meta.url), 'utf8'), context);
  return { state, requests, redirects, listeners,
    run: source => vm.runInContext(source, context),
    response: value => { response = value; }, rules: () => rules, rejectInstall: () => { rejectInstall = true; } };
}

const policy = () => ({ revision: 2, institution_id: 1, content_hash: 'a'.repeat(64),
  synced_at: new Date().toISOString(), blocked_domains: [], allowed_domains: [],
  dnr_rules: [{ id: 1, action: { type: 'redirect' }, condition: { urlFilter: 'google' } }],
  delivery: { client: 'browser_extension', complete: true, installed_domains: 0, total_domains: 0 } });

test('local enrollment and pinned sessions cannot override managed configuration', async () => {
  const w = worker({ apiBaseUrl: 'https://attacker.test', token: 'other', device_id: 88, learner_session_pinned: true, learner_session_id: 22 });
  w.response({ session: { id: 3, started_at: '2026-01-01T00:00:00Z' } });
  await w.run('refreshLearnerSession()');
  assert.equal(w.requests[0].url, 'https://school.test/api/v1/extension/session?device_id=7');
  assert.equal(w.requests[0].options.headers.Authorization, 'Bearer device-token');
  assert.equal(w.requests[0].options.redirect, 'error');
  assert.equal(w.state.learner_session_id, 3);
});

test('unmanaged and insecure enrollment never sends credentials', async () => {
  for (const overrides of [{ token: undefined }, { apiBaseUrl: 'http://remote.test/api/v1' }, { device_id: 0 }]) {
    const w = worker({}, overrides);
    assert.equal((await w.run('syncPoliciesFromDatabase()')).success, false);
    assert.equal(w.requests.length, 0);
  }
});

test('malformed responses retain previous dynamic and cached policy', async () => {
  for (const response of [{}, { ...policy(), dnr_rules: [] }, { ...policy(), delivery: {} }, { ...policy(), blocked_domains: ['*'] }]) {
    const w = worker({ blocked_domains: ['old.example'] });
    w.response(response);
    assert.equal((await w.run('syncPoliciesFromDatabase()')).success, false);
    assert.equal(w.rules()[0].id, 999);
    assert.deepEqual(w.state.blocked_domains, ['old.example']);
  }
});

test('a valid empty domain list installs system rules and a Chrome rejection preserves the cache', async () => {
  const w = worker(); w.response(policy());
  assert.equal((await w.run('syncPoliciesFromDatabase()')).success, true);
  assert.equal(w.rules()[0].id, 1);
  const rejected = worker({ blocked_domains: ['old.example'] });
  rejected.response(policy()); rejected.rejectInstall();
  assert.equal((await rejected.run('syncPoliciesFromDatabase()')).success, false);
  assert.deepEqual(rejected.state.blocked_domains, ['old.example']);
});

test('expired focus releases during offline navigation and after worker restart', async () => {
  const state = { focus_locked: true, focus_url: 'https://lesson.test/', focus: { locked: true, url: 'https://lesson.test/', expires_at: '2020-01-01T00:00:00Z' } };
  for (let restart = 0; restart < 2; restart++) {
    const w = worker(state);
    await w.listeners.navigate({ frameId: 0, tabId: 1, url: 'https://outside.test/' });
    assert.equal(w.redirects.length, 0);
    assert.equal(w.state.focus_locked, false);
  }
});

test('unexpired focus remains enforced offline; domain filtering continues after expiry', async () => {
  const w = worker({ focus: { locked: true, url: 'https://lesson.test/', expires_at: '2099-01-01T00:00:00Z' } });
  await w.listeners.navigate({ frameId: 0, tabId: 1, url: 'https://outside.test/' });
  assert.equal(w.redirects[0].url, 'https://lesson.test/');
  const expired = worker({ blocked_domains: ['outside.test'], focus: { locked: true, url: 'https://lesson.test/', expires_at: '2020-01-01T00:00:00Z' } });
  await expired.listeners.navigate({ frameId: 0, tabId: 1, url: 'https://outside.test/' });
  assert.ok(expired.redirects[0].url.startsWith('blocked.html'));
});

test('allowlist precedence covers exact matches and parent/child combinations', () => {
  const w = worker();
  assert.equal(w.run("policyBlocks('https://ads.example.com/', {blocked_domains:['ads.example.com'],allowed_domains:['example.com']})"), false);
  assert.equal(w.run("policyBlocks('https://lessons.example.com/', {blocked_domains:['example.com'],allowed_domains:['lessons.example.com']})"), false);
  assert.equal(w.run("policyBlocks('https://ads.example.com/', {blocked_domains:['example.com'],allowed_domains:['lessons.example.com']})"), true);
});
