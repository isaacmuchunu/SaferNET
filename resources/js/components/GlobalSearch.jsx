import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Loader2Icon, SearchIcon } from 'lucide-react';
import { useDebouncedValue } from '../lib/hooks';
import { useDevices, useInstitutions, useLearners } from '../lib/queries';

/**
 * County-wide lookup across the three registers an officer searches by name:
 * schools, learners and devices. Each source is tenant-scoped by the API, so
 * results never reach beyond the officer's own jurisdiction.
 */
export function GlobalSearch() {
    const navigate = useNavigate();
    const [query, setQuery] = useState('');
    const [open, setOpen] = useState(false);
    const containerRef = useRef(null);
    const term = useDebouncedValue(query, 250);
    const enabled = term.trim().length > 1;

    const institutions = useInstitutions({ search: term }, { enabled });
    const learners = useLearners({ search: term }, { enabled });
    const devices = useDevices({ search: term }, { enabled });

    useEffect(() => {
        const onPointerDown = (event) => {
            if (!containerRef.current?.contains(event.target)) {
                setOpen(false);
            }
        };

        document.addEventListener('pointerdown', onPointerDown);

        return () => document.removeEventListener('pointerdown', onPointerDown);
    }, []);

    const results = enabled
        ? [
              ...(institutions.data?.data ?? []).slice(0, 3).map((item) => ({
                  group: 'School',
                  title: item.name,
                  meta: `${item.subcounty?.name ?? 'Kiambu'} · NEMIS ${item.nemis_code}`,
                  path: `/schools/${item.id}`,
              })),
              ...(learners.data?.data ?? []).slice(0, 3).map((item) => ({
                  group: 'Learner',
                  title: `${item.first_name} ${item.last_name}`,
                  meta: `${item.learner_number}${item.learner_group ? ` · ${item.learner_group.name}` : ''}`,
                  path: `/learners?search=${encodeURIComponent(item.learner_number)}`,
              })),
              ...(devices.data?.data ?? []).slice(0, 3).map((item) => ({
                  group: 'Device',
                  title: item.asset_tag,
                  meta: item.hostname ?? 'Managed device',
                  path: `/devices?search=${encodeURIComponent(item.asset_tag)}`,
              })),
          ]
        : [];

    const loading = enabled && (institutions.isFetching || learners.isFetching || devices.isFetching);

    return (
        <div ref={containerRef} className="relative mx-auto w-full max-w-[510px]">
            <SearchIcon size={17} className="absolute top-1/2 left-3 -translate-y-1/2 text-text-muted" />
            <input
                value={query}
                onFocus={() => setOpen(true)}
                onChange={(event) => {
                    setQuery(event.target.value);
                    setOpen(true);
                }}
                onKeyDown={(event) => {
                    if (event.key === 'Escape') {
                        setQuery('');
                        setOpen(false);
                    }

                    if (event.key === 'Enter' && results[0]) {
                        navigate(results[0].path);
                        setQuery('');
                        setOpen(false);
                    }
                }}
                aria-label="Search schools, learners and devices"
                aria-expanded={open && enabled}
                placeholder="Search schools, learners or devices..."
                className="h-10 w-full rounded-xl border border-border bg-canvas/70 pr-4 pl-9 text-sm placeholder:text-text-muted focus:border-brand focus:bg-white focus:outline-none"
            />
            {loading && <Loader2Icon size={15} className="absolute top-1/2 right-3 -translate-y-1/2 animate-spin text-text-muted" />}

            {open && enabled && (
                <div className="absolute top-12 right-0 left-0 z-50 overflow-hidden rounded-2xl border border-brand/15 bg-white/98 shadow-[0_22px_55px_rgba(12,65,72,.18)] backdrop-blur-xl">
                    <p className="border-b border-border bg-gradient-to-r from-brand-soft/75 to-cyan-50/50 px-4 py-2.5 text-[10px] font-semibold tracking-wider text-brand uppercase">
                        Instant results · Enter opens the first match
                    </p>
                    {results.length > 0 ? (
                        results.map((item) => (
                            <Link
                                key={`${item.group}-${item.title}`}
                                to={item.path}
                                onClick={() => {
                                    setQuery('');
                                    setOpen(false);
                                }}
                                className="flex items-center justify-between gap-4 border-b border-border px-4 py-3 last:border-0 hover:bg-brand-soft"
                            >
                                <span className="min-w-0">
                                    <span className="block truncate text-sm font-semibold">{item.title}</span>
                                    <span className="block truncate text-xs text-text-secondary">{item.meta}</span>
                                </span>
                                <span className="shrink-0 text-[10px] font-semibold text-brand uppercase">{item.group}</span>
                            </Link>
                        ))
                    ) : (
                        <p className="px-4 py-6 text-center text-sm text-text-secondary">
                            {loading ? 'Searching the county register…' : 'No matching records'}
                        </p>
                    )}
                </div>
            )}
        </div>
    );
}
