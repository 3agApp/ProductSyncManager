import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import type { SyncRun } from '@/types';

type CounterProps = {
    label: string;
    value: number;
};

function Counter({ label, value }: CounterProps) {
    return (
        <div
            data-test="sync-counter"
            className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4"
        >
            <p className="text-muted-foreground text-xs">{label}</p>
            <p className="mt-1 text-xl font-semibold tabular-nums">
                {value.toLocaleString()}
            </p>
        </div>
    );
}

type BarProps = {
    percent: number | null;
};

/**
 * A progress bar. Hand rolled because the project does not ship the shadcn
 * progress component, and an unknown total renders as an indeterminate pulse
 * rather than a misleading number.
 */
export function SyncProgressBar({ percent }: BarProps) {
    return (
        <div
            role="progressbar"
            aria-valuemin={0}
            aria-valuemax={100}
            aria-valuenow={percent ?? undefined}
            data-test="sync-progress-bar"
            className="bg-muted h-2 w-full overflow-hidden rounded-full"
        >
            <div
                className={cn(
                    'bg-primary h-full rounded-full transition-all duration-500',
                    percent === null && 'w-full animate-pulse',
                )}
                style={percent === null ? undefined : { width: `${percent}%` }}
            />
        </div>
    );
}

type Props = {
    run: SyncRun;
};

export default function SyncProgress({ run }: Props) {
    const summary =
        run.totalProducts === null
            ? `Syncing ${run.processedProducts.toLocaleString()} products`
            : `Syncing ${run.processedProducts.toLocaleString()} of ${run.totalProducts.toLocaleString()} products`;

    return (
        <div className="space-y-4" data-test="sync-progress">
            <SyncProgressBar percent={run.progress} />

            <div className="text-muted-foreground flex items-center gap-2 text-sm">
                <Spinner className="size-4" />
                <span>
                    {run.status === 'queued'
                        ? 'Waiting for a worker…'
                        : summary}
                </span>
            </div>

            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <Counter label="Created" value={run.createdProducts} />
                <Counter label="Updated" value={run.updatedProducts} />
                <Counter label="Archived" value={run.archivedProducts} />
                <Counter label="Skipped" value={run.skippedProducts} />
            </div>
        </div>
    );
}
