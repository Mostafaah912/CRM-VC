<?php

declare(strict_types=1);

namespace Tests\Arch;

/** Comment-aware source scanner used by the architecture tests. */
final class Scanner
{
    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @param  list<string>  $dirs  paths relative to the project root
     * @return list<string> absolute paths of *.php files
     */
    public static function phpFiles(array $dirs): array
    {
        return self::files($dirs, 'php');
    }

    /**
     * @param  list<string>  $dirs
     * @param  list<string>  $excludeDirs  path fragments to skip
     * @return list<string>
     */
    public static function files(array $dirs, string $extensions, array $excludeDirs = []): array
    {
        $found = [];
        $extensions = explode(',', $extensions);

        foreach ($dirs as $dir) {
            $path = self::root().'/'.$dir;

            if (! is_dir($path)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                $real = $file->getPathname();

                foreach ($excludeDirs as $fragment) {
                    if (str_contains($real, $fragment)) {
                        continue 2;
                    }
                }

                if (in_array($file->getExtension(), $extensions, true)) {
                    $found[] = $real;
                }
            }
        }

        sort($found);

        return $found;
    }

    /** PHP source with comments blanked out (line numbers preserved). */
    public static function phpCode(string $file): string
    {
        $source = (string) file_get_contents($file);
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                $code .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
                    ? preg_replace('/[^\n]/', ' ', $token[1])
                    : $token[1];
            } else {
                $code .= $token;
            }
        }

        return $code;
    }

    /**
     * @param  list<string>  $files
     * @param  list<string>  $patterns  PCRE patterns matched per line
     * @return list<string> "relative/path.php:LINE: matched text"
     */
    public static function violations(array $files, array $patterns, bool $stripPhpComments = true): array
    {
        $violations = [];

        foreach ($files as $file) {
            $content = $stripPhpComments && str_ends_with($file, '.php')
                ? self::phpCode($file)
                : (string) file_get_contents($file);

            foreach (explode("\n", $content) as $index => $line) {
                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $line, $match) === 1) {
                        $violations[] = self::relative($file).':'.($index + 1).': '.trim($match[0]);
                    }
                }
            }
        }

        return $violations;
    }

    public static function relative(string $file): string
    {
        return ltrim(str_replace(self::root(), '', $file), '/');
    }

    /** @return list<string> */
    public static function modules(): array
    {
        $modules = [];

        foreach (glob(self::root().'/app/Modules/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $modules[] = basename($dir);
        }

        return $modules;
    }

    /** @return list<string> absolute paths, or empty when git is unavailable */
    public static function trackedFiles(): array
    {
        $output = [];
        exec('git -C '.escapeshellarg(self::root()).' ls-files 2>/dev/null', $output, $status);

        return $status === 0 ? array_map(fn (string $f) => self::root().'/'.$f, $output) : [];
    }
}
