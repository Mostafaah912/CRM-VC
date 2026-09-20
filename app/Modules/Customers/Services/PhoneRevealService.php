<?php

declare(strict_types=1);

namespace App\Modules\Customers\Services;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\PhoneRevealLog;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * P3-02: the ONE way a customer's full phone is released. The audit row (who, which customer, when, from which address and
 * browser) and the read are one transaction, so a number is never released without its audit row and an audit row never
 * survives a reveal that failed; the number is returned only after the commit.
 *
 *  - A soft-deleted customer, or one with no phone, reveals nothing and audits nothing (404) — checked here, whatever the HTTP
 *    layer did, and before anything is written.
 *  - Who is asking comes from the request, never the Auth facade.
 *  - The user agent is cut to 500 characters (characters, not bytes, so a multibyte name is never split mid-character).
 *  - Nothing is logged, and no message names a phone or a customer.
 */
final class PhoneRevealService
{
    private const USER_AGENT_MAX = 500;

    /**
     * @return string the customer's full normalized phone
     *
     * @throws AuthenticationException when nobody is signed in on the request
     * @throws NotFoundHttpException when the customer is soft-deleted or has no phone
     */
    public function reveal(Customer $customer, Request $request): string
    {
        $viewer = $request->user() ?? throw new AuthenticationException;

        if ($customer->trashed() || trim((string) $customer->phone_normalized) === '') {
            throw new NotFoundHttpException('There is no phone to reveal.');
        }

        return DB::transaction(function () use ($customer, $viewer, $request): string {
            PhoneRevealLog::query()->create([
                'customer_id' => $customer->id,
                'revealed_by' => (int) $viewer->getAuthIdentifier(),
                'ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, self::USER_AGENT_MAX),
            ]);

            return $customer->phone_normalized;
        });
    }
}
