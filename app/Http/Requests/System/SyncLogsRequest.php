<?php

declare(strict_types=1);

namespace App\Http\Requests\System;

use App\Modules\Sync\Enums\SyncEntity;
use App\Modules\Sync\Enums\SyncStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The two optional filters of the sync-logs page. An unknown value is rejected, not ignored. Access itself is the route's `permission:system,view`. */
class SyncLogsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(SyncStatus::class)],
            'entity' => ['nullable', Rule::enum(SyncEntity::class)],
        ];
    }

    public function statusFilter(): ?SyncStatus
    {
        return $this->enum('status', SyncStatus::class);
    }

    public function entityFilter(): ?SyncEntity
    {
        return $this->enum('entity', SyncEntity::class);
    }
}
