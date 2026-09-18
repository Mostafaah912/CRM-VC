<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Arr;

/**
 * Raw Woo payloads for mapper tests, taken from the P2-02 recorded fixtures (no second copy of
 * the data) plus dot-path editing helpers to produce the malformed variants.
 */
final class WooPayloads
{
    /**
     * Items of a recorded page, exactly as FakeWooClient replays them.
     *
     * @param  array<string, scalar>  $query
     * @return list<array<array-key, mixed>>
     */
    public static function items(string $endpoint, int $page = 1, array $query = []): array
    {
        return WooFixtures::client()->page($endpoint, $page, null, $query)->items;
    }

    /** @return array<array-key, mixed> */
    public static function first(string $endpoint, int $page = 1): array
    {
        return self::items($endpoint, $page)[0];
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public static function set(array $payload, string $path, mixed $value): array
    {
        Arr::set($payload, $path, $value);

        return $payload;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public static function without(array $payload, string $path): array
    {
        Arr::forget($payload, $path);

        return $payload;
    }
}
