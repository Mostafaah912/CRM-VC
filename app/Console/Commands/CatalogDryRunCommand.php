<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Sync\Services\CatalogDryRunService;
use Illuminate\Console\Command;

/**
 * `hm:catalog-dry-run` (P6 decision, ARCHITECTURE.md): read-only, GET-only check of the real Woo catalog
 * against the P2-03 mappers before trusting a live CatalogSyncJob run — prints how much of it maps
 * cleanly and every distinct failure reason it found, with a count and one sample each. Never writes
 * anything: CatalogDryRunService has no CatalogService dependency to write with.
 */
final class CatalogDryRunCommand extends Command
{
    protected $signature = 'hm:catalog-dry-run';

    protected $description = 'Read-only: validate every real Woo product/variation against the catalog mappers, writing nothing';

    public function handle(CatalogDryRunService $service): int
    {
        $result = $service->scan();

        $this->line("products scanned: {$result->productsScanned}, valid: {$result->productsValid}");
        $this->line("variations scanned: {$result->variationsScanned}, valid: {$result->variationsValid}");

        foreach ($result->errorClasses as $reason => $info) {
            $this->line("- {$reason} (x{$info['count']}) e.g. {$info['sample']}");
        }

        return self::SUCCESS;
    }
}
