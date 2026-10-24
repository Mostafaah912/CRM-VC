<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/* P8-03 — deploy/deploy.sh. NEVER executed for real here: syntax, argument guards and --dry-run only. */

function deploy(array $args, array $env = [], ?string $stdin = null): Process
{
    $p = new Process(['bash', base_path('deploy/deploy.sh'), ...$args], base_path(), $env);
    $stdin === null || $p->setInput($stdin);
    $p->run();

    return $p;
}

/** A throw-away git checkout standing in for the server's app directory. */
function fakeApp(string $appEnv = 'staging', bool $withRelease = false): string
{
    $dir = sys_get_temp_dir().'/hm-deploy-'.bin2hex(random_bytes(5));
    mkdir($dir.'/storage/logs', 0777, true);
    file_put_contents($dir.'/.env', "APP_ENV={$appEnv}\nDB_DATABASE=x\nAPP_URL=https://example.test\n");
    $sh = fn (string $c) => (new Process(['bash', '-c', $c], $dir))->mustRun()->getOutput();
    $sh('git init -q && git config user.email t@t && git config user.name t && echo 1 > a && git add a .env && git commit -qm one && echo 2 > a && git commit -qam two && git remote add origin "$PWD"');
    $shas = array_map('trim', explode("\n", trim($sh('git log --format=%H'))));

    if ($withRelease) {
        file_put_contents($dir.'/storage/logs/releases.log', "2026-10-20T10:00:00Z\tstaging\tfrom={$shas[1]}\tto={$shas[0]}\tok\n");
    }

    return $dir;
}

it('is valid bash', function () {
    foreach (['deploy.sh', 'harden.sh', 'verify-hardening.sh', 'provision.sh'] as $file) {
        $p = new Process(['bash', '-n', base_path("deploy/{$file}")]);
        $p->run();
        expect($p->isSuccessful())->toBeTrue($p->getErrorOutput());
    }
});

it('passes shellcheck when it is installed', function () {
    $which = new Process(['which', 'shellcheck']);
    $which->run();

    if (! $which->isSuccessful()) {
        $this->markTestSkipped('shellcheck not installed');
    }

    $p = new Process(['shellcheck', '--severity=warning', base_path('deploy/deploy.sh')]);
    $p->run();
    expect($p->isSuccessful())->toBeTrue($p->getOutput());
});

it('refuses an unknown environment, no arguments, and --skip-backup for production', function () {
    expect(deploy(['qa'])->getExitCode())->toBe(2)
        ->and(deploy([])->getExitCode())->toBe(2)
        ->and(deploy(['production', '--skip-backup', '--dry-run']))->toBeInstanceOf(Process::class);

    $p = deploy(['production', '--skip-backup', '--dry-run']);
    expect($p->getExitCode())->toBe(2)->and($p->getErrorOutput())->toContain('--skip-backup is refused for production');
});

it('--dry-run prints every step in the documented order and changes nothing', function () {
    $dir = fakeApp('production');
    $before = trim((new Process(['git', 'rev-parse', 'HEAD'], $dir))->mustRun()->getOutput());

    $p = deploy(['production', '--dry-run', '--ref', 'HEAD'], ['APP_DIR' => $dir]);
    $out = $p->getOutput();

    expect($p->getExitCode())->toBe(0, $out.$p->getErrorOutput());

    $steps = ['backup:run', 'artisan down', 'git checkout --detach', 'composer install --no-dev', 'npm ci', 'npm run build', 'migrate --force',
        'config:cache', 'route:cache', 'view:cache', 'event:cache', 'horizon:terminate', 'systemctl reload', 'artisan up', 'smoke check'];
    $last = -1;

    foreach ($steps as $step) {
        $pos = strpos($out, $step);
        expect($pos)->not->toBeFalse("missing step: {$step}")->and($pos)->toBeGreaterThan($last, "out of order: {$step}");
        $last = $pos;
    }

    expect($out)->toContain('dry-run: nothing was changed')
        ->and(trim((new Process(['git', 'rev-parse', 'HEAD'], $dir))->mustRun()->getOutput()))->toBe($before)
        ->and(file_exists($dir.'/storage/logs/releases.log'))->toBeFalse();
});

it('puts the production backup BEFORE maintenance mode and the staging skip works', function () {
    $dir = fakeApp('staging');

    $staging = deploy(['staging', '--dry-run', '--skip-backup', '--ref', 'HEAD'], ['APP_DIR' => $dir])->getOutput();
    expect($staging)->not->toContain('backup:run')->and($staging)->toContain('Backup skipped');

    $withBackup = deploy(['staging', '--dry-run', '--ref', 'HEAD'], ['APP_DIR' => $dir])->getOutput();
    expect(strpos($withBackup, 'backup:run'))->toBeLessThan(strpos($withBackup, 'artisan down'));
});

it('warns (dry-run) when .env does not match the requested environment', function () {
    $out = deploy(['production', '--dry-run', '--ref', 'HEAD'], ['APP_DIR' => fakeApp('staging')])->getOutput();

    expect($out)->toContain("APP_ENV='staging' but you asked to deploy 'production'");
});

it('REFUSES a real (non-dry) deploy when .env is for another environment — before touching anything', function () {
    $dir = fakeApp('staging');
    $p = deploy(['production', '--ref', 'HEAD'], ['APP_DIR' => $dir]);

    expect($p->getExitCode())->toBe(1)->and($p->getOutput())->toContain('Refusing: wrong server or wrong .env')
        ->and($p->getOutput())->not->toContain('maintenance');
});

it('production demands the typed confirmation (no terminal -> refuses)', function () {
    $dir = fakeApp('production');
    $p = deploy(['production', '--ref', 'HEAD'], ['APP_DIR' => $dir], "deploy production\n");

    expect($p->getExitCode())->toBe(1)->and($p->getOutput())->toContain('interactive terminal');
});

it('rollback targets the previous ref from releases.log and says it never touches migrations', function () {
    $dir = fakeApp('staging', withRelease: true);
    $shas = array_map('trim', explode("\n", trim((new Process(['git', 'log', '--format=%H'], $dir))->mustRun()->getOutput())));

    $p = deploy(['rollback', 'staging', '--dry-run'], ['APP_DIR' => $dir]);
    $out = $p->getOutput();

    expect($p->getExitCode())->toBe(0, $out)
        ->and($out)->toContain('Rolling back staging: '.substr($shas[0], 0, 10).' -> '.substr($shas[1], 0, 10))
        ->and($out)->toContain('git checkout --detach '.$shas[1])
        ->and($out)->toContain('ROLLBACK DOES NOT TOUCH THE DATABASE')
        ->and($out)->toContain('migrate:rollback --step=N')
        ->and($out)->not->toContain('artisan migrate --force');
});

it('deploy.sh reloads php<PHP_VERSION>-fpm (8.3 by default) instead of a hard-coded version', function () {
    $dir = fakeApp('staging');
    $args = ['staging', '--dry-run', '--skip-backup', '--ref', 'HEAD'];

    expect(deploy($args, ['APP_DIR' => $dir])->getOutput())->toContain('Reloading php8.3-fpm')
        ->and(deploy($args, ['APP_DIR' => $dir, 'PHP_VERSION' => '8.4'])->getOutput())->toContain('Reloading php8.4-fpm');
});

/** provision.sh --dry-run runs anywhere without root and writes nothing: it must print the plan and never a secret. */
function provision(array $args, array $env = []): Process
{
    $secrets = sys_get_temp_dir().'/hm-secrets-'.bin2hex(random_bytes(4));
    $p = new Process(['bash', base_path('deploy/provision.sh'), ...$args], base_path(), ['SECRETS_FILE' => $secrets, 'LOG_FILE' => $secrets.'.log', ...$env]);
    $p->run();
    expect(file_exists($secrets))->toBeFalse('a dry-run must not create the secrets file');

    return $p;
}

it('provision.sh --dry-run prints the whole plan, exits 0 and creates nothing', function () {
    $p = provision(['--dry-run']);
    $out = $p->getOutput();

    expect($p->getExitCode())->toBe(0, $out.$p->getErrorOutput());

    foreach (['== Secrets', '== Packages', 'update-alternatives --set php /usr/bin/php8.3', '== Swap (2G)', '== User deploy', '== PostgreSQL',
        'heymode_staging', 'heymode_production', '== Redis', '== PHP-FPM 8.3', '/var/www/heymode-staging', '/var/www/heymode-production',
        '== nginx staging site', '/etc/supervisor/conf.d/heymode.conf', '/etc/cron.d/heymode', 'dry-run: nothing was changed'] as $needle) {
        expect($out)->toContain($needle);
    }

    expect($out)->not->toMatch('/[0-9a-f]{48}/');
});

it('provision.sh honours PHP_VERSION, refuses bad input and refuses a real run without root', function () {
    $out84 = provision(['--dry-run'], ['PHP_VERSION' => '8.4'])->getOutput();
    expect($out84)->toContain('php8.4-fpm')->and($out84)->not->toContain('php8.3');

    expect(provision(['--dry-run'], ['PHP_VERSION' => '8.4; rm -rf /'])->getExitCode())->toBe(2)
        ->and(provision(['--dry-run'], ['STAGING_DOMAIN' => 'a b'])->getExitCode())->toBe(2)
        ->and(provision(['--bogus'])->getExitCode())->toBe(2);

    if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
        expect(provision([])->getExitCode())->toBe(1);
    }
});

it('rollback without a releases.log refuses', function () {
    $p = deploy(['rollback', 'staging', '--dry-run'], ['APP_DIR' => fakeApp('staging')]);

    expect($p->getExitCode())->toBe(1)->and($p->getOutput())->toContain('nothing to roll back to');
});
