const BASE_URL = '/api/v1';
const TOKEN_KEY = 'safernet.portal.token';
const DEVICE_KEY = 'safernet.portal.device';

/** Error thrown for any non-2xx API response. */
export class ApiError extends Error {
    constructor(status, message, errors = {}) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.errors = errors;
    }

    get isValidation() {
        return this.status === 422;
    }

    get isForbidden() {
        return this.status === 403;
    }
}

export const tokenStore = {
    read() {
        try {
            return window.localStorage.getItem(TOKEN_KEY);
        } catch {
            return null;
        }
    },
    write(token) {
        try {
            window.localStorage.setItem(TOKEN_KEY, token);
        } catch {
            /* storage unavailable — the session stays in memory only */
        }
    },
    clear() {
        try {
            window.localStorage.removeItem(TOKEN_KEY);
        } catch {
            /* no-op */
        }
    },
    deviceName() {
        try {
            let deviceId = window.localStorage.getItem(DEVICE_KEY);

            if (!deviceId) {
                deviceId = globalThis.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(36).slice(2)}`;
                window.localStorage.setItem(DEVICE_KEY, deviceId);
            }

            return `SAFERNET Officer Portal · ${deviceId.slice(0, 8)}`;
        } catch {
            return 'SAFERNET Officer Portal';
        }
    },
};

let onUnauthenticated = () => {};

export function setUnauthenticatedHandler(handler) {
    onUnauthenticated = handler;
}

function buildUrl(path, query) {
    const url = new URL(`${BASE_URL}${path}`, window.location.origin);

    Object.entries(query ?? {}).forEach(([key, value]) => {
        if (value === undefined || value === null || value === '') {
            return;
        }

        // An array has to be appended one entry at a time as `key[]`, which is
        // what PHP parses back into an array. `set` would stringify it to
        // "a,b" and the server would reject a comma-joined string where it
        // expects a list.
        if (Array.isArray(value)) {
            value
                .filter((entry) => entry !== undefined && entry !== null && entry !== '')
                .forEach((entry) => url.searchParams.append(`${key}[]`, entry));

            return;
        }

        url.searchParams.set(key, value);
    });

    return url.toString();
}

function statusMessage(status) {
    switch (status) {
        case 401:
            return 'Your session has expired. Sign in to continue.';
        case 403:
            return 'Your office does not have permission to perform this action.';
        case 404:
            return 'The requested record could not be found.';
        case 429:
            return 'Too many attempts. Wait a moment before trying again.';
        default:
            return 'The SAFERNET service is temporarily unavailable. Try again shortly.';
    }
}

export async function request(path, { method = 'GET', body, query, signal } = {}) {
    const token = tokenStore.read();
    const hasBody = body !== undefined && body !== null;
    const isFormData = typeof FormData !== 'undefined' && body instanceof FormData;

    const response = await fetch(buildUrl(path, query), {
        method,
        signal,
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(hasBody && !isFormData ? { 'Content-Type': 'application/json' } : {}),
            ...(token ? { Authorization: `Bearer ${token}` } : {}),
        },
        body: hasBody ? (isFormData ? body : JSON.stringify(body)) : undefined,
    });

    const payload = response.status === 204 ? null : await response.json().catch(() => null);

    if (!response.ok) {
        if (response.status === 401) {
            onUnauthenticated();
        }

        throw new ApiError(response.status, payload?.message ?? statusMessage(response.status), payload?.errors ?? {});
    }

    return payload;
}

export async function requestText(path, { method = 'GET', query, signal } = {}) {
    const token = tokenStore.read();
    const response = await fetch(buildUrl(path, query), {
        method,
        signal,
        headers: {
            Accept: 'text/html',
            'X-Requested-With': 'XMLHttpRequest',
            ...(token ? { Authorization: `Bearer ${token}` } : {}),
        },
    });

    if (!response.ok) {
        const payload = await response.json().catch(() => null);

        if (response.status === 401) {
            onUnauthenticated();
        }

        throw new ApiError(response.status, payload?.message ?? statusMessage(response.status), payload?.errors ?? {});
    }

    return response.text();
}

export async function requestBlob(path, { method = 'GET', query, signal } = {}) {
    const token = tokenStore.read();
    const response = await fetch(buildUrl(path, query), {
        method,
        signal,
        headers: {
            Accept: 'application/pdf',
            'X-Requested-With': 'XMLHttpRequest',
            ...(token ? { Authorization: `Bearer ${token}` } : {}),
        },
    });

    if (!response.ok) {
        const payload = await response.json().catch(() => null);

        if (response.status === 401) {
            onUnauthenticated();
        }

        throw new ApiError(response.status, payload?.message ?? statusMessage(response.status), payload?.errors ?? {});
    }

    return response.blob();
}

/** Builds the five standard calls for an API resource collection. */
function resource(path) {
    return {
        list: (query, signal) => request(path, { query, signal }),
        show: (id, signal) => request(`${path}/${id}`, { signal }),
        create: (body) => request(path, { method: 'POST', body }),
        update: (id, body) => {
            if (typeof FormData !== 'undefined' && body instanceof FormData) {
                body.set('_method', 'PUT');

                return request(`${path}/${id}`, { method: 'POST', body });
            }

            return request(`${path}/${id}`, { method: 'PUT', body });
        },
        remove: (id) => request(`${path}/${id}`, { method: 'DELETE' }),
    };
}

export const api = {
    login: (credentials) => request('/auth/login', { method: 'POST', body: credentials }),
    logout: () => request('/auth/logout', { method: 'DELETE' }),
    authContext: (signal) => request('/auth/context', { signal }),
    changeTemporaryPassword: (body) => request('/auth/onboarding/password', { method: 'POST', body }),
    beginMfaSetup: () => request('/auth/onboarding/mfa/setup', { method: 'POST' }),
    confirmMfaSetup: (body) => request('/auth/onboarding/mfa/confirm', { method: 'POST', body }),
    verifyMfa: (body) => request('/auth/mfa/verify', { method: 'POST', body }),
    me: (signal) => request('/me', { signal }),
    sessions: (signal) => request('/auth/sessions', { signal }),
    revokeSession: (id) => request(`/auth/sessions/${id}`, { method: 'DELETE' }),
    revokeOtherSessions: () => request('/auth/sessions/others', { method: 'DELETE' }),

    dashboard: (signal) => request('/dashboard', { signal }),
    protectionSummary: (signal) => request('/reports/protection-summary', { signal }),
    incidentTrend: (query, signal) => request('/reports/incident-trend', { query, signal }),

    subcounties: resource('/subcounties'),
    institutions: resource('/institutions'),
    reviewInstitution: (id, body) => request(`/institutions/${id}/reviews`, { method: 'POST', body }),

    users: resource('/users'),
    learnerGroups: resource('/learner-groups'),
    learners: resource('/learners'),
    webEvents: (query, signal) => request('/web-events', { query, signal }),
    laboratories: resource('/laboratories'),
    deviceGroups: resource('/device-groups'),
    devices: resource('/devices'),

    assignLearner: (deviceId, body) => request(`/devices/${deviceId}/assignments`, { method: 'POST', body }),
    unassignLearner: (deviceId, assignmentId) =>
        request(`/devices/${deviceId}/assignments/${assignmentId}`, { method: 'DELETE' }),
    startSession: (deviceId, body) => request(`/devices/${deviceId}/sessions`, { method: 'POST', body }),
    endSession: (sessionId) => request(`/learner-sessions/${sessionId}`, { method: 'DELETE' }),

    classrooms: {
        live: (query, signal) => request('/classrooms/live', { query, signal }),
        pushUrl: (body) => request('/classrooms/push-url', { method: 'POST', body }),
        nudge: (body) => request('/classrooms/nudge', { method: 'POST', body }),
        focusMode: (body) => request('/classrooms/focus-mode', { method: 'POST', body }),
    },

    filteringPolicies: resource('/filtering-policies'),
    policyRules: (policyId) => resource(`/filtering-policies/${policyId}/rules`),

    incidents: resource('/incidents'),
    incidentReport: (id, query, signal) => requestBlob(`/incidents/${id}/report`, { query, signal }),
    recordIncidentAction: (incidentId, body) => request(`/incidents/${incidentId}/actions`, { method: 'POST', body }),

    exceptionRequests: resource('/exception-requests'),
    reviewExceptionRequest: (id, body) => request(`/exception-requests/${id}/reviews`, { method: 'POST', body }),

    protectionComponents: (query, signal) => request('/protection-components', { query, signal }),
    securityEvents: (query, signal) => request('/security-events', { query, signal }),
    contentCategories: (query, signal) => request('/content-categories', { query, signal }),
    auditLogs: (query, signal) => request('/audit-logs', { query, signal }),

    blocklistSources: (query, signal) => request('/blocklist-sources', { query, signal }),
    updateBlocklistSource: (id, body) => request(`/blocklist-sources/${id}`, { method: 'PUT', body }),
    syncBlocklistSource: (id) => request(`/blocklist-sources/${id}/sync`, { method: 'POST' }),
    integrations: (signal) => request('/integrations', { signal }),
    notificationDeliveries: (query, signal) => request('/notification-deliveries', { query, signal }),

    notifications: (query, signal) => request('/notifications', { query, signal }),
    markNotificationRead: (id) => request(`/notifications/${id}`, { method: 'PUT' }),
    deleteNotification: (id) => request(`/notifications/${id}`, { method: 'DELETE' }),
};
