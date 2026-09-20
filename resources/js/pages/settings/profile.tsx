import { Head, usePage } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import DeleteUser from '@/components/delete-user';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/profile';
import type { Auth } from '@/types';

type PageProps = {
    auth: Auth;
    accountsUrl: string;
};

/**
 * A read-only view of the identity 3AG Accounts holds.
 *
 * Nothing here is editable because nothing here was ever the local app's to
 * change: single sign-on rewrites the name and email address from the
 * Accounts claims on every login, so a local edit only ever lasted until the
 * user signed in again.
 */
export default function Profile() {
    const { auth, accountsUrl } = usePage<PageProps>().props;

    return (
        <>
            <Head title="Profile settings" />

            <h1 className="sr-only">Profile settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Profile"
                    description="Your name and email address come from 3AG Accounts"
                />

                <dl className="space-y-6">
                    <div className="grid gap-2">
                        <Label asChild>
                            <dt>Name</dt>
                        </Label>
                        <dd
                            className="text-muted-foreground text-sm"
                            data-test="profile-name"
                        >
                            {auth.user.name}
                        </dd>
                    </div>

                    <div className="grid gap-2">
                        <Label asChild>
                            <dt>Email address</dt>
                        </Label>
                        <dd
                            className="text-muted-foreground text-sm"
                            data-test="profile-email"
                        >
                            {auth.user.email}
                        </dd>
                    </div>
                </dl>

                <p className="text-muted-foreground text-sm">
                    Your name, email address, password, two-factor
                    authentication, and passkeys are all managed in 3AG
                    Accounts. Changes there apply to every 3AG app the next time
                    you sign in.
                </p>

                <Button variant="secondary" asChild>
                    <a
                        href={accountsUrl}
                        target="_blank"
                        rel="noreferrer"
                        data-test="accounts-link"
                    >
                        Manage in 3AG Accounts
                        <ExternalLink className="size-4" />
                    </a>
                </Button>
            </div>

            <DeleteUser />
        </>
    );
}

Profile.layout = {
    breadcrumbs: [
        {
            title: 'Profile settings',
            href: edit(),
        },
    ],
};
