import { Link } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';

/**
 * One step of a paginated list, as an icon button.
 *
 * The paginator hands back a null url at either end, which is the disabled
 * state: the control stays in place so the row of arrows does not reflow on
 * the first and last page.
 */
export default function PaginationArrow({
    href,
    label,
    icon: Icon,
    test,
}: {
    href: string | null;
    label: string;
    icon: LucideIcon;
    test: string;
}) {
    if (!href) {
        return (
            <Button
                variant="ghost"
                size="icon"
                className="size-8"
                data-test={test}
                disabled
            >
                <Icon />
                <span className="sr-only">{label}</span>
            </Button>
        );
    }

    return (
        <Button
            variant="ghost"
            size="icon"
            className="size-8"
            data-test={test}
            asChild
        >
            <Link href={href} preserveScroll preserveState>
                <Icon />
                <span className="sr-only">{label}</span>
            </Link>
        </Button>
    );
}
