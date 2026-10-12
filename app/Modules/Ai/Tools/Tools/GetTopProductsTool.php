<?php

declare(strict_types=1);

namespace App\Modules\Ai\Tools\Tools;

use App\Modules\Ai\Tools\AiReadonlyDb;
use App\Modules\Ai\Tools\AiTool;
use App\Modules\Ai\Tools\SmallGroupMask;
use App\Modules\Ai\Tools\ToolFormat;

final class GetTopProductsTool extends AiTool
{
    public function __construct(private readonly AiReadonlyDb $db) {}

    public function name(): string
    {
        return 'get_top_products';
    }

    public function description(): string
    {
        return 'Best-selling products by lifetime revenue (Toman), units or number of buyers, with the share of all product revenue. Products with fewer than 5 buyers are never shown.';
    }

    public function properties(): array
    {
        return [
            'by' => ['type' => 'string', 'enum' => ['revenue', 'units', 'customers'], 'description' => 'Ranking measure. Default revenue.'],
            'limit' => ['type' => 'integer', 'description' => 'Products to return, 1 to 15. Default 10.'],
        ];
    }

    public function rules(): array
    {
        return ['by' => 'in:revenue,units,customers', 'limit' => 'integer|min:1|max:15'];
    }

    public function run(array $arguments): array
    {
        $by = (string) ($arguments['by'] ?? 'revenue');
        $limit = (int) ($arguments['limit'] ?? 10);
        $db = $this->db->connection();
        $order = ['revenue' => 'revenue_sum', 'units' => 'units_sum', 'customers' => 'buyers'][$by];

        $rows = $db->table('customer_product_purchases')->groupBy('product_id')
            ->havingRaw('count(*) >= ?', [SmallGroupMask::min()])
            ->selectRaw('product_id, count(*) as buyers, sum(items_count) as units_sum, sum(revenue) as revenue_sum')
            ->orderByDesc($order)->orderBy('product_id')->limit($limit)->get();

        $total = $db->table('customer_product_purchases')->sum('revenue');
        $names = $rows->isEmpty() ? [] : $db->table('products')->whereIn('id', $rows->pluck('product_id')->all())->pluck('name', 'id')->all();

        return [
            'ranked_by' => $by,
            'products' => $rows->map(fn ($r) => [
                'product_id' => (int) $r->product_id,
                'name' => $names[$r->product_id] ?? null,
                'buyers' => (int) $r->buyers,
                'units' => (int) $r->units_sum,
                'revenue' => ToolFormat::toman($r->revenue_sum),
                'revenue_share_percent' => ToolFormat::percent($r->revenue_sum, $total),
            ])->all(),
        ];
    }
}
