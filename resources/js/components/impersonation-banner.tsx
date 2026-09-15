import { router, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { destroy as stopImpersonating } from '@/routes/impersonation';

export function ImpersonationBanner() {
    const { auth, impersonating } = usePage().props;

    if (!impersonating) {
        return null;
    }

    return (
        <div
            data-test="impersonation-banner"
            className="flex flex-wrap items-center justify-center gap-x-3 gap-y-1 bg-amber-100 px-4 py-2 text-sm text-amber-900 dark:bg-amber-500/15 dark:text-amber-200"
        >
            <span>
                You are signed in as{' '}
                <span className="font-medium">{auth.user.name}</span> (
                {auth.user.email}).
            </span>
            <Button
                size="sm"
                variant="outline"
                className="h-7"
                data-test="impersonation-stop"
                onClick={() => router.visit(stopImpersonating())}
            >
                Return to admin
            </Button>
        </div>
    );
}
