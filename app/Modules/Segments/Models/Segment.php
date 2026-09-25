<?php

declare(strict_types=1);

namespace App\Modules\Segments\Models;

use App\Modules\Segments\Enums\SegmentType;
use Database\Factories\SegmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * PRD §09 `segments` (028). `rule` is the PRD §17 JSON Rule Schema — always run through
 * RuleValidator/RuleCompiler before it touches a query, never trusted as-is.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property SegmentType $type
 * @property array<mixed>|null $rule
 * @property int $rule_version
 * @property int $member_count
 * @property Carbon|null $last_evaluated_at
 * @property int|null $last_eval_ms
 * @property bool $is_active
 * @property bool $is_system
 * @property int|null $created_by
 */
class Segment extends Model
{
    /** @use HasFactory<SegmentFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'description', 'type', 'rule', 'rule_version',
        'member_count', 'last_evaluated_at', 'last_eval_ms',
        'is_active', 'is_system', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => SegmentType::class,
            'rule' => 'array',
            'member_count' => 'integer',
            'last_evaluated_at' => 'datetime',
            'last_eval_ms' => 'integer',
            'is_active' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    protected static function newFactory(): SegmentFactory
    {
        return SegmentFactory::new();
    }
}
