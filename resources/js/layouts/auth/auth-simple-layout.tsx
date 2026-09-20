import { Link, usePage } from '@inertiajs/react';
import { ArrowLeft, Boxes, RefreshCw, Users } from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

const benefits = [
    {
        icon: Boxes,
        title: 'A home for every product',
        description: 'Keep your catalog, pricing and inventory together.',
    },
    {
        icon: RefreshCw,
        title: 'Stay in sync',
        description: 'Bring your WooCommerce products into one workspace.',
    },
    {
        icon: Users,
        title: 'Built for your team',
        description: 'Manage your shops with the right people and permissions.',
    },
];

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    const { name } = usePage().props;

    return (
        <div className="bg-muted/30 grid min-h-svh lg:grid-cols-2">
            <aside className="relative hidden flex-col justify-between overflow-hidden bg-neutral-950 p-12 text-white lg:flex xl:p-16">
                <div
                    className="pointer-events-none absolute -top-32 -right-32 size-96 rounded-full border border-white/10"
                    aria-hidden="true"
                />
                <div
                    className="pointer-events-none absolute -top-16 -right-16 size-64 rounded-full border border-white/10"
                    aria-hidden="true"
                />
                <Link
                    href={home()}
                    className="relative flex w-fit items-center gap-3 rounded-lg font-semibold focus-visible:outline-2 focus-visible:outline-offset-4"
                >
                    <span className="flex size-10 items-center justify-center rounded-xl border border-white/20">
                        <AppLogoIcon className="size-6" />
                    </span>
                    {name}
                </Link>
                <div className="relative my-16 max-w-md">
                    <p className="mb-5 text-xs font-medium tracking-[0.18em] text-emerald-400 uppercase">
                        Your catalog. Connected.
                    </p>
                    <h2 className="text-4xl leading-tight font-semibold tracking-tight xl:text-5xl">
                        More clarity.
                        <br />
                        Less busywork.
                    </h2>
                    <p className="mt-6 text-base leading-relaxed text-neutral-400">
                        One place to look after your products, your shops and
                        the people behind them.
                    </p>
                    <div className="mt-12 space-y-7">
                        {benefits.map(
                            ({
                                icon: Icon,
                                title: benefitTitle,
                                description: detail,
                            }) => (
                                <div
                                    key={benefitTitle}
                                    className="flex items-start gap-4"
                                >
                                    <span className="flex size-10 shrink-0 items-center justify-center rounded-xl border border-white/10 bg-white/5 text-emerald-400">
                                        <Icon className="size-5" />
                                    </span>
                                    <div>
                                        <h3 className="text-sm font-medium">
                                            {benefitTitle}
                                        </h3>
                                        <p className="mt-1 text-sm leading-relaxed text-neutral-400">
                                            {detail}
                                        </p>
                                    </div>
                                </div>
                            ),
                        )}
                    </div>
                </div>
                <p className="text-xs text-neutral-500">
                    Product and inventory management, all together.
                </p>
            </aside>
            <main className="flex min-w-0 flex-col justify-center p-5 sm:p-10">
                <div className="mx-auto w-full max-w-md">
                    <Link
                        href={home()}
                        className="text-muted-foreground hover:text-foreground focus-visible:ring-ring mb-8 inline-flex items-center gap-2 rounded text-sm focus-visible:ring-2 focus-visible:outline-none"
                    >
                        <ArrowLeft className="size-4" /> Back to {name}
                    </Link>
                    <div className="workspace-panel p-6 sm:p-8">
                        <div className="mb-8 space-y-3">
                            <div className="bg-primary text-primary-foreground mb-6 flex size-10 items-center justify-center rounded-xl lg:hidden">
                                <AppLogoIcon className="size-6" />
                            </div>
                            <h1 className="text-2xl font-semibold tracking-tight">
                                {title}
                            </h1>
                            <p className="text-muted-foreground text-sm leading-relaxed">
                                {description}
                            </p>
                        </div>
                        {children}
                    </div>
                </div>
            </main>
        </div>
    );
}
