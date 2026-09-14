import type { InertiaLinkProps } from '@inertiajs/react';
import { clsx } from 'clsx';
import type { ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export function toUrl(url: NonNullable<InertiaLinkProps['href']>): string {
    return typeof url === 'string' ? url : url.url;
}

const currencyFormatters = new Map<string, Intl.NumberFormat>();

export function formatCurrency(
    value: number | string,
    currency: string,
): string {
    let formatter = currencyFormatters.get(currency);

    if (!formatter) {
        formatter = new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency,
        });
        currencyFormatters.set(currency, formatter);
    }

    return formatter.format(Number(value));
}
