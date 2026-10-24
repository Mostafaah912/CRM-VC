<?php

declare(strict_types=1);

/*
| Horizon starts supervisors for the environment named by APP_ENV only. An environment that is missing from
| config/horizon.php boots a master process with no workers: "running", but nothing is ever processed. Staging (P8-03)
| and production must each have a supervisor, and every environment must keep the PRD §22 queue order of `defaults`.
*/

it('defines a supervisor for every environment this app is deployed in', function (string $env) {
    expect(config("horizon.environments.{$env}.supervisor-1.maxProcesses"))->toBeInt()->toBeGreaterThan(0);
})->with(['production', 'staging', 'local']);

it('keeps the PRD §22 queue priority in the shared defaults, so no environment can reorder or drop a queue', function () {
    expect(config('horizon.defaults.supervisor-1.queue'))->toBe(['critical', 'sync', 'metrics', 'ai', 'default'])
        ->and(config('horizon.environments.staging.supervisor-1'))->not->toHaveKey('queue');
});
