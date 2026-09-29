import { ShieldCheck, Store, UserRound } from 'lucide-react';
import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn, toUrl } from '@/lib/utils';
import { edit } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import { index as shops } from '@/routes/shops';
import type { NavItem } from '@/types';

type NavGroup = {
    title: string;
    /** Shown under the page title while one of the group's pages is open. */
    description: string;
    items: NavItem[];
};

/**
 * Personal settings first, then the ones shared with everyone in the shop,
 * so it is clear which changes affect only you.
 */
const navGroups: NavGroup[] = [
    {
        title: 'Account',
        description: 'Manage your profile, sign-in and appearance',
        items: [
            {
                title: 'Profile',
                href: edit(),
                icon: UserRound,
            },
            {
                title: 'Security',
                href: editSecurity(),
                icon: ShieldCheck,
            },
        ],
    },
    {
        title: 'Workspace',
        description: 'Manage your shops, their members and integrations',
        items: [
            {
                title: 'Shops',
                href: shops(),
                icon: Store,
            },
        ],
    },
];

export default function SettingsLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl } = useCurrentUrl();

    const activeGroup =
        navGroups.find((group) =>
            group.items.some((item) => isCurrentOrParentUrl(item.href)),
        ) ?? navGroups[0];

    return (
        <div className="workspace-page [&>header]:mb-0">
            <Heading title="Settings" description={activeGroup.description} />

            <div className="grid min-w-0 gap-6 lg:grid-cols-[200px_minmax(0,1fr)] lg:gap-8">
                <aside className="min-w-0">
                    <nav
                        className="workspace-panel flex flex-col gap-3 p-2 lg:sticky lg:top-6"
                        aria-label="Settings"
                    >
                        {navGroups.map((group) => (
                            <div key={group.title} className="grid gap-1">
                                <p className="text-muted-foreground px-3 pt-1 text-xs font-medium tracking-[0.16em] uppercase">
                                    {group.title}
                                </p>
                                <div className="grid grid-cols-2 gap-1 lg:grid-cols-1">
                                    {group.items.map((item, index) => (
                                        <Button
                                            key={`${toUrl(item.href)}-${index}`}
                                            size="sm"
                                            variant="ghost"
                                            asChild
                                            className={cn(
                                                'h-11 w-full justify-start gap-3 px-3',
                                                {
                                                    'bg-muted font-semibold':
                                                        isCurrentOrParentUrl(
                                                            item.href,
                                                        ),
                                                },
                                            )}
                                        >
                                            <Link
                                                href={item.href}
                                                aria-current={
                                                    isCurrentOrParentUrl(
                                                        item.href,
                                                    )
                                                        ? 'page'
                                                        : undefined
                                                }
                                            >
                                                {item.icon && (
                                                    <item.icon className="h-4 w-4" />
                                                )}
                                                {item.title}
                                            </Link>
                                        </Button>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </nav>
                </aside>

                <div className="workspace-panel w-full max-w-4xl p-5 sm:p-8">
                    <section className="min-w-0 space-y-10">{children}</section>
                </div>
            </div>
        </div>
    );
}
