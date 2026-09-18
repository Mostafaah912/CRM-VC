<?php

declare(strict_types=1);

namespace App\Modules\Core\Enums;

/** PRD §20's five fixed Phase 1 roles. */
enum RoleName: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Analyst = 'analyst';
    case Support = 'support';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'مالک',
            self::Manager => 'مدیر',
            self::Analyst => 'تحلیلگر',
            self::Support => 'پشتیبانی',
            self::Viewer => 'ناظر',
        };
    }
}
