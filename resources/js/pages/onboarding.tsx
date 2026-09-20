import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Mail } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { onboarding } from '@/routes';
import { index as invitationsIndex } from '@/routes/invitations';
import { store } from '@/routes/shops';

export default function Onboarding() {
    const { shopTypes, pendingInvitationsCount } = usePage().props;

    return (
        <>
            <Head title="Create your first shop" />

            <div className="workspace-page">
                <div className="mx-auto w-full max-w-xl space-y-6">
                    <div className="page-heading">
                        <h1 className="page-title">Create your first shop</h1>
                        <p className="text-muted-foreground text-sm">
                            A shop holds your products, categories and brands,
                            and connects to your storefront. You can add more
                            shops and invite your team later.
                        </p>
                    </div>

                    {pendingInvitationsCount > 0 ? (
                        <div
                            data-test="onboarding-invitations"
                            className="bg-card flex flex-wrap items-center justify-between gap-4 rounded-2xl border p-5 shadow-xs"
                        >
                            <div className="flex min-w-0 items-center gap-3">
                                <div className="bg-muted flex size-10 shrink-0 items-center justify-center rounded-full">
                                    <Mail className="text-muted-foreground size-5" />
                                </div>
                                <p className="text-sm">
                                    {pendingInvitationsCount === 1
                                        ? 'You have been invited to a shop.'
                                        : `You have been invited to ${pendingInvitationsCount} shops.`}
                                </p>
                            </div>
                            <Button variant="secondary" asChild>
                                <Link href={invitationsIndex()}>
                                    View invitations
                                </Link>
                            </Button>
                        </div>
                    ) : null}

                    <Form
                        {...store.form()}
                        className="bg-card space-y-6 rounded-2xl border p-6 shadow-xs"
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="name">Shop name</Label>
                                    <Input
                                        id="name"
                                        name="name"
                                        data-test="onboarding-shop-name"
                                        placeholder="My shop"
                                        autoFocus
                                        required
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="type">Shop type</Label>
                                    <Select
                                        name="type"
                                        defaultValue={
                                            shopTypes[0]?.value ?? 'wordpress'
                                        }
                                    >
                                        <SelectTrigger
                                            id="type"
                                            data-test="onboarding-shop-type"
                                            className="w-full"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {shopTypes.map((option) => (
                                                <SelectItem
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.type} />
                                </div>

                                <Button
                                    type="submit"
                                    className="w-full"
                                    data-test="onboarding-shop-submit"
                                    disabled={processing}
                                >
                                    Create shop
                                </Button>
                            </>
                        )}
                    </Form>
                </div>
            </div>
        </>
    );
}

Onboarding.layout = {
    breadcrumbs: [
        {
            title: 'Get started',
            href: onboarding(),
        },
    ],
};
