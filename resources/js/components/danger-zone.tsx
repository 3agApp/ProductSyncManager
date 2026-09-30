import type { ReactNode } from 'react';
import Heading from '@/components/heading';

type Props = {
    title: string;
    description: string;
    /** What happens, in one sentence, shown above the button. */
    warning?: string;
    children: ReactNode;
};

/**
 * The red box at the bottom of a settings page for the one thing that
 * cannot be undone. Kept as one component so the account, shop and
 * organization pages all warn the same way.
 */
export default function DangerZone({
    title,
    description,
    warning = 'Please proceed with caution, this cannot be undone.',
    children,
}: Props) {
    return (
        <div className="space-y-6">
            <Heading variant="small" title={title} description={description} />
            <div className="flex flex-wrap items-center justify-between gap-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10">
                <div className="min-w-0 space-y-0.5 text-red-600 dark:text-red-100">
                    <p className="font-medium">Warning</p>
                    <p className="text-sm">{warning}</p>
                </div>
                {children}
            </div>
        </div>
    );
}
