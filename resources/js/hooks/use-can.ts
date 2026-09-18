import { usePage } from '@inertiajs/react';

/**
 * UX only — hides/shows UI based on the permissions the server computed for
 * this request. Never the real security boundary; the `permission` route
 * middleware + PermissionService enforce access for real (CLAUDE.md §6/§20).
 */
export function useCan() {
    const { auth } = usePage().props;
    const permissions = auth.permissions;

    return (module: string, action: string): boolean =>
        permissions.includes(`${module}.${action}`);
}
