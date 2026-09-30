import { Link } from '@inertiajs/react';
import { AlertTriangle, ChevronRight, CircleAlert } from 'lucide-react';
import { cn } from '@/lib/utils';

export type AttentionItem = {
    key: string;
    tone: 'warning' | 'danger';
    title: string;
    description: string;
    /** Where the item is fixed: a page URL, or `#id` for a section on this page. */
    href: string;
};

/**
 * The short list of things on a settings page that someone has to act on.
 *
 * It renders nothing when there is nothing to do, so a healthy page shows
 * no empty "all good" box. Every item is a link to where it is fixed,
 * because a problem you cannot click through to is a dead end.
 */
export default function AttentionList({ items }: { items: AttentionItem[] }) {
    if (items.length === 0) {
        return null;
    }

    return (
        <section
            aria-labelledby="needs-attention-heading"
            data-test="needs-attention"
            className="space-y-3"
        >
            <h2
                id="needs-attention-heading"
                className="text-base font-semibold"
            >
                Needs attention
            </h2>
            <ul className="divide-y overflow-hidden rounded-lg border border-amber-500/30">
                {items.map((item) => {
                    const Icon =
                        item.tone === 'danger' ? CircleAlert : AlertTriangle;
                    const content = (
                        <>
                            <Icon
                                className={cn(
                                    'mt-0.5 size-4 shrink-0',
                                    item.tone === 'danger'
                                        ? 'text-red-600 dark:text-red-400'
                                        : 'text-amber-600 dark:text-amber-400',
                                )}
                            />
                            <span className="min-w-0 flex-1">
                                <span className="block text-sm font-medium">
                                    {item.title}
                                </span>
                                <span className="text-muted-foreground block text-sm break-words">
                                    {item.description}
                                </span>
                            </span>
                            <ChevronRight className="text-muted-foreground mt-0.5 size-4 shrink-0" />
                        </>
                    );
                    const className =
                        'hover:bg-muted/50 flex items-start gap-3 px-4 py-3 transition-colors';

                    return (
                        <li key={item.key} data-test="attention-item">
                            {item.href.startsWith('#') ? (
                                <a href={item.href} className={className}>
                                    {content}
                                </a>
                            ) : (
                                <Link href={item.href} className={className}>
                                    {content}
                                </Link>
                            )}
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}
