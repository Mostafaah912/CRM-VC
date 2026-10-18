<?php

declare(strict_types=1);

use App\Modules\Ai\Jobs\GenerateDailyBriefJob;
use App\Modules\Ai\Jobs\GenerateWeeklyReviewJob;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/*
| P7-08 — PRD §22: "daily 07:00 GenerateDailyBriefJob", "weekly Sat 07:30 GenerateWeeklyReviewJob", Asia/Tehran. Registered as their OWN
| entries, never inside hm:nightly-chain (a Bus::chain stops at its first failure; an AI failure must not stop the data chain).
*/

function scheduled(): array
{
    return app(Schedule::class)->events();
}

function eventFor(string $needle): ?Event
{
    foreach (scheduled() as $event) {
        if (str_contains((string) ($event instanceof CallbackEvent ? $event->description : $event->command), $needle)) {
            return $event;
        }
    }

    return null;
}

it('schedules the daily brief at 07:00 and the weekly review on Saturday at 07:30, Asia/Tehran', function () {
    $daily = eventFor(GenerateDailyBriefJob::class);
    $weekly = eventFor(GenerateWeeklyReviewJob::class);

    expect($daily)->not->toBeNull()->and($daily->expression)->toBe('0 7 * * *')->and((string) $daily->timezone)->toBe('Asia/Tehran')
        ->and($weekly)->not->toBeNull()->and($weekly->expression)->toBe('30 7 * * 6')->and((string) $weekly->timezone)->toBe('Asia/Tehran');
});

it('keeps the times and the weekday configurable (the schedule reads ai.generation.*)', function () {
    $config = (require base_path('config/ai.php'))['generation'];

    expect($config)->toMatchArray(['daily_at' => '07:00', 'weekly_at' => '07:30', 'weekly_day' => 6, 'max_data_age_days' => 3, 'chain_start_hour' => 3]);
});

it('does not touch the nightly chain: it stays a separate scheduler entry and knows nothing about AI', function () {
    $chain = eventFor('hm:nightly-chain');

    expect($chain)->not->toBeNull()->and($chain->expression)->toBe('0 3 * * *')
        ->and(eventFor(GenerateDailyBriefJob::class))->not->toBe($chain)
        ->and(file_get_contents(base_path('app/Console/Commands/NightlyChainCommand.php')))->not->toContain('Ai\\')->not->toContain('GenerateDailyBriefJob')->not->toContain('GenerateWeeklyReviewJob');
});

it('runs the AI entries after the nightly chain has normally finished and the data jobs', function () {
    $chain = eventFor('hm:nightly-chain');
    $daily = eventFor(GenerateDailyBriefJob::class);

    expect((int) explode(' ', $daily->expression)[1])->toBeGreaterThan((int) explode(' ', $chain->expression)[1] + 3);
});
