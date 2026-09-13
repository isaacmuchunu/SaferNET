import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from './api';

const keepPrevious = (previous) => previous;

/** Paginated list hook for an API resource. */
function useCollection(key, fetcher, params = {}, options = {}) {
    return useQuery({
        queryKey: [key, params],
        queryFn: ({ signal }) => fetcher(params, signal),
        placeholderData: keepPrevious,
        ...options,
    });
}

/** Single-record hook for an API resource. */
function useRecord(key, fetcher, id, options = {}) {
    return useQuery({
        queryKey: [key, 'detail', String(id ?? '')],
        queryFn: ({ signal }) => fetcher(id, signal).then((payload) => payload.data),
        enabled: Boolean(id),
        ...options,
    });
}

export const useDashboard = () =>
    useQuery({ queryKey: ['dashboard'], queryFn: ({ signal }) => api.dashboard(signal).then((p) => p.data) });

export const useProtectionSummary = () =>
    useQuery({ queryKey: ['protection-summary'], queryFn: ({ signal }) => api.protectionSummary(signal).then((p) => p.data) });

export const useIncidentTrend = (params = {}) =>
    useQuery({
        queryKey: ['incident-trend', params],
        queryFn: ({ signal }) => api.incidentTrend(params, signal).then((p) => p.data),
        placeholderData: keepPrevious,
    });

export const useSubcounties = (params, options) => useCollection('subcounties', api.subcounties.list, params, options);
export const useSubcounty = (id) => useRecord('subcounties', api.subcounties.show, id);

export const useInstitutions = (params, options) => useCollection('institutions', api.institutions.list, params, options);
export const useInstitution = (id) => useRecord('institutions', api.institutions.show, id);

export const useUsers = (params, options) => useCollection('users', api.users.list, params, options);
export const useLearners = (params, options) => useCollection('learners', api.learners.list, params, options);
export const useLearnerGroups = (params, options) => useCollection('learner-groups', api.learnerGroups.list, params, options);

/** One learner with their devices, sessions and activity totals. */
export const useLearner = (id, options) =>
    useQuery({
        queryKey: ['learner', id],
        queryFn: ({ signal }) => api.learners.show(id, signal).then((p) => p.data),
        enabled: Boolean(id),
        ...options,
    });
export const useLaboratories = (params, options) => useCollection('laboratories', api.laboratories.list, params, options);
export const useDeviceGroups = (params, options) => useCollection('device-groups', api.deviceGroups.list, params, options);
export const useDevices = (params, options) => useCollection('devices', api.devices.list, params, options);
export const useIncidents = (params, options) => useCollection('incidents', api.incidents.list, params, options);
export const useIncident = (id) => useRecord('incidents', api.incidents.show, id);
export const useExceptionRequests = (params, options) => useCollection('exception-requests', api.exceptionRequests.list, params, options);
export const useFilteringPolicies = (params) => useCollection('filtering-policies', api.filteringPolicies.list, params);
export const useContentCategories = (params, options) => useCollection('content-categories', api.contentCategories, params, options);
export const useProtectionComponents = (params) => useCollection('protection-components', api.protectionComponents, params);
export const useSecurityEvents = (params) => useCollection('security-events', api.securityEvents, params);
export const useAuditLogs = (params, options) => useCollection('audit-logs', api.auditLogs, params, options);
export const useBlocklistSources = (params, options) => useCollection('blocklist-sources', api.blocklistSources, params, options);
export const useNotificationDeliveries = (params, options) =>
    useCollection('notification-deliveries', api.notificationDeliveries, params, options);

export const useIntegrations = () =>
    useQuery({ queryKey: ['integrations'], queryFn: ({ signal }) => api.integrations(signal).then((p) => p.data) });

export const useSessions = () =>
    useQuery({ queryKey: ['auth-sessions'], queryFn: ({ signal }) => api.sessions(signal).then((p) => p.data) });

export const useRevokeSession = () => useInvalidatingMutation((id) => api.revokeSession(id), ['auth-sessions']);
export const useRevokeOtherSessions = () => useInvalidatingMutation(() => api.revokeOtherSessions(), ['auth-sessions']);

export const useSyncBlocklistSource = () =>
    useInvalidatingMutation((id) => api.syncBlocklistSource(id), ['blocklist-sources', 'integrations']);

export const useToggleBlocklistSource = () =>
    useInvalidatingMutation(({ id, enabled }) => api.updateBlocklistSource(id, { is_enabled: enabled }), [
        'blocklist-sources',
        'integrations',
    ]);

export const useNotifications = (params, options) => useCollection('notifications', api.notifications, params, options);

export const usePolicyRules = (policyId) =>
    useQuery({
        queryKey: ['policy-rules', String(policyId ?? '')],
        queryFn: ({ signal }) => api.policyRules(policyId).list({}, signal),
        enabled: Boolean(policyId),
    });

/**
 * Wraps a mutation so that every collection it touches is refreshed, and the
 * county overview stays in step with the register.
 */
function useInvalidatingMutation(mutationFn, keys = []) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn,
        onSuccess: () => {
            [...keys, 'dashboard'].forEach((key) => queryClient.invalidateQueries({ queryKey: [key] }));
        },
    });
}

export const useCreateSubcounty = () => useInvalidatingMutation((body) => api.subcounties.create(body), ['subcounties']);

export const useCreateInstitution = () =>
    useInvalidatingMutation((body) => api.institutions.create(body), ['institutions', 'subcounties']);

export const useReviewInstitution = () =>
    useInvalidatingMutation(({ id, ...body }) => api.reviewInstitution(id, body), ['institutions', 'audit-logs']);

export const useSaveUser = () =>
    useInvalidatingMutation(({ id, ...body }) => {
        const hasAvatar = typeof File !== 'undefined' && body.avatar instanceof File;

        if (hasAvatar) {
            const form = new FormData();
            Object.entries(body).forEach(([key, value]) => {
                if (value !== undefined && value !== null) {
                    form.append(key, value);
                }
            });

            return id ? api.users.update(id, form) : api.users.create(form);
        }

        delete body.avatar;

        return id ? api.users.update(id, body) : api.users.create(body);
    }, ['users']);

export const useDeleteUser = () => useInvalidatingMutation((id) => api.users.remove(id), ['users']);

export const useSaveLearner = () =>
    useInvalidatingMutation(
        ({ id, ...body }) => (id ? api.learners.update(id, body) : api.learners.create(body)),
        ['learners'],
    );

export const useDeleteLearner = () => useInvalidatingMutation((id) => api.learners.remove(id), ['learners']);

export const useSaveLearnerGroup = () =>
    useInvalidatingMutation(
        ({ id, ...body }) => (id ? api.learnerGroups.update(id, body) : api.learnerGroups.create(body)),
        ['learner-groups'],
    );

export const useSaveLaboratory = () =>
    useInvalidatingMutation(
        ({ id, ...body }) => (id ? api.laboratories.update(id, body) : api.laboratories.create(body)),
        ['laboratories'],
    );

export const useSaveDeviceGroup = () =>
    useInvalidatingMutation(
        ({ id, ...body }) => (id ? api.deviceGroups.update(id, body) : api.deviceGroups.create(body)),
        ['device-groups'],
    );

export const useSaveDevice = () =>
    useInvalidatingMutation(({ id, ...body }) => (id ? api.devices.update(id, body) : api.devices.create(body)), ['devices']);

export const useDeleteDevice = () => useInvalidatingMutation((id) => api.devices.remove(id), ['devices']);

export const useDeleteLearnerGroup = () =>
    useInvalidatingMutation((id) => api.learnerGroups.remove(id), ['learner-groups', 'learners']);

export const useDeleteLaboratory = () => useInvalidatingMutation((id) => api.laboratories.remove(id), ['laboratories']);

export const useEndSession = () => useInvalidatingMutation((sessionId) => api.endSession(sessionId), ['devices']);

export const useAssignLearner = () =>
    useInvalidatingMutation(({ deviceId, learnerId }) => api.assignLearner(deviceId, { learner_id: learnerId }), ['devices']);

export const useUnassignLearner = () =>
    useInvalidatingMutation(({ deviceId, assignmentId }) => api.unassignLearner(deviceId, assignmentId), ['devices']);

export const useStartSession = () =>
    useInvalidatingMutation(
        ({ deviceId, learnerId, identitySource, pin }) =>
            api.startSession(deviceId, { learner_id: learnerId, identity_source: identitySource, pin }),
        ['devices'],
    );

export const useUpdateIncident = () =>
    useInvalidatingMutation(({ id, ...body }) => api.incidents.update(id, body), ['incidents']);

export const useRecordIncidentAction = () =>
    useInvalidatingMutation(({ id, ...body }) => api.recordIncidentAction(id, body), ['incidents']);

export const useCreateExceptionRequest = () =>
    useInvalidatingMutation((body) => api.exceptionRequests.create(body), ['exception-requests']);

export const useReviewExceptionRequest = () =>
    useInvalidatingMutation(({ id, ...body }) => api.reviewExceptionRequest(id, body), ['exception-requests']);

export const useSaveFilteringPolicy = () =>
    useInvalidatingMutation(
        ({ id, ...body }) => (id ? api.filteringPolicies.update(id, body) : api.filteringPolicies.create(body)),
        ['filtering-policies'],
    );

export const useSavePolicyRule = () =>
    useInvalidatingMutation(
        ({ policyId, id, ...body }) =>
            id ? api.policyRules(policyId).update(id, body) : api.policyRules(policyId).create(body),
        ['policy-rules', 'filtering-policies'],
    );

export const useDeletePolicyRule = () =>
    useInvalidatingMutation(({ policyId, id }) => api.policyRules(policyId).remove(id), [
        'policy-rules',
        'filtering-policies',
    ]);

export const useMarkNotificationRead = () => useInvalidatingMutation((id) => api.markNotificationRead(id), ['notifications']);
export const useDeleteNotification = () => useInvalidatingMutation((id) => api.deleteNotification(id), ['notifications']);

export const useClassroomLive = (params, options) =>
    useQuery({
        queryKey: ['classrooms-live', params],
        queryFn: ({ signal }) => api.classrooms.live(params, signal).then((p) => p.data),
        refetchInterval: options?.refetchInterval ?? 8000,
        ...options,
    });

/**
 * A learner's browsing history. Only fetched when a panel is actually open —
 * this is sensitive data about a named child, so it is not pre-loaded for
 * every tile on the page.
 */
export const useWebEvents = (params, options) =>
    useQuery({
        queryKey: ['web-events', params],
        queryFn: ({ signal }) => api.webEvents(params, signal).then((p) => p),
        ...options,
    });

export const useClassroomPushUrl = () =>
    useInvalidatingMutation((body) => api.classrooms.pushUrl(body), ['classrooms-live']);

export const useClassroomNudge = () =>
    useInvalidatingMutation((body) => api.classrooms.nudge(body), ['classrooms-live']);

export const useClassroomFocusMode = () =>
    useInvalidatingMutation((body) => api.classrooms.focusMode(body), ['classrooms-live']);
