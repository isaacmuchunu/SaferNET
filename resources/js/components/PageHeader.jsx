import { Link } from 'react-router-dom';
import { ChevronRightIcon } from 'lucide-react';

export function PageHeader({ title, description, meta, actions, breadcrumbs = [] }) {
    return (
        <div className="mb-5">
            {breadcrumbs.length > 0 && (
                <nav aria-label="Breadcrumb" className="mb-2">
                    <ol className="flex flex-wrap items-center gap-1 text-[11px] text-text-secondary">
                        {breadcrumbs.map((crumb, index) => (
                            <li key={crumb.to ?? crumb.label} className="flex items-center gap-1">
                                {index > 0 && <ChevronRightIcon size={12} className="text-border" />}
                                {crumb.to ? (
                                    <Link to={crumb.to} className="font-medium hover:text-brand hover:underline">
                                        {crumb.label}
                                    </Link>
                                ) : (
                                    <span className="text-text">{crumb.label}</span>
                                )}
                            </li>
                        ))}
                    </ol>
                </nav>
            )}

            <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-start">
                <div className="min-w-0">
                    <h1 className="text-2xl font-bold tracking-[-.025em] text-text sm:text-[26px]">{title}</h1>
                    <div className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1">
                        {description && <p className="text-sm text-text-secondary">{description}</p>}
                        {meta && (
                            <>
                                <span aria-hidden className="hidden h-1 w-1 rounded-full bg-text-muted sm:block" />
                                <span className="text-xs text-text-muted">{meta}</span>
                            </>
                        )}
                    </div>
                </div>
                {actions && <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div>}
            </div>
        </div>
    );
}
