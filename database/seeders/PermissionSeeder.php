<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Core\Models\Permission;
use Illuminate\Database\Seeder;

/**
 * The permission catalog grounded in PRD §19/§20's explicitly named
 * permissions, plus one `view` (and `note`) action per module PRD's role
 * matrix references. Modules with no code yet (Customers, Orders, Segments,
 * ...) still get their catalog rows here — a permission row is just
 * metadata, it doesn't require the module's own code to exist. Each real
 * module adds its own finer-grained actions to this catalog as it's built.
 */
class PermissionSeeder extends Seeder
{
    /** @return list<array{module: string, action: string, label: string}> */
    public static function catalog(): array
    {
        return [
            ['module' => 'audit', 'action' => 'view', 'label' => 'مشاهده گزارش رخدادها'],
            ['module' => 'settings', 'action' => 'manage', 'label' => 'مدیریت تنظیمات'],
            ['module' => 'users', 'action' => 'manage', 'label' => 'مدیریت کاربران و نقش‌ها'],
            ['module' => 'system', 'action' => 'view', 'label' => 'مشاهده سلامت سیستم و گزارش همگام‌سازی'],

            ['module' => 'customers', 'action' => 'view', 'label' => 'مشاهده مشتریان'],
            ['module' => 'customers', 'action' => 'view_full_phone', 'label' => 'مشاهده شماره موبایل کامل'],
            ['module' => 'customers', 'action' => 'note', 'label' => 'ثبت یادداشت برای مشتری'],
            ['module' => 'customers', 'action' => 'export', 'label' => 'خروجی گرفتن از مشتریان'],
            ['module' => 'customers', 'action' => 'anonymize', 'label' => 'ناشناس‌سازی مشتری'],
            ['module' => 'identity', 'action' => 'review', 'label' => 'بازبینی تعارض هویت'],

            ['module' => 'orders', 'action' => 'view', 'label' => 'مشاهده سفارش‌ها'],

            ['module' => 'segments', 'action' => 'view', 'label' => 'مشاهده سگمنت‌ها'],
            ['module' => 'segments', 'action' => 'create', 'label' => 'ساخت سگمنت'],
            ['module' => 'segments', 'action' => 'edit', 'label' => 'ویرایش سگمنت'],
            ['module' => 'segments', 'action' => 'delete', 'label' => 'حذف سگمنت'],

            ['module' => 'metrics', 'action' => 'view', 'label' => 'مشاهده معیارها'],

            ['module' => 'dashboard', 'action' => 'view', 'label' => 'مشاهده داشبورد'],
            ['module' => 'analytics', 'action' => 'view', 'label' => 'مشاهده تحلیل‌ها'],

            ['module' => 'ai', 'action' => 'view', 'label' => 'مشاهده بینش هوش مصنوعی'],
            ['module' => 'ai', 'action' => 'request', 'label' => 'درخواست تحلیل از هوش مصنوعی'],
        ];
    }

    public function run(): void
    {
        foreach (self::catalog() as $permission) {
            Permission::query()->firstOrCreate(
                ['module' => $permission['module'], 'action' => $permission['action']],
                ['label' => $permission['label']],
            );
        }
    }
}
