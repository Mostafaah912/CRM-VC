import { Eye, Loader2, Lock } from 'lucide-react';
import { useState } from 'react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { revealPhone } from '@/routes/customers';

type Props = {
    customerId: number;
    /** The masked number the list always sends (null: this customer has no phone). */
    maskedPhone: string | null;
    /** Whether the viewer holds customers.view_full_phone. UX only: the endpoint enforces it for real. */
    hasPermission: boolean;
};

type State =
    | { status: 'idle' }
    | { status: 'loading' }
    | { status: 'revealed'; phone: string }
    | { status: 'error'; message: string };

const MESSAGES = {
    tooMany: 'تعداد درخواست‌ها بیش از حد مجاز است',
    forbidden: 'دسترسی ندارید',
    serverError: 'خطای سرور، لطفاً بعداً تلاش کنید',
    failed: 'خطا در نمایش شماره',
    hint: 'برای مشاهده کلیک کنید',
    locked: 'مشاهده‌ی شماره‌ی کامل نیاز به دسترسی دارد',
};

/** 429 and 403 get their own wording; a server error (500+) is distinct too, so an outage never reads as a permission problem. */
function messageFor(status: number): string {
    if (status === 429) {
        return MESSAGES.tooMany;
    }

    if (status === 403) {
        return MESSAGES.forbidden;
    }

    if (status >= 500) {
        return MESSAGES.serverError;
    }

    return MESSAGES.failed;
}

/** Laravel puts the CSRF token for scripts in this cookie; a POST without it is refused. */
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

function phoneFrom(body: unknown): string | null {
    if (
        typeof body === 'object' &&
        body !== null &&
        'phone' in body &&
        typeof body.phone === 'string'
    ) {
        return body.phone;
    }

    return null;
}

/**
 * A customer's phone in the list. The list only ever carries the masked number; a viewer who may reveal clicks it, one request
 * asks the audited endpoint, and the full number is shown from this component's own state — never stored, logged or shared.
 */
export function PhoneRevealButton({
    customerId,
    maskedPhone,
    hasPermission,
}: Props) {
    const [state, setState] = useState<State>({ status: 'idle' });

    if (maskedPhone === null) {
        return <span className="text-muted-foreground">—</span>;
    }

    if (state.status === 'revealed') {
        return (
            <span dir="ltr" className="font-mono">
                {state.phone}
            </span>
        );
    }

    if (!hasPermission) {
        return (
            <Tooltip>
                <TooltipTrigger asChild>
                    <span
                        dir="ltr"
                        className="text-muted-foreground inline-flex items-center gap-1.5 font-mono"
                    >
                        {maskedPhone}
                        <Lock
                            aria-label={MESSAGES.locked}
                            className="size-3.5"
                        />
                    </span>
                </TooltipTrigger>
                <TooltipContent>{MESSAGES.locked}</TooltipContent>
            </Tooltip>
        );
    }

    const reveal = async () => {
        setState({ status: 'loading' });

        try {
            const response = await fetch(revealPhone.url(customerId), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
            });

            if (!response.ok) {
                setState({
                    status: 'error',
                    message: messageFor(response.status),
                });

                return;
            }

            const phone = phoneFrom(await response.json());

            setState(
                phone === null
                    ? { status: 'error', message: MESSAGES.failed }
                    : { status: 'revealed', phone },
            );
        } catch {
            setState({ status: 'error', message: MESSAGES.failed });
        }
    };

    return (
        <span className="inline-flex flex-col gap-1">
            <Tooltip>
                <TooltipTrigger asChild>
                    <button
                        type="button"
                        onClick={reveal}
                        disabled={state.status === 'loading'}
                        aria-busy={state.status === 'loading'}
                        className="inline-flex cursor-pointer items-center gap-1.5 font-mono disabled:cursor-wait"
                    >
                        <span dir="ltr">{maskedPhone}</span>
                        {state.status === 'loading' ? (
                            <Loader2
                                aria-hidden="true"
                                className="size-3.5 animate-spin"
                            />
                        ) : (
                            <Eye aria-hidden="true" className="size-3.5" />
                        )}
                        <span className="sr-only">{MESSAGES.hint}</span>
                    </button>
                </TooltipTrigger>
                <TooltipContent>{MESSAGES.hint}</TooltipContent>
            </Tooltip>
            {state.status === 'error' && (
                <span role="alert" className="text-destructive text-xs">
                    {state.message}
                </span>
            )}
        </span>
    );
}
