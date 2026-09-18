<?php

declare(strict_types=1);

namespace App\Modules\Core\Events;

use App\Modules\Core\Enums\SettingKey;
use Illuminate\Foundation\Events\Dispatchable;

class SettingChanged
{
    use Dispatchable;

    public function __construct(
        public readonly SettingKey $key,
        public readonly bool|int|float $old,
        public readonly bool|int|float $new,
    ) {}
}
