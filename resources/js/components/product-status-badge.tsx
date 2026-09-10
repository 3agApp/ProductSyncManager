import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type { ProductStatus } from '@/types';

const badgeStyles: Record<ProductStatus, string> = {
    active: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900/60 dark:bg-emerald-950/50 dark:text-emerald-300',
    draft: 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-900/60 dark:bg-sky-950/50 dark:text-sky-300',
    archived: 'bg-muted text-muted-foreground',
};

const dotStyles: Record<ProductStatus, string> = {
    active: 'bg-emerald-500',
    draft: 'bg-sky-500',
    archived: 'bg-muted-foreground/60',
};

type Props = {
    status: ProductStatus;
    label: string;
};

export function ProductStatusBadge({ status, label }: Props) {
    return (
        <Badge variant="outline" className={cn('gap-1.5', badgeStyles[status])}>
            <span className={cn('size-1.5 rounded-full', dotStyles[status])} />
            {label}
        </Badge>
    );
}
