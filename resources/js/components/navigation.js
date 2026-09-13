import {
    BarChart3Icon,
    Building2Icon,
    ClipboardCheckIcon,
    DatabaseIcon,
    FileCheck2Icon,
    FlaskConicalIcon,
    GraduationCapIcon,
    LayoutDashboardIcon,
    ListChecksIcon,
    MonitorCogIcon,
    NetworkIcon,
    RadioIcon,
    SchoolIcon,
    SettingsIcon,
    ShieldAlertIcon,
    SlidersHorizontalIcon,
    UsersRoundIcon,
} from 'lucide-react';
import { capabilitiesFor } from '../lib/permissions';

/**
 * The sidebar is assembled per office. A laboratory manager is never offered
 * the school register or the audit log; a county director is never offered a
 * "my school" shortcut, because they have none.
 */
export function navigationFor(user) {
    const can = capabilitiesFor(user?.role);
    const schoolId = user?.institution?.id;
    const isScde = user?.role === 'scde';

    const groups = [
        {
            label: 'Overview',
            items: [{ label: 'Dashboard', path: '/', icon: LayoutDashboardIcon }],
        },
        {
            label: 'Organization',
            items: [
                can.viewSubcounties && !isScde && { label: 'Sub-counties', path: '/subcounties', icon: NetworkIcon },
                can.viewSchoolRegister && { label: 'Schools', path: '/schools', icon: Building2Icon },
                !can.viewSchoolRegister && schoolId && { label: 'My School', path: `/schools/${schoolId}`, icon: SchoolIcon },
                can.reviewRegistrations && { label: 'Approvals', path: '/approvals', icon: ClipboardCheckIcon },
                can.manageOfficers && { label: 'Administrators', path: '/administrators', icon: UsersRoundIcon },
                can.viewLearners && { label: 'Learners', path: '/learners', icon: GraduationCapIcon },
            ],
        },
        {
            label: 'Protection',
            items: [
                can.viewDevices && { label: 'Devices', path: '/devices', icon: MonitorCogIcon },
                can.viewLaboratories && { label: 'Laboratories', path: '/laboratories', icon: FlaskConicalIcon },
                can.viewClassroomLive && { label: 'Classroom Live', path: '/classroom', icon: RadioIcon },
                can.viewPolicies && { label: 'Policies', path: '/policies', icon: SlidersHorizontalIcon },
                can.viewPolicies && { label: 'Categories', path: '/categories', icon: ListChecksIcon },
                can.viewPolicies && { label: 'Blocklists', path: '/blocklists', icon: DatabaseIcon },
            ],
        },
        {
            label: 'Safety',
            items: [
                can.viewIncidents && { label: 'Incidents & alerts', path: '/incidents', icon: ShieldAlertIcon },
                { label: 'Exceptions', path: '/exceptions', icon: FileCheck2Icon },
            ],
        },
        {
            label: 'System',
            items: [
                can.viewDeployment && {
                    label: can.viewAudit ? 'Deployment & Audit' : 'Deployment',
                    path: '/deployment',
                    icon: NetworkIcon,
                },
                can.viewReports && { label: 'Reports', path: '/reports', icon: BarChart3Icon },
                { label: 'Settings', path: '/settings', icon: SettingsIcon },
            ],
        },
    ];

    return groups
        .map((group) => ({ ...group, items: group.items.filter(Boolean) }))
        .filter((group) => group.items.length > 0);
}
