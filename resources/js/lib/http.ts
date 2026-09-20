/** Laravel puts the CSRF token for scripts in this cookie; a write without it is refused. */
export function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/** Headers of a read that wants JSON (a page would come back as HTML or an Inertia redirect otherwise). */
export const READ_HEADERS: Record<string, string> = {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
};

/** Headers of a write with a JSON body. */
export function writeHeaders(): Record<string, string> {
    return {
        ...READ_HEADERS,
        'Content-Type': 'application/json',
        'X-XSRF-TOKEN': xsrfToken(),
    };
}

/** The first message of a Laravel 422 body ({errors: {field: [message]}}) for one field, if there is one. */
export function validationMessage(body: unknown, field: string): string | null {
    if (typeof body !== 'object' || body === null || !('errors' in body)) {
        return null;
    }

    const errors = body.errors;

    if (typeof errors !== 'object' || errors === null || !(field in errors)) {
        return null;
    }

    const messages = (errors as Record<string, unknown>)[field];

    return Array.isArray(messages) && typeof messages[0] === 'string'
        ? messages[0]
        : null;
}
