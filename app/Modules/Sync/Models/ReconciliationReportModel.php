<?php

declare(strict_types=1);

namespace App\Modules\Sync\Models;

use App\Modules\Sync\Enums\ReconciliationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One Jalali month's reconciliation (PRD §09 reconciliation_reports + the P2-11 additions): a row per month, replaced when
 * the month is reconciled again. Revenue is integer Toman; `diff_percent` is the absolute variance in percent, four
 * decimals. A `failed` month holds no measurements. Written only by ReconciliationService.
 *
 * @property int $id
 * @property string $jalali_month
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property int|null $woo_orders
 * @property int|null $crm_orders
 * @property int|null $woo_revenue
 * @property int|null $crm_revenue
 * @property int|null $orders_diff
 * @property int|null $revenue_diff
 * @property string|null $diff_percent
 * @property bool $is_acceptable
 * @property array<string, mixed>|null $details
 * @property ReconciliationStatus $status
 * @property string|null $error_message
 * @property CarbonImmutable|null $reconciled_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class ReconciliationReportModel extends Model
{
    protected $table = 'reconciliation_reports';

    protected $fillable = [
        'jalali_month', 'period_start', 'period_end',
        'woo_orders', 'crm_orders', 'woo_revenue', 'crm_revenue', 'orders_diff', 'revenue_diff', 'diff_percent',
        'is_acceptable', 'details', 'status', 'error_message', 'reconciled_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'woo_orders' => 'integer',
            'crm_orders' => 'integer',
            'woo_revenue' => 'integer',
            'crm_revenue' => 'integer',
            'orders_diff' => 'integer',
            'revenue_diff' => 'integer',
            'diff_percent' => 'decimal:4',
            'is_acceptable' => 'boolean',
            'details' => 'array',
            'status' => ReconciliationStatus::class,
            'reconciled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
