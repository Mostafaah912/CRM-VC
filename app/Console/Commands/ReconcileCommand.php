<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Sync\Exceptions\ReconciliationMonthException;
use App\Modules\Sync\Services\ReconciliationService;
use App\Modules\Sync\Support\SafeErrorText;
use Illuminate\Console\Command;
use Throwable;

/**
 * `hm:reconcile` (P2-11): compare Woo with the local orders, month by month (GATE 1). `--month=YYYY-MM` (Jalali) reconciles
 * that month now and prints one line; `--all` queues a job per complete month; with neither, the last complete month is
 * reconciled now. A red month is a RESULT (exit 0); usage errors and failures exit 1. It prints counts and a percentage —
 * never a credential, an order or a payload.
 */
final class ReconcileCommand extends Command
{
    protected $signature = 'hm:reconcile {--month= : Reconcile this Jalali month (YYYY-MM) now} {--all : Queue every complete month}';

    protected $description = 'Reconcile Woo with the local orders per Jalali month (GATE 1)';

    public function handle(ReconciliationService $reconciliation): int
    {
        $month = $this->option('month');

        if ($month !== null && $this->option('all')) {
            $this->error('Use either --month or --all, not both.');

            return self::FAILURE;
        }

        try {
            if ($this->option('all')) {
                $this->line($this->queued($reconciliation->dispatchAllMonths()));

                return self::SUCCESS;
            }

            $this->line($reconciliation->reconcile($month ?? $reconciliation->lastCompleteMonth())->summary());

            return self::SUCCESS;
        } catch (ReconciliationMonthException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Reconciliation failed: '.SafeErrorText::from($e, 300));

            return self::FAILURE;
        }
    }

    /** @param  list<string>  $months */
    private function queued(array $months): string
    {
        if ($months === []) {
            return 'No complete month to reconcile yet';
        }

        return sprintf('Dispatched reconciliation for %d %s (%s to %s)', count($months), count($months) === 1 ? 'month' : 'months', $months[0], $months[array_key_last($months)]);
    }
}
