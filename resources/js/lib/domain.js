/**
 * Every label the portal shows for a backend enum, defined once.
 */

export const ROLES = {
    cde: { short: 'CDE', label: 'County Director of Education', scope: 'Kiambu County' },
    scde: { short: 'SCDE', label: 'Sub-County Director of Education', scope: 'Sub-county' },
    hoi: { short: 'HOI', label: 'Head of Institution', scope: 'Institution' },
    clm: { short: 'CLM', label: 'Computer Laboratory Manager', scope: 'Institution' },
    service: { short: 'SVC', label: 'Service Account', scope: 'Machine-to-machine' },
};

export const roleLabel = (role) => ROLES[role]?.label ?? 'Portal user';
export const roleShort = (role) => ROLES[role]?.short ?? '—';

/** Institution lifecycle: label, pill tone and what the state means. */
export const INSTITUTION_STATUS = {
    draft: { label: 'Draft', tone: 'neutral', note: 'Returned for correction or not yet submitted.' },
    pending_approval: { label: 'Pending Approval', tone: 'warning', note: 'Awaiting County Director review.' },
    approved: { label: 'Approved', tone: 'info', note: 'Cleared by the County Director of Education.' },
    onboarding: { label: 'Onboarding', tone: 'info', note: 'Officers and laboratories are being enrolled.' },
    deployment_in_progress: { label: 'Deployment', tone: 'info', note: 'Protection components are being installed.' },
    attribution_required: { label: 'Attribution Required', tone: 'warning', note: 'Devices are active without an assigned learner.' },
    protected: { label: 'Protected', tone: 'success', note: 'Filtering is enforced across every enrolled device.' },
    attention_required: { label: 'Attention Required', tone: 'danger', note: 'Protection has degraded and needs officer action.' },
    suspended: { label: 'Suspended', tone: 'danger', note: 'Service withdrawn pending county instruction.' },
    rejected: { label: 'Rejected', tone: 'danger', note: 'Registration declined by the County Director.' },
};

export const institutionStatus = (status) =>
    INSTITUTION_STATUS[status] ?? { label: status ?? 'Unknown', tone: 'neutral', note: '' };

export const DEVICE_STATUS = {
    active: { label: 'Protected', tone: 'success' },
    offline: { label: 'Offline', tone: 'danger' },
    attention_required: { label: 'Attention Required', tone: 'warning' },
    retired: { label: 'Retired', tone: 'neutral' },
};

export const INCIDENT_STATUS = {
    open: { label: 'Open', tone: 'danger' },
    under_review: { label: 'Under Review', tone: 'warning' },
    monitored: { label: 'Monitored', tone: 'info' },
    resolved: { label: 'Resolved', tone: 'success' },
    dismissed: { label: 'Dismissed', tone: 'neutral' },
};

export const SEVERITY = {
    critical: { label: 'Critical', tone: 'danger' },
    high: { label: 'High', tone: 'danger' },
    medium: { label: 'Medium', tone: 'warning' },
    low: { label: 'Low', tone: 'neutral' },
};

export const HEALTH_STATUS = {
    healthy: { label: 'Healthy', tone: 'success' },
    degraded: { label: 'Degraded', tone: 'warning' },
    offline: { label: 'Offline', tone: 'danger' },
    unknown: { label: 'Unknown', tone: 'neutral' },
};

export const COMPONENT_TYPES = {
    gateway: 'Gateway',
    endpoint_agent: 'Endpoint Agent',
    browser_extension: 'Browser Extension',
    dns_filter: 'DNS Filter',
};

export const EXCEPTION_STATUS = {
    pending: { label: 'Pending', tone: 'warning' },
    approved: { label: 'Approved', tone: 'success' },
    rejected: { label: 'Rejected', tone: 'danger' },
};

export const ENFORCEMENT_ACTION = {
    allow: { label: 'Allow', tone: 'success' },
    warn: { label: 'Warn', tone: 'warning' },
    restrict: { label: 'Restrict', tone: 'warning' },
    block: { label: 'Block', tone: 'danger' },
};

export const INSTITUTION_TYPES = {
    primary: 'Primary school',
    junior: 'Junior school',
    secondary: 'Secondary school',
    special: 'Special needs institution',
};

export const OWNERSHIP_TYPES = { public: 'Public', private: 'Private' };

export const LEARNER_STATUS = {
    active: { label: 'Active', tone: 'success' },
    inactive: { label: 'Inactive', tone: 'neutral' },
    graduated: { label: 'Graduated', tone: 'info' },
    transferred: { label: 'Transferred', tone: 'neutral' },
};

export const POLICY_LEVELS = { county: 'County', institution: 'Institution', group: 'Learner group' };
