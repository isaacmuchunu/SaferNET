import clsx from 'clsx';
import { initials } from '../lib/format';

const SIZES = {
    sm: 'h-8 w-8 text-xs',
    md: 'h-10 w-10 text-xs',
    lg: 'h-12 w-12 text-sm',
    xl: 'h-16 w-16 text-base',
};

export function UserAvatar({ user, size = 'md', className }) {
    const classes = clsx(
        'grid shrink-0 place-items-center overflow-hidden rounded-full border border-white/70 bg-brand font-bold text-white shadow-sm',
        SIZES[size] ?? SIZES.md,
        className,
    );

    if (user?.avatar_url) {
        return <img src={user.avatar_url} alt={`${user.name ?? 'Officer'} profile`} className={clsx(classes, 'object-cover')} />;
    }

    return <span className={classes}>{initials(user?.name)}</span>;
}
