import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type Props = {
    icon: LucideIcon;
    title: string;
    description?: string;
    /** A button or link that gets the person out of the empty state. */
    action?: ReactNode;
    className?: string;
};

export default function EmptyState({
    icon: Icon,
    title,
    description,
    action,
    className,
}: Props) {
    return (
        <div className={cn('p-8 text-center', className)}>
            <div className="bg-muted mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl">
                <Icon className="text-muted-foreground h-7 w-7" />
            </div>
            <p className="font-medium">{title}</p>
            {description ? (
                <p className="text-muted-foreground mt-1 text-sm">
                    {description}
                </p>
            ) : null}
            {action ? <div className="mt-4">{action}</div> : null}
        </div>
    );
}
