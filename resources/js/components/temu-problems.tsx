import { Link } from '@inertiajs/react';
import { AlertTriangle, PencilLine, RotateCcw } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { edit as editProduct } from '@/routes/products';
import type { TemuProblem, TemuProblemReason } from '@/types';

type Props = {
    shopSlug: string;
    problems: TemuProblem[];
    /** Every product needing attention, of which `problems` is the newest few. */
    total: number;
    canRetry: boolean;
    onRetry: (records?: number[]) => void;
};

type Group = {
    reason: TemuProblemReason;
    items: TemuProblem[];
};

/** A group this long shows its first few products until opened. */
const COLLAPSED_ROWS = 5;

/**
 * The products Temu did not accept, grouped by why.
 *
 * Temu tends to refuse many products for the same reason in one sync, and
 * the fix is the same for all of them, so each reason is one card that says
 * what to do once, with the products underneath.
 */
export default function TemuProblems({
    shopSlug,
    problems,
    total,
    canRetry,
    onRetry,
}: Props) {
    const groups = useMemo(() => {
        const byKey = new Map<string, Group>();

        for (const item of problems) {
            const group = byKey.get(item.reason.key) ?? {
                reason: item.reason,
                items: [],
            };

            group.items.push(item);
            byKey.set(item.reason.key, group);
        }

        return [...byKey.values()].sort(
            (a, b) => b.items.length - a.items.length,
        );
    }, [problems]);

    const retryable = problems.filter((item) => item.canRetry).length;

    if (problems.length === 0) {
        return null;
    }

    return (
        <section
            className="space-y-3"
            aria-labelledby="temu-needs-attention"
            data-test="temu-problems"
        >
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-2">
                    <h3
                        id="temu-needs-attention"
                        className="text-sm font-medium"
                    >
                        Needs attention
                    </h3>
                    <Badge
                        variant="outline"
                        className="border-amber-600/30 text-amber-700 tabular-nums dark:border-amber-400/30 dark:text-amber-400"
                    >
                        {total.toLocaleString()}
                    </Badge>
                </div>
                {canRetry && retryable > 1 ? (
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => onRetry()}
                        data-test="temu-retry-all"
                    >
                        <RotateCcw className="size-4" />
                        Retry all failed ({retryable})
                    </Button>
                ) : null}
            </div>

            {total > problems.length ? (
                <p className="text-muted-foreground text-sm">
                    Showing the {problems.length} most recently checked of{' '}
                    {total.toLocaleString()}. Fixed products drop off after the
                    next sync.
                </p>
            ) : null}

            <div className="space-y-3">
                {groups.map((group) => (
                    <ProblemGroup
                        key={group.reason.key}
                        group={group}
                        shopSlug={shopSlug}
                        canRetry={canRetry}
                        onRetry={onRetry}
                    />
                ))}
            </div>
        </section>
    );
}

function ProblemGroup({
    group,
    shopSlug,
    canRetry,
    onRetry,
}: {
    group: Group;
    shopSlug: string;
    canRetry: boolean;
    onRetry: (records?: number[]) => void;
}) {
    const [expanded, setExpanded] = useState(false);
    const { reason, items } = group;

    const retryIds = items
        .filter((item) => item.canRetry)
        .map((item) => item.id);
    const visible = expanded ? items : items.slice(0, COLLAPSED_ROWS);
    const hidden = items.length - visible.length;

    return (
        <div
            className="overflow-hidden rounded-lg border"
            data-test="temu-problem-group"
        >
            <div className="bg-muted/30 flex flex-wrap items-start justify-between gap-3 border-b p-4">
                <div className="flex min-w-0 flex-1 gap-3">
                    <AlertTriangle
                        className={cn(
                            'mt-0.5 size-4 shrink-0',
                            reason.fix === 'retry'
                                ? 'text-muted-foreground'
                                : 'text-amber-600 dark:text-amber-400',
                        )}
                    />
                    <div className="min-w-0 space-y-1">
                        <p className="text-sm font-medium break-words">
                            {reason.title}{' '}
                            <span className="text-muted-foreground font-normal">
                                · {items.length}{' '}
                                {items.length === 1 ? 'product' : 'products'}
                            </span>
                        </p>
                        {reason.hint ? (
                            <p className="text-muted-foreground text-sm">
                                {reason.hint}
                            </p>
                        ) : null}
                    </div>
                </div>

                {canRetry && retryIds.length > 0 ? (
                    <Button
                        variant={reason.fix === 'retry' ? 'default' : 'outline'}
                        size="sm"
                        onClick={() => onRetry(retryIds)}
                        data-test="temu-retry-group"
                    >
                        <RotateCcw className="size-4" />
                        {retryIds.length === 1
                            ? 'Retry'
                            : `Retry ${retryIds.length}`}
                    </Button>
                ) : null}
            </div>

            <ul className="divide-y">
                {visible.map((item) => (
                    <li
                        key={item.id}
                        data-test="temu-problem-row"
                        className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-4 py-3"
                    >
                        <div className="min-w-0 flex-1">
                            {item.productId ? (
                                <Link
                                    href={
                                        editProduct([shopSlug, item.productId])
                                            .url
                                    }
                                    className="text-sm font-medium break-words hover:underline"
                                >
                                    {item.productName}
                                </Link>
                            ) : (
                                <span className="text-sm font-medium break-words">
                                    {item.productName}
                                </span>
                            )}
                            <div className="text-muted-foreground flex flex-wrap gap-x-2 text-xs">
                                <span className="font-mono">{item.sku}</span>
                                {item.checkedAtDiff ? (
                                    <span>Checked {item.checkedAtDiff}</span>
                                ) : null}
                            </div>
                        </div>

                        <div className="flex items-center gap-1">
                            {reason.fix === 'title' && item.productId ? (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    asChild
                                    data-test="temu-edit-title"
                                >
                                    <Link
                                        href={`${editProduct([shopSlug, item.productId]).url}#product-temu-name`}
                                    >
                                        <PencilLine className="size-4" />
                                        Set Temu title
                                    </Link>
                                </Button>
                            ) : null}
                            {reason.fix === 'seller_center' && item.goodsId ? (
                                <span className="text-muted-foreground text-xs">
                                    Temu ID{' '}
                                    <span className="font-mono">
                                        {item.goodsId}
                                    </span>
                                </span>
                            ) : null}
                            {canRetry && item.canRetry && items.length > 1 ? (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => onRetry([item.id])}
                                    aria-label={`Retry ${item.productName}`}
                                    data-test="temu-retry"
                                >
                                    <RotateCcw className="size-4" />
                                </Button>
                            ) : null}
                        </div>
                    </li>
                ))}
            </ul>

            {hidden > 0 || expanded ? (
                <button
                    type="button"
                    onClick={() => setExpanded((open) => !open)}
                    className="text-muted-foreground hover:text-foreground hover:bg-muted/50 w-full border-t px-4 py-2 text-left text-sm transition-colors"
                    data-test="temu-problem-group-toggle"
                >
                    {expanded ? 'Show fewer' : `Show ${hidden} more`}
                </button>
            ) : null}
        </div>
    );
}
