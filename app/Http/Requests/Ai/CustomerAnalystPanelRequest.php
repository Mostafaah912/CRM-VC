<?php

declare(strict_types=1);

namespace App\Http\Requests\Ai;

use App\Modules\Core\Services\PermissionService;
use Illuminate\Foundation\Http\FormRequest;

/** GET /customers/{customer}/analyst-panel: no input beyond the id. Route middleware checked customers.view; the two optional parts follow segments.view and ai.view. */
final class CustomerAnalystPanelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, never> */
    public function rules(): array
    {
        return [];
    }

    public function customerId(): int
    {
        return (int) $this->route('customer');
    }

    public function canSeeSegments(): bool
    {
        return $this->allows('segments', 'view');
    }

    public function canSeeInsight(): bool
    {
        return $this->allows('ai', 'view');
    }

    private function allows(string $module, string $action): bool
    {
        $user = $this->user();

        return $user !== null && app(PermissionService::class)->allows($user, $module, $action);
    }
}
