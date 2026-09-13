/**
 * What each office may do, in one place.
 *
 * The division of duty is deliberate: the Head of Institution governs and acts
 * on what SAFERNET reports, while the Computer Laboratory Manager deploys,
 * maintains and troubleshoots it. The API enforces the same split — this map
 * only decides what an officer is offered.
 */

const BASE = {
    // Scope
    isCounty: false,
    isSubcounty: false,
    isSchool: false,

    // Organisation
    viewSubcounties: false,
    manageSubcounties: false,
    viewSchoolRegister: false,
    registerSchools: false,
    reviewRegistrations: false,
    manageOfficers: false,
    assignableRoles: [],

    // Learner register
    viewLearners: false,
    createLearners: false,
    editLearners: false,
    deleteLearners: false,
    manageLearnerGroups: false,

    // Devices and deployment
    viewDevices: false,
    registerDevices: false,
    decommissionDevices: false,
    assignLearners: false,
    manageSessions: false,
    viewLaboratories: false,
    manageLaboratories: false,
    viewClassroomLive: false,

    // Filtering
    viewPolicies: false,
    managePolicies: false,

    // Safety
    viewIncidents: false,
    recordIncidentActions: false,
    submitExceptions: false,
    reviewExceptions: false,

    // System
    viewDeployment: false,
    viewReports: false,
    viewAudit: false,
};

const ROLE_CAPABILITIES = {
    /** County Director of Education — county-wide governance. */
    cde: {
        isCounty: true,
        viewSubcounties: true,
        manageSubcounties: true,
        viewSchoolRegister: true,
        registerSchools: true,
        reviewRegistrations: true,
        manageOfficers: true,
        assignableRoles: ['cde', 'scde', 'hoi', 'clm'],
        viewLearners: true,
        createLearners: true,
        editLearners: true,
        deleteLearners: true,
        manageLearnerGroups: true,
        viewDevices: true,
        registerDevices: true,
        decommissionDevices: true,
        assignLearners: true,
        manageSessions: true,
        viewLaboratories: true,
        manageLaboratories: true,
        viewPolicies: true,
        managePolicies: true,
        viewIncidents: true,
        recordIncidentActions: true,
        submitExceptions: true,
        reviewExceptions: true,
        viewDeployment: true,
        viewReports: true,
        viewAudit: true,
    },

    /** Sub-County Director — supervises the schools of one sub-county. */
    scde: {
        isSubcounty: true,
        viewSubcounties: true,
        viewSchoolRegister: true,
        registerSchools: true,
        manageOfficers: true,
        assignableRoles: ['hoi', 'clm'],
        viewLearners: true,
        viewDevices: true,
        viewLaboratories: true,
        viewPolicies: true,
        viewIncidents: true,
        submitExceptions: true,
        reviewExceptions: true,
        viewDeployment: true,
        viewReports: true,
        viewAudit: true,
    },

    /** Head of Institution — the school's accountable authority. */
    hoi: {
        isSchool: true,
        manageOfficers: true,
        assignableRoles: ['clm'],
        viewLearners: true,
        createLearners: true,
        editLearners: true,
        deleteLearners: true,
        manageLearnerGroups: true,
        viewDevices: true,
        assignLearners: true,
        manageSessions: true,
        viewLaboratories: true,
        viewClassroomLive: true,
        viewPolicies: true,
        managePolicies: true,
        viewIncidents: true,
        recordIncidentActions: true,
        submitExceptions: true,
        reviewExceptions: true,
        viewDeployment: true,
        viewReports: true,
        viewAudit: true,
    },

    /** Computer Laboratory Manager — the school's technical operator. */
    clm: {
        isSchool: true,
        viewLearners: true,
        createLearners: true,
        editLearners: true,
        manageLearnerGroups: true,
        viewDevices: true,
        registerDevices: true,
        decommissionDevices: true,
        assignLearners: true,
        manageSessions: true,
        viewLaboratories: true,
        manageLaboratories: true,
        viewClassroomLive: true,
        viewPolicies: true,
        viewIncidents: true,
        submitExceptions: true,
        viewDeployment: true,
        viewReports: true,
    },
};

/** @returns {typeof BASE} */
export function capabilitiesFor(role) {
    return { ...BASE, ...(ROLE_CAPABILITIES[role] ?? {}) };
}

/**
 * One line describing what this office is for, shown on the dashboard and the
 * account page so officers understand the boundary of their duties.
 */
export const ROLE_REMIT = {
    cde: 'Governs SAFERNET across Kiambu County: county policy, school approvals and county-wide reporting.',
    scde: 'Supervises the schools of the sub-county: registrations, officer accounts and protection oversight.',
    hoi: 'Governs SAFERNET in the school: learner records, filtering decisions and action on what SAFERNET reports.',
    clm: 'Operates SAFERNET in the school: laboratories, device enrolment, learner attribution and endpoint health.',
};
