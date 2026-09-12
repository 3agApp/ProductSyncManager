import { Plus, X } from 'lucide-react';
import { useRef, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Props = {
    name: string;
    options: string[];
    defaultValue: string[];
    onChange?: () => void;
};

/**
 * Pick the categories a product is filed under.
 *
 * Each choice is submitted as its own value rather than as one separated
 * string, so a category whose name contains a comma survives the trip.
 * Typing a path that is new to the shop is allowed -- that is how a
 * category gets created without leaving the form.
 */
export default function CategoryPicker({
    name,
    options,
    defaultValue,
    onChange,
}: Props) {
    const [selected, setSelected] = useState<string[]>(defaultValue);
    const [draft, setDraft] = useState('');
    const inputRef = useRef<HTMLInputElement>(null);

    const add = (value: string) => {
        const path = value.trim();

        if (path === '' || selected.includes(path)) {
            setDraft('');

            return;
        }

        setSelected((current) => [...current, path]);
        setDraft('');
        onChange?.();
        inputRef.current?.focus();
    };

    const remove = (path: string) => {
        setSelected((current) => current.filter((item) => item !== path));
        onChange?.();
    };

    const unused = options.filter((option) => !selected.includes(option));

    return (
        <div className="grid gap-2">
            {/* What actually gets submitted. */}
            {selected.map((path) => (
                <input
                    key={path}
                    type="hidden"
                    name={`${name}[]`}
                    value={path}
                />
            ))}

            {selected.length > 0 ? (
                <div
                    className="flex flex-wrap gap-1.5"
                    data-test="category-chips"
                >
                    {selected.map((path) => (
                        <Badge
                            key={path}
                            variant="secondary"
                            className="gap-1 py-1 pr-1 pl-2 font-normal"
                        >
                            {path}
                            <button
                                type="button"
                                onClick={() => remove(path)}
                                aria-label={`Remove ${path}`}
                                data-test="category-chip-remove"
                                className="hover:bg-background/60 rounded p-0.5"
                            >
                                <X className="size-3" />
                            </button>
                        </Badge>
                    ))}
                </div>
            ) : null}

            <div className="flex gap-2">
                <Input
                    ref={inputRef}
                    value={draft}
                    onChange={(event) => setDraft(event.target.value)}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter') {
                            // The form would otherwise submit on Enter.
                            event.preventDefault();
                            add(draft);
                        }
                    }}
                    list={`${name}-options`}
                    data-test="product-categories"
                    placeholder="Bags > Totes"
                    aria-label="Add a category"
                />
                <datalist id={`${name}-options`}>
                    {unused.map((option) => (
                        <option key={option} value={option} />
                    ))}
                </datalist>

                <Button
                    type="button"
                    variant="secondary"
                    onClick={() => add(draft)}
                    disabled={draft.trim() === ''}
                    data-test="category-add"
                >
                    <Plus /> Add
                </Button>
            </div>

            <p className="text-muted-foreground text-xs">
                Pick from the list or type a new one. Use &gt; to nest, as in
                Bags &gt; Totes.
            </p>
        </div>
    );
}
