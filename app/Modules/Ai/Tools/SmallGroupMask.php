<?php

declare(strict_types=1);

namespace App\Modules\Ai\Tools;

/**
 * Privacy floor for categorical groups. A group below `ai.tools.small_group_min` customers (default 5) could be one
 * identifiable person, so it is never shown on its own: small groups are folded into ONE row carrying only a
 * count, and if even that merged count is below the floor the count itself becomes "<5".
 */
final class SmallGroupMask
{
    public static function min(): int
    {
        return max(2, (int) config('ai.tools.small_group_min', 5));
    }

    /**
     * @param  array<array-key, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function apply(array $rows, string $groupKey, string $countKey = 'customers'): array
    {
        $kept = [];
        $mergedCount = 0;
        $mergedGroups = 0;

        foreach ($rows as $row) {
            if ((int) $row[$countKey] >= self::min()) {
                $kept[] = $row;
            } else {
                $mergedCount += (int) $row[$countKey];
                $mergedGroups++;
            }
        }

        if ($mergedGroups > 0) {
            $kept[] = [
                $groupKey => 'other_small_groups',
                $countKey => $mergedCount >= self::min() ? $mergedCount : '<'.self::min(),
                'merged_groups' => $mergedGroups,
            ];
        }

        return $kept;
    }

    public static function count(int $n): int|string
    {
        return $n >= self::min() ? $n : '<'.self::min();
    }
}
