<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Sync\Services\FakeWooClient;
use App\Modules\Sync\Support\WooFixture;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Loads the recorded Woo exchanges under tests/fixtures/woo into a FakeWooClient.
 * Layout: the folder mirrors the Woo endpoint; each file is one recorded exchange
 * { request: {endpoint, page, query}, response: {status, headers, body, attempts?} }.
 * Files are read in sorted order so loading is deterministic.
 */
final class WooFixtures
{
    public static function directory(): string
    {
        return dirname(__DIR__).'/fixtures/woo';
    }

    /**
     * Every fixture file, at any depth (the folders mirror endpoints such as orders/5001/refunds), sorted.
     *
     * @return list<string>
     */
    public static function paths(): array
    {
        $paths = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::directory(), FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'json') {
                $paths[] = $file->getPathname();
            }
        }

        sort($paths);

        return $paths;
    }

    /** @return list<WooFixture> */
    public static function all(): array
    {
        return array_map(function (string $path): WooFixture {
            $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($decoded)) {
                throw new RuntimeException("Woo fixture {$path} is not a JSON object.");
            }

            return WooFixture::fromArray($decoded);
        }, self::paths());
    }

    public static function client(): FakeWooClient
    {
        return new FakeWooClient(self::all());
    }
}
