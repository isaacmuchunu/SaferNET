import { useState } from 'react';
import {
    ActivityIcon,
    AlertTriangleIcon,
    CheckCircle2Icon,
    ExternalLinkIcon,
    EyeIcon,
    FilterIcon,
    GlobeIcon,
    LaptopIcon,
    LockIcon,
    MonitorIcon,
    PauseIcon,
    PlayIcon,
    PlusIcon,
    RadioIcon,
    RefreshCwIcon,
    SendIcon,
    ShieldAlertIcon,
    SparklesIcon,
    UnlockIcon,
    UsersIcon,
} from 'lucide-react';
import { PageHeader } from '../components/PageHeader';
import {
    Button,
    EmptyState,
    Field,
    FilterSelect,
    Panel,
    PanelHeader,
    Skeleton,
    TextInput,
} from '../components/Primitives';
import { Drawer } from '../components/Overlays';
import { StatusPill } from '../components/StatusPill';
import {
    useClassroomFocusMode,
    useClassroomLive,
    useClassroomNudge,
    useClassroomPushUrl,
} from '../lib/queries';
import { useAuth } from '../lib/auth';
import { useScope } from '../lib/scope';
import { formatNumber, formatRelative } from '../lib/format';
import { showToast, toastError } from '../lib/toast';

export function ClassroomLivePage() {
    const { user } = useAuth();
    const scope = useScope();

    const [selectedLab, setSelectedLab] = useState('');
    const [autoRefresh, setAutoRefresh] = useState(true);
    const [pushModalOpen, setPushModalOpen] = useState(false);
    const [targetUrl, setTargetUrl] = useState('https://khanacademy.org');
    const [targetTitle, setTargetTitle] = useState('Lesson Resource');
    const [nudgeMessage, setNudgeMessage] = useState('Please focus on the classroom lesson.');

    const queryParams = {
        laboratory_id: selectedLab || undefined,
        institution_id: scope.institution?.id || undefined,
    };

    const liveQuery = useClassroomLive(queryParams, {
        refetchInterval: autoRefresh ? 8000 : false,
    });

    const pushUrlMutation = useClassroomPushUrl();
    const nudgeMutation = useClassroomNudge();
    const focusModeMutation = useClassroomFocusMode();

    const data = liveQuery.data ?? {};
    const laboratories = data.laboratories ?? [];
    const tiles = data.tiles ?? [];
    const activeBroadcast = data.active_broadcast;

    // Derived stats
    const totalTiles = tiles.length;
    const onTaskCount = tiles.filter((t) => t.status === 'on_task').length;
    const offTaskCount = tiles.filter((t) => t.status === 'off_task').length;
    const lockedCount = tiles.filter((t) => t.status === 'locked').length;
    const avgFocusScore = totalTiles > 0
        ? Math.round(tiles.reduce((acc, t) => acc + (t.focus_score || 100), 0) / totalTiles)
        : 100;

    const isRoomLocked = lockedCount > 0 && selectedLab !== '';

    async function handlePushUrl(e) {
        e?.preventDefault();
        try {
            await pushUrlMutation.mutateAsync({
                url: targetUrl,
                title: targetTitle,
                laboratory_id: selectedLab ? Number(selectedLab) : null,
            });
            showToast('Resource Broadcast Sent', {
                description: `Pushed "${targetTitle}" (${targetUrl}) to active student workstations.`,
            });
            setPushModalOpen(false);
        } catch (error) {
            toastError(error, 'Could not push resource URL');
        }
    }

    async function handleSendNudge() {
        try {
            await nudgeMutation.mutateAsync({
                message: nudgeMessage,
                laboratory_id: selectedLab ? Number(selectedLab) : null,
            });
            showToast('Attention Nudge Sent', {
                description: 'Broadcasted focus reminder to student browsers.',
            });
        } catch (error) {
            toastError(error, 'Could not send attention reminder');
        }
    }

    async function handleToggleFocusMode() {
        if (!selectedLab) {
            showToast('Select a laboratory first', {
                description: 'Focus mode is applied per laboratory room.',
                tone: 'warning',
            });
            return;
        }

        try {
            const nextState = !isRoomLocked;
            await focusModeMutation.mutateAsync({
                laboratory_id: Number(selectedLab),
                locked: nextState,
            });
            showToast(nextState ? 'Focus Mode Activated' : 'Focus Mode Released', {
                description: nextState
                    ? 'Student browsers locked to lesson resources.'
                    : 'Workstations restored to standard filtering policies.',
            });
        } catch (error) {
            toastError(error, 'Could not toggle focus mode');
        }
    }

    return (
        <div className="animate-fade-up space-y-6 pb-12">
            <PageHeader
                title="Classroom Live Monitor"
                description="Real-time learner activity, focus scores and synchronous classroom controls for laboratory sessions."
                meta={
                    <span className="inline-flex items-center gap-2 font-mono text-xs text-text-muted">
                        <span className="relative flex h-2 w-2">
                            <span className={`absolute inline-flex h-full w-full animate-ping rounded-full opacity-75 ${autoRefresh ? 'bg-success' : 'bg-text-muted'}`} />
                            <span className={`relative inline-flex h-2 w-2 rounded-full ${autoRefresh ? 'bg-success' : 'bg-text-muted'}`} />
                        </span>
                        {autoRefresh ? 'Live Sync Active (8s)' : 'Sync Paused'}
                    </span>
                }
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        <Button
                            variant="secondary"
                            icon={autoRefresh ? PauseIcon : PlayIcon}
                            onClick={() => setAutoRefresh(!autoRefresh)}
                        >
                            {autoRefresh ? 'Pause' : 'Resume'}
                        </Button>
                        <Button
                            variant="secondary"
                            icon={RefreshCwIcon}
                            disabled={liveQuery.isFetching}
                            onClick={() => liveQuery.refetch()}
                        >
                            Refresh
                        </Button>
                        <Button
                            variant="primary"
                            icon={SendIcon}
                            onClick={() => setPushModalOpen(true)}
                        >
                            Push Resource URL
                        </Button>
                    </div>
                }
            />

            {/* Top Control & Posture Strip */}
            <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                <div className="rounded-xl border border-border bg-white p-4 shadow-sm">
                    <div className="flex items-center justify-between">
                        <span className="text-xs font-medium text-text-muted uppercase">Active Workstations</span>
                        <LaptopIcon className="h-4 w-4 text-brand" />
                    </div>
                    <div className="mt-2 flex items-baseline gap-2">
                        <span className="text-2xl font-bold tracking-tight text-text">{formatNumber(totalTiles)}</span>
                        <span className="text-xs text-text-muted">learners online</span>
                    </div>
                    <div className="mt-2 text-[11px] text-text-muted">
                        Across {selectedLab ? 'selected lab' : `${laboratories.length} laboratories`}
                    </div>
                </div>

                <div className="rounded-xl border border-border bg-white p-4 shadow-sm">
                    <div className="flex items-center justify-between">
                        <span className="text-xs font-medium text-text-muted uppercase">On-Task Compliance</span>
                        <CheckCircle2Icon className="h-4 w-4 text-success" />
                    </div>
                    <div className="mt-2 flex items-baseline gap-2">
                        <span className="text-2xl font-bold tracking-tight text-text">{avgFocusScore}%</span>
                        <span className="text-xs font-medium text-success">
                            {onTaskCount} on task
                        </span>
                    </div>
                    <div className="mt-2 text-[11px] text-text-muted">
                        {offTaskCount > 0 ? `${offTaskCount} visiting non-lesson domains` : 'All sessions within educational scope'}
                    </div>
                </div>

                <div className="rounded-xl border border-border bg-white p-4 shadow-sm">
                    <div className="flex items-center justify-between">
                        <span className="text-xs font-medium text-text-muted uppercase">Classroom Nudge</span>
                        <SparklesIcon className="h-4 w-4 text-amber-500" />
                    </div>
                    <div className="mt-2">
                        <Button
                            size="sm"
                            variant="secondary"
                            icon={SendIcon}
                            className="w-full justify-center text-xs"
                            onClick={handleSendNudge}
                            disabled={nudgeMutation.isPending}
                        >
                            {nudgeMutation.isPending ? 'Sending...' : 'Broadcast Focus Nudge'}
                        </Button>
                    </div>
                    <div className="mt-2 text-[11px] text-text-muted">
                        Displays gentle reminder on student browsers
                    </div>
                </div>

                <div className="rounded-xl border border-border bg-white p-4 shadow-sm">
                    <div className="flex items-center justify-between">
                        <span className="text-xs font-medium text-text-muted uppercase">Focus Mode Lock</span>
                        <LockIcon className={`h-4 w-4 ${isRoomLocked ? 'text-danger' : 'text-text-muted'}`} />
                    </div>
                    <div className="mt-2">
                        <Button
                            size="sm"
                            variant={isRoomLocked ? 'danger' : 'secondary'}
                            icon={isRoomLocked ? UnlockIcon : LockIcon}
                            className="w-full justify-center text-xs"
                            onClick={handleToggleFocusMode}
                            disabled={focusModeMutation.isPending || !selectedLab}
                        >
                            {isRoomLocked ? 'Release Screen Lock' : 'Lock Room Screens'}
                        </Button>
                    </div>
                    <div className="mt-2 text-[11px] text-text-muted">
                        {selectedLab ? (isRoomLocked ? 'Room currently restricted' : 'Restricts tabs to lesson URLs') : 'Select a lab room to lock'}
                    </div>
                </div>
            </div>

            {/* Filter Bar */}
            <div className="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-border bg-white p-3.5 shadow-sm">
                <div className="flex items-center gap-3">
                    <FilterIcon className="h-4 w-4 text-text-muted" />
                    <span className="text-xs font-semibold text-text-muted uppercase">Filter Laboratory:</span>
                    <FilterSelect
                        label={`All laboratories (${laboratories.length})`}
                        value={selectedLab}
                        onChange={setSelectedLab}
                        options={laboratories.map((lab) => [String(lab.id), lab.name])}
                    />
                </div>

                {activeBroadcast && (
                    <div className="flex items-center gap-2 rounded-lg bg-brand-soft px-3 py-1 text-xs font-medium text-brand">
                        <RadioIcon className="h-3.5 w-3.5 animate-pulse" />
                        <span>Active Broadcast: <strong>{activeBroadcast.title}</strong></span>
                        {activeBroadcast.url && (
                            <a
                                href={activeBroadcast.url}
                                target="_blank"
                                rel="noreferrer"
                                className="inline-flex items-center gap-1 underline"
                            >
                                {activeBroadcast.url}
                                <ExternalLinkIcon className="h-3 w-3" />
                            </a>
                        )}
                    </div>
                )}
            </div>

            {/* Live Student Tiles Grid */}
            {liveQuery.isPending ? (
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    {Array.from({ length: 8 }).map((_, i) => (
                        <div key={i} className="h-48 rounded-xl border border-border bg-white p-4 shadow-sm">
                            <Skeleton className="h-5 w-32" />
                            <Skeleton className="mt-3 h-4 w-24" />
                            <Skeleton className="mt-6 h-12 w-full rounded-lg" />
                        </div>
                    ))}
                </div>
            ) : tiles.length === 0 ? (
                <Panel>
                    <EmptyState
                        icon={MonitorIcon}
                        title="No Active Classroom Sessions"
                        description={
                            selectedLab
                                ? 'No learners are currently logged into devices in this laboratory.'
                                : 'Learners sign in via device enrollment or school PIN to populate the live monitor.'
                        }
                    />
                </Panel>
            ) : (
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    {tiles.map((tile) => {
                        const isOffTask = tile.status === 'off_task';
                        const isLocked = tile.status === 'locked';
                        const isIdle = tile.status === 'idle';

                        return (
                            <div
                                key={tile.session_id}
                                className={`group relative flex flex-col justify-between overflow-hidden rounded-xl border bg-white p-4.5 shadow-sm transition-all duration-200 hover:shadow-md ${
                                    isLocked
                                        ? 'border-danger/60 ring-1 ring-danger/30'
                                        : isOffTask
                                        ? 'border-warning/60 ring-1 ring-warning/30'
                                        : 'border-border hover:border-brand/40'
                                }`}
                            >
                                {/* Header: Student Name + Status */}
                                <div>
                                    <div className="flex items-start justify-between gap-2">
                                        <div className="min-w-0">
                                            <h3 className="truncate text-sm font-bold text-text">
                                                {tile.learner_name}
                                            </h3>
                                            <div className="flex items-center gap-1.5 text-[11px] text-text-muted">
                                                <span>{tile.admission_number}</span>
                                                <span>·</span>
                                                <span className="font-mono">{tile.device_name}</span>
                                            </div>
                                        </div>

                                        <span
                                            className={`inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider ${
                                                isLocked
                                                    ? 'bg-danger-soft text-danger'
                                                    : isOffTask
                                                    ? 'bg-warning-soft text-warning-strong'
                                                    : isIdle
                                                    ? 'bg-surface-muted text-text-muted'
                                                    : 'bg-success-soft text-success'
                                            }`}
                                        >
                                            {isLocked ? (
                                                <>
                                                    <LockIcon className="h-2.5 w-2.5" /> Locked
                                                </>
                                            ) : isOffTask ? (
                                                <>
                                                    <AlertTriangleIcon className="h-2.5 w-2.5" /> Off-Task
                                                </>
                                            ) : isIdle ? (
                                                <>
                                                    <ClockIcon className="h-2.5 w-2.5" /> No activity
                                                </>
                                            ) : (
                                                <>
                                                    <CheckCircle2Icon className="h-2.5 w-2.5" /> On Task
                                                </>
                                            )}
                                        </span>
                                    </div>

                                    {/* Workstation Screen Preview Box */}
                                    <div className="mt-3.5 rounded-lg border border-border/70 bg-canvas/60 p-2.5 transition-colors group-hover:bg-brand-soft/20">
                                        <div className="flex items-center gap-1.5 text-[10px] font-medium text-text-muted">
                                            <GlobeIcon className="h-3 w-3 shrink-0 text-brand" />
                                            <span className="truncate font-semibold text-text">{tile.domain || 'Waiting for browser activity'}</span>
                                        </div>
                                        <div className="mt-1 line-clamp-2 text-xs font-normal text-text-secondary">
                                            {tile.active_tab_title || tile.active_url || 'No page has been reported in this session.'}
                                        </div>
                                        <div className="mt-2 flex items-center justify-between text-[10px] text-text-muted">
                                            <span>{tile.category}</span>
                                            <span className="font-mono">{tile.ip_address || 'IP not reported'}</span>
                                        </div>
                                    </div>
                                </div>

                                {/* Footer: Focus Bar & Last Activity */}
                                <div className="mt-4 pt-3 border-t border-border/60">
                                    <div className="flex items-center justify-between text-[11px]">
                                        <span className="text-text-muted">Focus Score</span>
                                        <span className={`font-bold ${tile.focus_score >= 80 ? 'text-success' : tile.focus_score >= 50 ? 'text-warning-strong' : 'text-danger'}`}>
                                            {tile.focus_score}%
                                        </span>
                                    </div>
                                    <div className="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
                                        <div
                                            className={`h-full rounded-full transition-all duration-300 ${
                                                tile.focus_score >= 80 ? 'bg-success' : tile.focus_score >= 50 ? 'bg-warning' : 'bg-danger'
                                            }`}
                                            style={{ width: `${Math.min(100, Math.max(8, tile.focus_score))}%` }}
                                        />
                                    </div>
                                    <div className="mt-2 flex items-center justify-between text-[10px] text-text-muted">
                                        <span>{tile.laboratory_name}</span>
                                        <span>{formatRelative(tile.last_activity_at)}</span>
                                    </div>
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}

            {/* Push Resource URL Drawer */}
            <Drawer
                open={pushModalOpen}
                onClose={() => setPushModalOpen(false)}
                title="Broadcast Learning Resource to Class"
                subtitle="Pushes a designated URL directly to student browser extensions in the laboratory."
            >
                <form onSubmit={handlePushUrl} className="space-y-4">
                    <Field label="Resource Title" required>
                        <TextInput
                            placeholder="e.g. Khan Academy Chemistry Lesson"
                            value={targetTitle}
                            onChange={(e) => setTargetTitle(e.target.value)}
                            required
                        />
                    </Field>

                    <Field label="Resource URL" required>
                        <TextInput
                            type="url"
                            placeholder="https://khanacademy.org/..."
                            value={targetUrl}
                            onChange={(e) => setTargetUrl(e.target.value)}
                            required
                        />
                    </Field>

                    <Field label="Target Laboratory">
                        <FilterSelect
                            label="All laboratories in school"
                            value={selectedLab}
                            onChange={setSelectedLab}
                            className="w-full"
                            options={laboratories.map((lab) => [String(lab.id), lab.name])}
                        />
                    </Field>

                    <div className="flex justify-end gap-2 pt-4">
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() => setPushModalOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant="primary"
                            icon={SendIcon}
                            disabled={pushUrlMutation.isPending}
                        >
                            {pushUrlMutation.isPending ? 'Broadcasting...' : 'Broadcast to Workstations'}
                        </Button>
                    </div>
                </form>
            </Drawer>
        </div>
    );
}
