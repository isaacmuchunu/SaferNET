import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { AppShell, RequireAuth, RequireCapability } from './components/AppShell';
import { ToastViewport } from './components/ToastViewport';
import { AuthProvider } from './lib/auth';
import { ScopeProvider } from './lib/scope';
import { ApiError } from './lib/api';

import { SignInPage } from './pages/auth/SignInPage';
import { PasswordSetupPage } from './pages/auth/PasswordSetupPage';
import { MfaSetupPage } from './pages/auth/MfaSetupPage';
import { MfaChallengePage } from './pages/auth/MfaChallengePage';
import { DashboardPage } from './pages/DashboardPage';
import { SubcountiesPage } from './pages/SubcountiesPage';
import { SubcountyPage } from './pages/SubcountyPage';
import { SchoolsPage } from './pages/SchoolsPage';
import { SchoolPage } from './pages/SchoolPage';
import { RegisterSchoolPage } from './pages/RegisterSchoolPage';
import { ApprovalsPage } from './pages/ApprovalsPage';
import { AdministratorsPage } from './pages/AdministratorsPage';
import { LearnersPage } from './pages/LearnersPage';
import { DevicesPage } from './pages/DevicesPage';
import { LaboratoriesPage } from './pages/LaboratoriesPage';
import { ClassroomLivePage } from './pages/ClassroomLivePage';
import { PoliciesPage } from './pages/PoliciesPage';
import { CategoriesPage } from './pages/CategoriesPage';
import { BlocklistsPage } from './pages/BlocklistsPage';
import { DomainReviewPage } from './pages/DomainReviewPage';
import { IncidentsPage } from './pages/IncidentsPage';
import { IncidentPage } from './pages/IncidentPage';
import { AlertsPage } from './pages/AlertsPage';
import { ExceptionsPage } from './pages/ExceptionsPage';
import { DeploymentAuditPage } from './pages/DeploymentAuditPage';
import { ReportsPage } from './pages/ReportsPage';
import { SettingsPage } from './pages/SettingsPage';

const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            staleTime: 30_000,
            refetchOnWindowFocus: false,
            /** Authorisation and validation failures are final — retry transport faults only. */
            retry: (failureCount, error) => !(error instanceof ApiError && error.status < 500) && failureCount < 2,
        },
    },
});

function Portal() {
    return (
        <Routes>
            <Route path="/signin" element={<SignInPage />} />
            <Route path="/onboarding/password" element={<PasswordSetupPage />} />
            <Route path="/onboarding/mfa" element={<MfaSetupPage />} />
            <Route path="/mfa" element={<MfaChallengePage />} />

            <Route element={<RequireAuth />}>
                <Route element={<AppShell />}>
                    <Route index element={<DashboardPage />} />

                    <Route element={<RequireCapability capability="viewSubcounties" />}>
                        <Route path="subcounties" element={<SubcountiesPage />} />
                        <Route path="subcounties/:subcountyId" element={<SubcountyPage />} />
                    </Route>

                    <Route element={<RequireCapability capability="registerSchools" />}>
                        <Route path="schools/register" element={<RegisterSchoolPage />} />
                    </Route>

                    <Route element={<RequireCapability capability="viewSchoolRegister" />}>
                        <Route path="schools" element={<SchoolsPage />} />
                    </Route>
                    <Route path="schools/:schoolId" element={<SchoolPage />} />

                    <Route element={<RequireCapability capability="reviewRegistrations" />}>
                        <Route path="approvals" element={<ApprovalsPage />} />
                    </Route>

                    <Route element={<RequireCapability capability="manageOfficers" />}>
                        <Route path="administrators" element={<AdministratorsPage />} />
                    </Route>

                    <Route element={<RequireCapability capability="viewReports" />}>
                        <Route path="reports" element={<ReportsPage />} />
                    </Route>

                    <Route element={<RequireCapability capability="viewAudit" />}>
                        <Route path="audit" element={<Navigate to="/deployment?view=audit" replace />} />
                    </Route>

                    <Route element={<RequireCapability capability="viewLearners" />}>
                        <Route path="learners" element={<LearnersPage />} />
                    </Route>

                    <Route element={<RequireCapability capability="viewDevices" />}>
                        <Route path="devices" element={<DevicesPage />} />
                    </Route>

                    <Route element={<RequireCapability capability="viewLaboratories" />}>
                        <Route path="laboratories" element={<LaboratoriesPage />} />
                    </Route>

                    <Route element={<RequireCapability capability="viewClassroomLive" />}>
                        <Route path="classroom" element={<ClassroomLivePage />} />
                    </Route>

                    <Route element={<RequireCapability capability="viewPolicies" />}>
                        <Route path="policies" element={<PoliciesPage />} />
                        <Route path="categories" element={<CategoriesPage />} />
                        <Route path="blocklists" element={<BlocklistsPage />} />
                    </Route>

                    <Route element={<RequireCapability capability="reviewDomains" />}>
                        <Route path="domain-reviews" element={<DomainReviewPage />} />
                    </Route>

                    <Route element={<RequireCapability capability="viewIncidents" />}>
                        <Route path="incidents" element={<IncidentsPage />} />
                        <Route path="incidents/:incidentId" element={<IncidentPage />} />
                    </Route>

                    <Route element={<RequireCapability capability="viewDeployment" />}>
                        <Route path="deployment" element={<DeploymentAuditPage />} />
                    </Route>

                    <Route path="alerts" element={<AlertsPage />} />
                    <Route path="exceptions" element={<ExceptionsPage />} />
                    <Route path="settings" element={<SettingsPage />} />

                    <Route path="*" element={<Navigate to="/" replace />} />
                </Route>
            </Route>

            <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
    );
}

createRoot(document.getElementById('safernet-root')).render(
    <StrictMode>
        <QueryClientProvider client={queryClient}>
            <AuthProvider>
                <ScopeProvider>
                    <BrowserRouter>
                        <Portal />
                        <ToastViewport />
                    </BrowserRouter>
                </ScopeProvider>
            </AuthProvider>
        </QueryClientProvider>
    </StrictMode>,
);
