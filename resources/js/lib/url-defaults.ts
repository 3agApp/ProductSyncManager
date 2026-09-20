import { router } from '@inertiajs/react';
import { setUrlDefaults } from '@/wayfinder';

/**
 * The shared props this reads. Only the current shop matters here,
 * and it is null for anyone who has not joined one yet.
 */
type TenantPageProps = {
    currentShop?: { slug?: string | null } | null;
};

/**
 * Build the URL defaults for a page.
 *
 * SetShopUrlDefaults does exactly this on the server, under both
 * names, so a route generated either side comes out the same.
 */
export function urlDefaultsFor(props: TenantPageProps | null | undefined) {
    const slug = props?.currentShop?.slug;

    if (!slug) {
        return {};
    }

    return { current_shop: slug, shop: slug };
}

/**
 * Read the page Inertia boots from, before it has booted.
 *
 * Wayfinder marks a route's shop segment optional, because on the
 * server URL::defaults() fills it in. That makes a call without one compile
 * cleanly and then throw at runtime, so the client needs the same defaults —
 * including on the very first render, which is before any navigate event.
 * Inertia puts that first page in the DOM and reads it the same way.
 */
function initialPageProps(id: string): TenantPageProps | null {
    if (typeof document === 'undefined') {
        return null;
    }

    const script = document.querySelector(
        `script[data-page="${id}"][type="application/json"]`,
    );

    if (!script?.textContent) {
        return null;
    }

    try {
        return JSON.parse(script.textContent).props ?? null;
    } catch {
        // A shape we do not recognise leaves the defaults empty, which is
        // where they were before this existed. Nothing regresses.
        return null;
    }
}

/**
 * Keep Wayfinder's URL defaults pointed at the shop being viewed.
 *
 * Call before createInertiaApp, so the first render already has them.
 */
export function installUrlDefaults(id = 'app'): void {
    let props = initialPageProps(id);

    router.on('navigate', (event) => {
        props = event.detail.page.props as TenantPageProps;
    });

    // Resolved per URL rather than once, so switching shop is picked
    // up without re-registering anything.
    setUrlDefaults(() => urlDefaultsFor(props));
}
