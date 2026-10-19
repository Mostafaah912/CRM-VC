<?php

declare(strict_types=1);

namespace App\Http\Requests\Ai;

use App\Models\User;
use App\Modules\Core\Services\PermissionService;

/** POST body: {type, force?}. Needs ai.request (also enforced by the route middleware — this is the second, in-request check). */
final class AnalystGenerateRequest extends AnalystTypeRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && app(PermissionService::class)->allows($user, 'ai', 'request');
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['type' => parent::rules()['type'], 'force' => ['nullable', 'boolean']];
    }

    public function force(): bool
    {
        return (bool) ($this->validated('force') ?? false);
    }

    public function actor(): User
    {
        /** @var User $user */
        $user = $this->user();

        return $user;
    }
}
