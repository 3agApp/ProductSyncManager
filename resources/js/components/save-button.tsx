import { Check } from 'lucide-react';
import { Button } from '@/components/ui/button';

type Props = {
    processing: boolean;
    /** Leave undefined for a form whose changes cannot be tracked. */
    isDirty?: boolean;
    recentlySuccessful: boolean;
    label?: string;
    'data-test'?: string;
};

/**
 * A form's submit button, which stays disabled until something has changed
 * and says "Saved" beside itself for a moment afterwards, so a save that
 * worked is visible where the person is looking rather than only in a toast.
 */
export default function SaveButton({
    processing,
    isDirty,
    recentlySuccessful,
    label = 'Save',
    ...props
}: Props) {
    return (
        <div className="flex items-center gap-4">
            <Button
                type="submit"
                disabled={processing || isDirty === false}
                data-test={props['data-test']}
            >
                {label}
            </Button>

            {recentlySuccessful ? (
                <p
                    role="status"
                    className="text-muted-foreground animate-in fade-in flex items-center gap-1.5 text-sm"
                >
                    <Check className="size-4" /> Saved
                </p>
            ) : null}
        </div>
    );
}
