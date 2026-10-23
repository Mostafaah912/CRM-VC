import { usePage } from '@inertiajs/react';

/** A thin fixed bar shown only when the server says APP_ENV=staging (P8-03), so nobody mistakes staging for production. */
export function StagingBanner() {
    const { staging } = usePage().props;

    if (staging !== true) {
        return null;
    }

    return (
        <div
            role="status"
            dir="ltr"
            className="fixed inset-x-0 bottom-0 z-50 bg-amber-500 py-0.5 text-center text-xs font-bold tracking-widest text-black"
        >
            STAGING
        </div>
    );
}
