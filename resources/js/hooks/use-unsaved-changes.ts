import { router } from '@inertiajs/react';
import { useEffect } from 'react';

/**
 * Warn before leaving a form with edits that have not been saved.
 *
 * Covers both ways out: closing or reloading the tab, which the browser
 * handles, and following a link inside the app, which Inertia does.
 */
export function useUnsavedChanges(enabled: boolean, message: string) {
    useEffect(() => {
        if (!enabled) {
            return;
        }

        const warnOnUnload = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            // Browsers show their own wording; a value is still required.
            event.returnValue = '';
        };

        window.addEventListener('beforeunload', warnOnUnload);

        const stopWatchingVisits = router.on('before', (event) => {
            const visit = event.detail.visit;

            // Only navigation away is worth guarding. Submitting the form is
            // the point of it, and so is the redirect that follows.
            if (visit.method !== 'get') {
                return;
            }

            // Links in the shell prefetch on hover. That is not the user
            // going anywhere, and asking them about it would put the
            // question twice for a single click.
            if (visit.prefetch) {
                return;
            }

            if (!window.confirm(message)) {
                event.preventDefault();
            }
        });

        return () => {
            window.removeEventListener('beforeunload', warnOnUnload);
            stopWatchingVisits();
        };
    }, [enabled, message]);
}
