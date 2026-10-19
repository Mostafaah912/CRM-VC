<?php

declare(strict_types=1);

namespace App\Http\Requests\Ai;

use App\Modules\Core\Services\PermissionService;
use Illuminate\Foundation\Http\FormRequest;

/** The analyst page itself: no input. Route middleware already checked ai.view. */
class AnalystPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }

    /** Cost, tokens, model and prompt version are for owners/managers only (ai.view_cost); the server decides, the UI only reflects it. */
    public function canSeeCost(): bool
    {
        $user = $this->user();

        return $user !== null && app(PermissionService::class)->allows($user, 'ai', 'view_cost');
    }
}
