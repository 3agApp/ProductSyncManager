import { Head, Link, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    Boxes,
    CircleDollarSign,
    Package,
    PackageSearch,
    RefreshCw,
    Store,
    Users,
} from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { ProductStatusBadge } from '@/components/product-status-badge';
import { ProductStockLevel } from '@/components/product-stock-level';
import { Button } from '@/components/ui/button';
import { dashboard, home, login } from '@/routes';
import type { ProductStatus } from '@/types';

const features = [
    {
        icon: Store,
        title: 'Every shop, one workspace',
        description:
            'Run multiple storefronts side by side and switch between them in a click.',
    },
    {
        icon: Boxes,
        title: 'Catalog & inventory',
        description:
            'Create, edit and archive products with SKUs, pricing and stock levels.',
    },
    {
        icon: PackageSearch,
        title: 'Spot problems early',
        description:
            'Low-stock and out-of-stock products surface on your dashboard automatically.',
    },
    {
        icon: Users,
        title: 'Built for your team',
        description:
            'Invite staff as owners, admins or members and keep access in check.',
    },
];

const previewProducts: {
    name: string;
    sku: string;
    status: ProductStatus;
    stock: number;
    price: string;
}[] = [
    {
        name: 'Classic Cotton Tote Bag',
        sku: 'TOT-10421',
        status: 'active',
        stock: 128,
        price: '$24.00',
    },
    {
        name: 'Premium Ceramic Mug',
        sku: 'MUG-20877',
        status: 'active',
        stock: 4,
        price: '$18.50',
    },
    {
        name: 'Handmade Oak Planter',
        sku: 'PLN-33012',
        status: 'draft',
        stock: 36,
        price: '$42.00',
    },
    {
        name: 'Vintage Leather Wallet',
        sku: 'WAL-48110',
        status: 'active',
        stock: 0,
        price: '$65.00',
    },
];

const statusLabels: Record<ProductStatus, string> = {
    active: 'Active',
    draft: 'Draft',
    archived: 'Archived',
};

export default function Welcome() {
    const { auth, currentShop, name } = usePage().props;
    const dashboardUrl = currentShop ? dashboard(currentShop.slug) : '/';

    return (
        <>
            <Head title="Product management for multi-shop sellers" />
            <div className="bg-muted/20 text-foreground flex min-h-screen flex-col">
                <header className="mx-auto flex w-full max-w-6xl flex-wrap items-center justify-between gap-4 px-6 py-5">
                    <Link
                        href={home()}
                        className="flex items-center gap-2 font-semibold tracking-tight"
                    >
                        <div className="bg-primary text-primary-foreground flex size-8 items-center justify-center rounded-md">
                            <AppLogoIcon className="size-5" />
                        </div>
                        {name}
                    </Link>

                    <nav className="flex items-center gap-2">
                        {auth.user ? (
                            <Button asChild>
                                <Link href={dashboardUrl}>Dashboard</Link>
                            </Button>
                        ) : (
                            <Button asChild>
                                <Link href={login()}>Log in</Link>
                            </Button>
                        )}
                    </nav>
                </header>

                <main className="flex-1">
                    <section className="mx-auto max-w-6xl px-6 pt-12 pb-16 text-center lg:pt-20">
                        <div className="bg-muted/60 text-muted-foreground mb-6 inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-medium">
                            <RefreshCw className="size-3.5 text-emerald-600 dark:text-emerald-400" />
                            Product management for multi-shop sellers
                        </div>
                        <h1 className="mx-auto max-w-3xl text-4xl font-semibold tracking-tight text-balance sm:text-5xl lg:text-6xl">
                            Keep every product in sync, across every shop.
                        </h1>
                        <p className="text-muted-foreground mx-auto mt-6 max-w-2xl text-base text-balance sm:text-lg">
                            {name} gives your team one place to manage catalogs,
                            pricing and inventory, so your storefronts never
                            drift apart.
                        </p>
                        <div className="mt-8 flex flex-wrap items-center justify-center gap-3">
                            <Button size="lg" asChild>
                                <Link href={auth.user ? dashboardUrl : login()}>
                                    {auth.user ? 'Open dashboard' : 'Log in'}
                                    <ArrowRight />
                                </Link>
                            </Button>
                        </div>
                    </section>

                    <section className="mx-auto max-w-5xl px-6">
                        <DashboardPreview />
                    </section>

                    <section className="mx-auto grid max-w-6xl gap-4 px-6 py-20 sm:grid-cols-2 lg:grid-cols-4">
                        {features.map((feature) => (
                            <div
                                key={feature.title}
                                className="workspace-panel p-6"
                            >
                                <div className="bg-muted mb-4 flex size-10 items-center justify-center rounded-lg">
                                    <feature.icon className="size-5" />
                                </div>
                                <h2 className="font-medium">{feature.title}</h2>
                                <p className="text-muted-foreground mt-2 text-sm">
                                    {feature.description}
                                </p>
                            </div>
                        ))}
                    </section>
                </main>

                <footer className="border-t">
                    <div className="text-muted-foreground mx-auto flex max-w-6xl flex-col items-center justify-between gap-2 px-6 py-6 text-sm sm:flex-row">
                        <span className="text-foreground flex items-center gap-2 font-medium">
                            <AppLogoIcon className="size-4" />
                            {name}
                        </span>
                        <span>
                            © {new Date().getFullYear()} {name}. All rights
                            reserved.
                        </span>
                    </div>
                </footer>
            </div>
        </>
    );
}

function DashboardPreview() {
    return (
        <div
            aria-hidden="true"
            className="bg-card overflow-hidden rounded-xl border shadow-xl shadow-neutral-900/5 dark:shadow-black/40"
        >
            <div className="bg-muted/40 flex items-center gap-2 border-b px-4 py-3">
                <span className="size-2.5 rounded-full bg-red-400/80" />
                <span className="size-2.5 rounded-full bg-amber-400/80" />
                <span className="size-2.5 rounded-full bg-emerald-400/80" />
                <span className="text-muted-foreground ml-3 text-xs">
                    Acme Outlet · Products
                </span>
            </div>

            <div className="grid gap-3 border-b p-4 sm:grid-cols-3">
                {[
                    { label: 'Total products', value: '248', icon: Package },
                    {
                        label: 'Inventory value',
                        value: '$38,420.00',
                        icon: CircleDollarSign,
                    },
                    { label: 'Low stock', value: '7', icon: AlertTriangle },
                ].map((stat) => (
                    <div
                        key={stat.label}
                        className="rounded-lg border p-3 text-left"
                    >
                        <div className="text-muted-foreground flex items-center justify-between text-xs">
                            {stat.label}
                            <stat.icon className="size-3.5" />
                        </div>
                        <div className="mt-1 text-lg font-semibold tabular-nums">
                            {stat.value}
                        </div>
                    </div>
                ))}
            </div>

            <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                    <thead className="text-muted-foreground border-b">
                        <tr>
                            <th className="px-4 py-2.5 font-medium">Product</th>
                            <th className="px-4 py-2.5 font-medium">Status</th>
                            <th className="px-4 py-2.5 font-medium">
                                Inventory
                            </th>
                            <th className="px-4 py-2.5 text-right font-medium">
                                Price
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {previewProducts.map((product) => (
                            <tr
                                key={product.sku}
                                className="border-b last:border-0"
                            >
                                <td className="px-4 py-3 whitespace-nowrap">
                                    <div className="font-medium">
                                        {product.name}
                                    </div>
                                    <div className="text-muted-foreground font-mono text-xs">
                                        {product.sku}
                                    </div>
                                </td>
                                <td className="px-4 py-3">
                                    <ProductStatusBadge
                                        status={product.status}
                                        label={statusLabels[product.status]}
                                    />
                                </td>
                                <td className="px-4 py-3 whitespace-nowrap">
                                    <ProductStockLevel
                                        stock={product.stock}
                                        lowStockThreshold={5}
                                    />
                                </td>
                                <td className="px-4 py-3 text-right tabular-nums">
                                    {product.price}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
