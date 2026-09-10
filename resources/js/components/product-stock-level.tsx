import { AlertTriangle } from 'lucide-react';

type Props = {
    stock: number;
    lowStockThreshold: number;
};

export function ProductStockLevel({ stock, lowStockThreshold }: Props) {
    if (stock === 0) {
        return (
            <span className="font-medium text-red-600 dark:text-red-400">
                Out of stock
            </span>
        );
    }

    if (stock <= lowStockThreshold) {
        return (
            <span className="inline-flex items-center gap-1.5 font-medium text-amber-600 dark:text-amber-400">
                <AlertTriangle className="size-3.5" />
                {stock.toLocaleString()} left
            </span>
        );
    }

    return <span>{stock.toLocaleString()} in stock</span>;
}
