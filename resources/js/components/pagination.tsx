import { Link } from '@inertiajs/react';

type Props = {
    currentPage: number;
    lastPage: number;
    prevUrl: string | null;
    nextUrl: string | null;
};

export function Pagination({ currentPage, lastPage, prevUrl, nextUrl }: Props) {
    if (lastPage <= 1) {
        return null;
    }

    return (
        <nav
            aria-label="صفحه‌بندی"
            className="flex items-center justify-center gap-4"
        >
            {prevUrl ? (
                <Link
                    href={prevUrl}
                    preserveScroll
                    className="text-sm hover:underline"
                >
                    قبلی
                </Link>
            ) : (
                <span className="text-muted-foreground text-sm opacity-50">
                    قبلی
                </span>
            )}

            <span className="text-muted-foreground text-sm">
                صفحه {currentPage} از {lastPage}
            </span>

            {nextUrl ? (
                <Link
                    href={nextUrl}
                    preserveScroll
                    className="text-sm hover:underline"
                >
                    بعدی
                </Link>
            ) : (
                <span className="text-muted-foreground text-sm opacity-50">
                    بعدی
                </span>
            )}
        </nav>
    );
}
