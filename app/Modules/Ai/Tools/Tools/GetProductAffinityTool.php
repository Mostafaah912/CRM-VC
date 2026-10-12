<?php

declare(strict_types=1);

namespace App\Modules\Ai\Tools\Tools;

use App\Modules\Ai\Tools\AiReadonlyDb;
use App\Modules\Ai\Tools\AiTool;
use App\Modules\Ai\Tools\SmallGroupMask;

final class GetProductAffinityTool extends AiTool
{
    public function __construct(private readonly AiReadonlyDb $db) {}

    public function name(): string
    {
        return 'get_product_affinity';
    }

    public function description(): string
    {
        return 'Strongest co-purchase pairs by lift at one level: category, product, variation (ids only, no names available) or basket (counts are baskets, not customers). Pairs bought together by fewer than 5 are never shown.';
    }

    public function properties(): array
    {
        return [
            'level' => ['type' => 'string', 'enum' => ['category', 'product', 'variation', 'basket'], 'description' => 'Affinity level. Default product.'],
            'limit' => ['type' => 'integer', 'description' => 'Pairs to return, 1 to 15. Default 10.'],
        ];
    }

    public function rules(): array
    {
        return ['level' => 'in:category,product,variation,basket', 'limit' => 'integer|min:1|max:15'];
    }

    public function run(array $arguments): array
    {
        $level = (string) ($arguments['level'] ?? 'product');
        $limit = (int) ($arguments['limit'] ?? 10);
        $db = $this->db->connection();
        $rows = $db->table('product_affinities')->where('level', $level)->where('co_customers', '>=', SmallGroupMask::min())
            ->orderByDesc('lift')->orderByDesc('co_customers')->limit($limit)
            ->get(['entity_a_id', 'entity_b_id', 'co_customers', 'a_customers', 'b_customers', 'confidence', 'lift']);

        $names = [];
        $nameTable = ['category' => 'product_categories', 'product' => 'products', 'basket' => 'products'][$level] ?? null;

        if ($nameTable !== null && $rows->isNotEmpty()) {
            $ids = $rows->pluck('entity_a_id')->merge($rows->pluck('entity_b_id'))->unique()->all();
            $names = $db->table($nameTable)->whereIn('id', $ids)->pluck('name', 'id')->all();
        }

        return [
            'level' => $level,
            'unit' => $level === 'basket' ? 'baskets' : 'customers',
            'pairs' => $rows->map(fn ($r) => [
                'a' => ['id' => (int) $r->entity_a_id, 'name' => $names[$r->entity_a_id] ?? null],
                'b' => ['id' => (int) $r->entity_b_id, 'name' => $names[$r->entity_b_id] ?? null],
                'co_count' => (int) $r->co_customers,
                'a_count' => (int) $r->a_customers,
                'b_count' => (int) $r->b_customers,
                'confidence_percent' => round((float) $r->confidence * 100, 2),
                'lift' => round((float) $r->lift, 2),
            ])->all(),
        ];
    }
}
