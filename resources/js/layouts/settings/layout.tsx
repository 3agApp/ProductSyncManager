import { UserRound, Store, Palette } from 'lucide-react';
import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn, toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit } from '@/routes/profile';
import { index as shops } from '@/routes/shops';
import type { NavItem } from '@/types';

const sidebarNavItems: NavItem[] = [
    {
        title: 'Profile',
        href: edit(),
        icon: UserRound,
    },
    {
        title: 'Shops',
        href: shops(),
        icon: Store,
    },
    {
        title: 'Appearance',
        href: editAppearance(),
        icon: Palette,
    },
];

export default function SettingsLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl } = useCurrentUrl();

    return (
        <div className="workspace-page [&>header]:mb-0">
            <Heading
                title="Settings"
                description="Manage your profile and account settings"
            />

            <div className="grid min-w-0 gap-6 lg:grid-cols-[200px_minmax(0,1fr)] lg:gap-8">
                <aside className="min-w-0">
                    <nav
                        className="workspace-panel grid grid-cols-2 gap-1 p-2 lg:sticky lg:top-6 lg:grid-cols-1"
                        aria-label="Settings"
                    >
                        {sidebarNavItems.map((item, index) => (
                            <Button
                                key={`${toUrl(item.href)}-${index}`}
                                size="sm"
                                variant="ghost"
                                asChild
                                className={cn(
                                    'h-11 w-full justify-start gap-3 px-3',
                                    {
                                        'bg-muted font-semibold':
                                            isCurrentOrParentUrl(item.href),
                                    },
                                )}
                            >
                                <Link
                                    href={item.href}
                                    aria-current={
                                        isCurrentOrParentUrl(item.href)
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
                    </nav>
                </aside>

                <div className="workspace-panel w-full max-w-4xl p-5 sm:p-8">
                    <section className="min-w-0 space-y-10">{children}</section>
                </div>
            </div>
        </div>
    );
}
