<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Models\User;

/** Account mutations for the signed-in user; keeps DB writes out of the settings controllers. */
final class UserAccountService
{
    /** @param  array<string, mixed>  $attributes */
    public function updateProfile(User $user, array $attributes): void
    {
        $user->fill($attributes);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();
    }

    public function changePassword(User $user, string $password): void
    {
        $user->update(['password' => $password]);
    }

    public function deleteAccount(User $user): void
    {
        $user->delete();
    }

    /** @return array<int, array<string, mixed>> */
    public function passkeyList(User $user): array
    {
        return $user->passkeys()
            ->select(['id', 'name', 'credential', 'created_at', 'last_used_at'])
            ->latest()
            ->get()
            ->map(fn ($passkey) => [
                'id' => $passkey->id,
                'name' => $passkey->name,
                'authenticator' => $passkey->authenticator,
                'created_at_diff' => $passkey->created_at->diffForHumans(),
                'last_used_at_diff' => $passkey->last_used_at?->diffForHumans(),
            ])
            ->values()
            ->all();
    }
}
