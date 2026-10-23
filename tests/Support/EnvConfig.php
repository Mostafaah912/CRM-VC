<?php

declare(strict_types=1);

namespace Tests\Support;

/** Evaluate a config file as if the process had the given env vars (config() itself is built once at boot, so it cannot show env-driven defaults). */
final class EnvConfig
{
    /**
     * @param  array<string, string|null>  $env  null = unset
     * @return array<string, mixed>
     */
    public static function load(string $configFile, array $env): array
    {
        $saved = [];

        foreach ($env as $key => $value) {
            $saved[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];

            if ($value === null) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $_SERVER[$key] = $value;
                putenv("{$key}={$value}");
            }
        }

        try {
            return require config_path($configFile.'.php');
        } finally {
            foreach ($saved as $key => [$e, $s, $g]) {
                $e === null ? ($_ENV[$key] = null) : ($_ENV[$key] = $e);
                $e === null && ($_ENV = array_filter($_ENV, fn ($k) => $k !== $key, ARRAY_FILTER_USE_KEY));
                $s === null ? ($_SERVER = array_filter($_SERVER, fn ($k) => $k !== $key, ARRAY_FILTER_USE_KEY)) : ($_SERVER[$key] = $s);
                $g === false ? putenv($key) : putenv("{$key}={$g}");
            }
        }
    }
}
