<?php

declare(strict_types=1);

namespace App\Modules\Ai\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per Asia/Tehran calendar day (PRD §09 ai_usage_daily). Written only by AiUsageService.
 *
 * @property string $date
 * @property int $requests
 * @property int $input_tokens
 * @property int $output_tokens
 * @property string $cost_usd numeric(10,5) as a decimal string — never a float
 */
class AiUsageDaily extends Model
{
    protected $table = 'ai_usage_daily';

    protected $primaryKey = 'date';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];
}
