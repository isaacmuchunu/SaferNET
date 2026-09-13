import { useState } from 'react';
import {
    AlertTriangleIcon,
    CheckCircle2Icon,
    ClockIcon,
    ExternalLinkIcon,
    FilterIcon,
    GlobeIcon,
    LaptopIcon,
    LockIcon,
    MonitorIcon,
    PauseIcon,
    PlayIcon,
    RadioIcon,
    RefreshCwIcon,
    SendIcon,
    SparklesIcon,
    UnlockIcon,
    WifiIcon,
    WifiOffIcon,
} from 'lucide-react';
import { PageHeader } from '../components/PageHeader';
import {
    Button,
    EmptyState,
    Field,
    FilterSelect,
    Meter,
    Panel,
    Skeleton,
    TextInput,
} from '../components/Primitives';
import { Drawer } from '../components/Overlays';
import { BrowsingHistory } from '../components/BrowsingHistory';
import {
    useClassroomFocusMode,
    useClassroomLive,
    useClassroomNudge,
    useClassroomPushUrl,
} from '../lib/queries';
import { useScope } from '../lib/scope';
import { formatNumber, formatRelative } from '../lib/format';
import { showToast, toastError } from '../lib/toast';

// A workstation that has not reported for this long is shown as silent rather
// than as continuing to do whatever it was last seen doing.
const STALE_AFTER_MS = 5 * 60 * 1000;

/** Sort order for the tile grid: what needs a teacher's attention comes first. */
function attentionRank(tile) {
    if (tile.status === 'off_task') return 0;
    if (tile.isStale) return 1;
    if (tile.status === 'locked') return 2;
    if (tile.status === 'idle') return 3;
    return 4;
}

/** How a tile presents: its border, its badge and what the badge says. */
function tileAppearance(tile) {
    if (tile.isStale) {
        return {
            frame: 'border-border bg-surface-muted/40',
            badge: 'bg-surface-muted text-text-muted',
            icon: WifiOffIcon,
            label: 'Not reporting',
        };
    }

    if (tile.status === 'locked') {
        return {
            frame: 'border-danger/60 ring-1 ring-danger/30',
            badge: 'bg-danger-soft text-danger',
            icon: LockIcon,
            label: 'Locked',
        };
    }

    if (tile.status === 'off_task') {
        return {
            frame: 'border-warning/60 ring-1 ring-warning/30',
            badge: 'bg-warning-soft text-warning-strong',
            icon: AlertTriangleIcon,
            label: 'Off task',
        };
    }

    if (tile.status === 'idle') {
        return {
            frame: 'border-border',
            badge: 'bg-surface-muted text-text-muted',
            icon: ClockIcon,
            label: 'No activity yet',
        };
    }

    return {
        frame: 'border-border hover:border-brand/40',
        badge: 'bg-success-soft text-success',
        icon: CheckCircle2Icon,
        label: 'On task',
    };
}

/** "live 4m" / "live 1h 12m" — how long this learner's session has been open. */
function liveFor(seconds) {
    if (seconds === null || seconds === undefined) return null;

    const minutes = Math.floor(seconds / 60);
    if (minutes < 1) return 'just signed in';
    if (minutes < 60) return `live ${minutes}m`;

    return `live ${Math.floor(minutes / 60)}h ${minutes % 60}m`;
}

function focusTone(score) {
    if (score >= 80) return { text: 'text-success', meter: 'good' };
    if (score >= 50) return { text: 'text-warning-strong', meter: 'warning' };
    return { text: 'text-danger', meter: 'danger' };
}

export function ClassroomLivePage() {
    const scope = useScope();

    const [selectedLab, setSelectedLab] = useState('');
    const [autoRefresh, setAutoRefresh] = useState(true);
    const [pushModalOpen, setPushModalOpen] = useState(false);
    const [targetUrl, setTargetUrl] = useState('https://khanacademy.org');
    const [targetTitle, setTargetTitle] = useState('Lesson Resource');
    const [nudgeMessage, setNudgeMessage] = useState('Please focus on the classroom lesson.');
    const [focusUrl, setFocusUrl] = useState('');
    const [historyFor, setHistoryFor] = useState(null);

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
    const activeBroadcast = data.active_broadcast;

    // Focus is state the server holds, with its own target and expiry. Inferring
    // it from how the tiles happen to look would make the button disagree with
    // what the learners' browsers are actually doing.
    const focus = data.focus ?? { locked: false, url: null };
    const isRoomLocked = Boolean(focus.locked);

    // A session that has not reported in a while is not evidence of anything.
    // Presenting its last known page as current activity is how a monitor comes
    // to show a quiet room as fully on task.
    const tiles = (data.tiles ?? []).map((tile) => {
        // The server decides this, so every tile is judged against one clock.
        // The local fallback only covers an older API that does not send it.
        if (typeof tile.is_reporting === 'boolean') {
            return { ...tile, isStale: !tile.is_reporting };
        }

        const lastActivity = tile.last_activity_at ? new Date(tile.last_activity_at) : null;
        const silentMs = lastActivity ? Date.now() - lastActivity.getTime() : Infinity;

        return { ...tile, isStale: silentMs > STALE_AFTER_MS };
    });

    const totalTiles = tiles.length;
    const reporting = tiles.filter((t) => !t.isStale);
    const onTaskCount = reporting.filter((t) => t.status === 'on_task').length;
    const offTaskCount = reporting.filter((t) => t.status === 'off_task').length;
    const staleCount = tiles.length - reporting.length;

    // Averaged over the workstations actually reporting. Including silent ones
    // at their last known score inflates the figure a teacher acts on.
    const avgFocusScore = reporting.length > 0
        ? Math.round(reporting.reduce((acc, t) => acc + (t.focus_score ?? 100), 0) / reporting.length)
        : null;

    // Whatever needs a teacher's attention sorts to the front, so a problem is
    // never below the fold of a full laboratory.
    const orderedTiles = [...tiles].sort(
        (a, b) => attentionRank(a) - attentionRank(b) || (a.focus_score ?? 100) - (b.focus_score ?? 100),
    );

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

        const nextState = !isRoomLocked;
        // A lock confines learners to one origin, so it needs somewhere to
        // confine them to. Locking without a target would leave the room
        // showing as locked while restricting nothing.
        const target = focusUrl.trim() || activeBroadcast?.url || '';

        if (nextState && !target) {
            showToast('Set a lesson URL to lock the room to', {
                description: 'Focus mode confines every workstation to one site. Push a resource first, or type the URL in the focus card.',
                tone: 'warning',
            });
            return;
        }

        try {
            await focusModeMutation.mutateAsync({
                laboratory_id: Number(selectedLab),
                locked: nextState,
                ...(nextState ? { url: target } : {}),
            });
            showToast(nextState ? 'Focus Mode Activated' : 'Focus Mode Released', {
                description: nextState
                    ? `Workstations confined to ${target}.`
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
                        <span className="text-xs text-text-muted">signed in</span>
                    </div>
                    <div className="mt-2 text-[11px] text-text-muted">
                        {staleCount > 0
                            ? `${staleCount} not reporting · ${selectedLab ? 'selected lab' : `${laboratories.length} laboratories`}`
                            : `Across ${selectedLab ? 'selected lab' : `${laboratories.length} laboratories`}`}
                    </div>
                </div>

                <div className="rounded-xl border border-border bg-white p-4 shadow-sm">
                    <div className="flex items-center justify-between">
                        <span className="text-xs font-medium text-text-muted uppercase">On-Task Compliance</span>
                        <CheckCircle2Icon className="h-4 w-4 text-success" />
                    </div>
                    <div className="mt-2 flex items-baseline gap-2">
                        {/* Averaged over reporting workstations only, so a room
                            that has gone quiet does not read as compliant. */}
                        <span className="text-2xl font-bold tracking-tight text-text">
                            {avgFocusScore === null ? '—' : `${avgFocusScore}%`}
                        </span>
                        {avgFocusScore !== null && (
                            <span className="text-xs font-medium text-success">{onTaskCount} on task</span>
                        )}
                    </div>
                    <div className="mt-2 text-[11px] text-text-muted">
                        {avgFocusScore === null
                            ? 'No workstation has reported activity yet'
                            : offTaskCount > 0
                              ? `${offTaskCount} visiting non-lesson domains`
                              : 'All reporting sessions within educational scope'}
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
                    {/* A lock needs somewhere to confine learners to, so the
                        target is set here rather than inferred. */}
                    {selectedLab && !isRoomLocked && (
                        <div className="mt-2">
                            <label htmlFor="focus-url" className="sr-only">
                                Lesson URL to confine workstations to
                            </label>
                            <TextInput
                                id="focus-url"
                                type="url"
                                className="text-xs"
                                placeholder={activeBroadcast?.url || 'https://kicd.ac.ke/lesson'}
                                value={focusUrl}
                                onChange={(e) => setFocusUrl(e.target.value)}
                            />
                        </div>
                    )}
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
                        {!selectedLab
                            ? 'Select a lab room to lock'
                            : isRoomLocked
                              ? <>Confined to <span className="font-medium text-text">{focus.url}</span></>
                              : 'Confines every tab to one lesson origin'}
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

            {/* Live workstation tiles */}
            {liveQuery.isPending ? (
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    {Array.from({ length: 8 }).map((_, i) => (
                        <div key={i} className="rounded-xl border border-border bg-white p-4 shadow-sm">
                            <Skeleton className="h-5 w-32" />
                            <Skeleton className="mt-3 h-4 w-24" />
                            <Skeleton className="mt-4 h-16 w-full rounded-lg" />
                            <Skeleton className="mt-4 h-8 w-full rounded" />
                        </div>
                    ))}
                </div>
            ) : tiles.length === 0 ? (
                <Panel>
                    <EmptyState
                        icon={MonitorIcon}
                        title="No active classroom sessions"
                        description={
                            selectedLab
                                ? 'No learners are currently signed in to devices in this laboratory.'
                                : 'Learners sign in via device enrolment or school PIN to populate the live monitor.'
                        }
                    />
                </Panel>
            ) : (
                <ul className="grid list-none gap-4 p-0 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    {orderedTiles.map((tile) => {
                        const look = tileAppearance(tile);
                        const BadgeIcon = look.icon;
                        const score = tile.focus_score ?? 100;
                        const tone = focusTone(score);

                        return (
                            <li
                                key={tile.session_id}
                                className={`group flex min-w-0 flex-col justify-between rounded-xl border bg-white p-4 shadow-sm transition-all duration-200 hover:shadow-md ${look.frame}`}
                            >
                                <div className="min-w-0">
                                    <div className="flex items-start justify-between gap-2">
                                        <div className="min-w-0">
                                            <h3 className="truncate text-sm font-bold text-text" title={tile.learner_name}>
                                                {tile.learner_name}
                                            </h3>
                                            <p className="mt-0.5 flex flex-wrap items-center gap-x-1.5 text-[11px] text-text-muted">
                                                <span>{tile.admission_number}</span>
                                                <span aria-hidden="true">·</span>
                                                <span className="truncate font-mono">{tile.device_name}</span>
                                            </p>
                                        </div>

                                        <span
                                            className={`inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-bold tracking-wider uppercase ${look.badge}`}
                                        >
                                            <BadgeIcon className="h-2.5 w-2.5" aria-hidden="true" />
                                            {look.label}
                                        </span>
                                    </div>

                                    {/* What the learner is looking at */}
                                    <div className="mt-3.5 min-w-0 rounded-lg border border-border/70 bg-canvas/60 p-2.5 transition-colors group-hover:bg-brand-soft/20">
                                        <div className="flex min-w-0 items-center gap-1.5 text-[10px] font-medium text-text-muted">
                                            <GlobeIcon className="h-3 w-3 shrink-0 text-brand" aria-hidden="true" />
                                            <span className="truncate font-semibold text-text">
                                                {tile.domain || 'Waiting for browser activity'}
                                            </span>
                                        </div>

                                        <p className="mt-1 line-clamp-2 text-xs text-text-secondary">
                                            {tile.active_tab_title || tile.active_url || 'No page has been reported in this session.'}
                                        </p>

                                        <div className="mt-2 flex items-center justify-between gap-2 text-[10px] text-text-muted">
                                            <span className="truncate">{tile.category}</span>
                                            {tile.active_url && (
                                                <a
                                                    href={tile.active_url}
                                                    target="_blank"
                                                    rel="noreferrer noopener"
                                                    className="inline-flex shrink-0 items-center gap-1 font-medium text-brand underline-offset-2 hover:underline focus-visible:ring-2 focus-visible:ring-brand focus-visible:outline-none"
                                                >
                                                    Open
                                                    <ExternalLinkIcon className="h-3 w-3" aria-hidden="true" />
                                                    <span className="sr-only">
                                                        the page {tile.learner_name} is viewing, in a new tab
                                                    </span>
                                                </a>
                                            )}
                                        </div>
                                    </div>

                                    {/* Whether this workstation is reporting right now, said
                                        plainly. A silent one is saying nothing, which is not the
                                        same as saying everything is fine. */}
                                    {tile.isStale ? (
                                        <p className="mt-2.5 flex items-start gap-1.5 text-[11px] text-text-muted">
                                            <WifiOffIcon className="mt-px h-3 w-3 shrink-0" aria-hidden="true" />
                                            <span>
                                                Last reported {formatRelative(tile.last_activity_at)}. The page above may no
                                                longer be what is on screen.
                                            </span>
                                        </p>
                                    ) : (
                                        <p className="mt-2.5 flex items-center gap-1.5 text-[11px] text-success">
                                            <span className="relative flex h-2 w-2" aria-hidden="true">
                                                <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-success opacity-60 motion-reduce:animate-none" />
                                                <span className="relative inline-flex h-2 w-2 rounded-full bg-success" />
                                            </span>
                                            <WifiIcon className="h-3 w-3 shrink-0" aria-hidden="true" />
                                            <span className="font-medium">Reporting</span>
                                            {liveFor(tile.live_for_seconds) && (
                                                <span className="text-text-muted">· {liveFor(tile.live_for_seconds)}</span>
                                            )}
                                        </p>
                                    )}
                                </div>

                                <div className="mt-4 border-t border-border/60 pt-3">
                                    <div className="flex items-center justify-between gap-2 text-[11px]">
                                        <span className="text-text-muted">
                                            Focus score
                                            <span className="ml-1 text-text-muted/70">(last hour)</span>
                                        </span>
                                        <span className={`font-bold ${tile.isStale ? 'text-text-muted' : tone.text}`}>
                                            {score}%
                                        </span>
                                    </div>

                                    <Meter
                                        value={Math.max(8, score)}
                                        tone={tile.isStale ? 'muted' : tone.meter}
                                        label={`Focus score for ${tile.learner_name}`}
                                        className="mt-1 w-full"
                                    />

                                    <div className="mt-2 flex items-center justify-between gap-2 text-[10px] text-text-muted">
                                        <span className="truncate">{tile.laboratory_name}</span>
                                        <button
                                            type="button"
                                            onClick={() => setHistoryFor(tile)}
                                            className="shrink-0 font-medium text-brand underline-offset-2 hover:underline focus-visible:ring-2 focus-visible:ring-brand focus-visible:outline-none"
                                        >
                                            Browsing history
                                        </button>
                                    </div>
                                </div>
                            </li>
                        );
                    })}
                </ul>
            )}

            {/* Browsing history for one learner */}
            <Drawer
                open={historyFor !== null}
                onClose={() => setHistoryFor(null)}
                title={historyFor ? `${historyFor.learner_name} — browsing history` : 'Browsing history'}
                subtitle={
                    historyFor
                        ? `${historyFor.admission_number} · ${historyFor.device_name} · session started ${formatRelative(historyFor.session_started_at)}`
                        : undefined
                }
            >
                {historyFor && <BrowsingHistory learnerId={historyFor.learner_id} />}
            </Drawer>

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
