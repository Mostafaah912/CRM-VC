<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\DemoDataSeeder;
use Illuminate\Console\Command;

/** P8-03: load the deterministic demo dataset into a STAGING database. Refuses in every other environment — production above all. */
final class StagingSeedCommand extends Command
{
    protected $signature = 'hm:staging:seed';

    protected $description = 'Seed the demo dataset (DemoDataSeeder). Only runs when APP_ENV=staging';

    public function handle(): int
    {
        if (! app()->environment('staging')) {
            $this->error('Refused: hm:staging:seed only runs when APP_ENV=staging (this is "'.app()->environment().'").');

            return self::FAILURE;
        }

        return $this->call('db:seed', ['--class' => DemoDataSeeder::class, '--force' => true]);
    }
}
