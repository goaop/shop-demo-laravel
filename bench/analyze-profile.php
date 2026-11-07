<?php

declare(strict_types=1);

/**
 * Minimal cachegrind (Xdebug 3 profiler) analyzer: no KCachegrind needed.
 *
 * Per profile: total time, time per bucket (framework subsystems, application, aspects, Composer, PHP internals - an
 * internal function counts towards the bucket of its caller), top functions by exclusive and inclusive time, call
 * counts of file-system functions.
 *
 * Usage: php bench/analyze-profile.php <cachegrind.out[.gz]> [--top=25] [--json]
 *        php bench/analyze-profile.php --index results/profiles/index.json   (comparison of all scenarios, markdown)
 */

/**
 * @return array{total: int, functions: array<string, array{self: int, incl: int, calls: int, internal: int}>, bucketTime: array<string, int>}
 */
function parseProfile(string $file): array
{
    $handle = str_ends_with($file, '.gz') ? gzopen($file, 'rb') : fopen($file, 'rb');
    if ($handle === false) {
        throw new RuntimeException("Can not open $file");
    }
    $read = str_ends_with($file, '.gz') ? gzgets(...) : fgets(...);

    $names     = ['fn' => [], 'fl' => []];
    $functions = [];
    $current   = null;
    $callee    = null;
    $inCall    = false;
    $total     = 0;
    $bucketTime = [];
    $internalCallers = [];

    $resolve = static function (string $kind, string $value) use (&$names): string {
        if (preg_match('/^\((\d+)\)(?: (.*))?$/', $value, $m)) {
            if (isset($m[2]) && $m[2] !== '') {
                $names[$kind][$m[1]] = $m[2];
            }

            return $names[$kind][$m[1]] ?? $value;
        }

        return $value;
    };

    while (($line = $read($handle)) !== false) {
        $line = rtrim($line, "\n");
        if ($line === '') {
            continue;
        }
        if (str_starts_with($line, 'fn=')) {
            $current = $resolve('fn', substr($line, 3));
            $functions[$current] ??= ['self' => 0, 'incl' => 0, 'calls' => 0, 'internal' => 0];
            $functions[$current]['calls']++;
            $inCall = false;
            continue;
        }
        if (str_starts_with($line, 'fl=') || str_starts_with($line, 'fi=') || str_starts_with($line, 'fe=')) {
            $resolve('fl', substr($line, 3));
            continue;
        }
        if (str_starts_with($line, 'cfl=') || str_starts_with($line, 'cfi=')) {
            $resolve('fl', substr($line, 4));
            continue;
        }
        if (str_starts_with($line, 'cfn=')) {
            $callee = $resolve('fn', substr($line, 4));
            continue;
        }
        if (str_starts_with($line, 'calls=')) {
            $inCall = true;
            continue;
        }
        if (str_starts_with($line, 'summary:')) {
            $total = (int) explode(' ', trim(substr($line, 8)))[0];
            continue;
        }
        if ($current !== null && preg_match('/^\d+ (\d+)/', $line, $m)) {
            $cost = (int) $m[1];
            if ($inCall) {
                // inclusive cost of a call made by $current
                if ($callee !== null && str_starts_with($callee, 'php::')) {
                    // which callers an internal function works for: its self time is split by these shares later
                    $internalCallers[$callee][$current] = ($internalCallers[$callee][$current] ?? 0) + $cost;
                }
                $functions[$current]['incl'] += $cost;
                $inCall = false;
            } else {
                $functions[$current]['self'] += $cost;
                $functions[$current]['incl'] += $cost;
            }
        }
    }
    fclose($handle);

    // The self time of an internal function counts towards the buckets of its callers, split by their share of its
    // inclusive time (callbacks like ReflectionMethod::invokeArgs() run user code: only their own time is split)
    foreach ($internalCallers as $internal => $callers) {
        $sum = array_sum($callers);
        foreach ($callers as $caller => $cost) {
            $share = $sum > 0 ? $cost / $sum : 0;
            $functions[$caller]['internal'] += (int) round($functions[$internal]['self'] * $share);
        }
    }
    foreach ($functions as $name => $data) {
        if (str_starts_with($name, 'php::')) {
            continue; // counted towards the caller
        }
        $bucket              = bucketOf($name);
        $bucketTime[$bucket] = ($bucketTime[$bucket] ?? 0) + $data['self'] + $data['internal'];
    }
    if ($total === 0) {
        $total = array_sum($bucketTime);
    }

    return ['total' => $total, 'functions' => $functions, 'bucketTime' => $bucketTime];
}

function bucketOf(string $function): string
{
    // include::/path, require_once::/path - the top-level code of a file (class declarations, compilation)
    if (preg_match('/^(include|include_once|require|require_once)::(.*)$/', $function, $m)) {
        $path = $m[2];

        return match (true) {
            str_contains($path, '/framework/aop')         => 'include: AOP cache files (woven, proxies, maps)',
            str_contains($path, '/bootstrap/cache/')      => 'include: Laravel bootstrap caches',
            str_contains($path, '/vendor/composer/')      => 'include: Composer',
            preg_match('#/framework/src/#', $path) === 1  => 'include: framework classes',
            str_contains($path, '/vendor/')               => 'include: other vendor',
            str_contains($path, 'php://filter')           => 'include: through the stream filter',
            default                                       => 'include: application files',
        };
    }
    $class = str_contains($function, '->') ? explode('->', $function)[0] : explode('::', $function)[0];

    return match (true) {
        $function === '{main}'                                                    => 'front controller {main}',
        str_starts_with($class, 'App\\Aspects\\')                                => 'aspects (advice bodies)',
        str_starts_with($class, 'App\\'), str_starts_with($function, '{closure:' . dirname(__DIR__) . '/src') => 'application',
        str_starts_with($class, 'Lib\\')                                          => 'library (lib/, not woven)',
        str_starts_with($class, 'Composer\\')                                     => 'Composer autoloader',
        str_starts_with($class, 'Go\\Laravel\\')                                 => 'goaop-laravel-bridge',
        str_starts_with($class, 'Illuminate\\'), str_starts_with($class, 'Symfony\\'), str_starts_with($class, 'Laravel\\') => 'Laravel (container, HTTP kernel, middleware, routing)',
        str_starts_with($class, 'PhpParser\\'), str_starts_with($class, 'Go\\ParserReflection\\') => 'weaving: parser + parser-reflection',
        str_starts_with($class, 'Dissect\\'), str_starts_with($class, 'Go\\Aop\\Pointcut\\') => 'weaving: pointcut grammar/matching',
        str_starts_with($class, 'Go\\Instrument\\Transformer\\'), str_starts_with($class, 'Go\\Proxy\\') => 'weaving: transformers + proxy generators',
        str_starts_with($class, 'Go\\Core\\Cache\\'), str_starts_with($class, 'Go\\Core\\AspectLoader'), str_starts_with($class, 'Go\\Core\\AttributeAspectLoader'), str_starts_with($class, 'Go\\Core\\AdviceMatcher'), str_starts_with($class, 'Go\\Core\\AdvisorIndex'), str_starts_with($class, 'Go\\Core\\IntroductionAspect'), str_starts_with($class, 'Go\\Core\\AbstractAspectLoader') => 'weaving: aspect loading + advice matching',
        str_starts_with($class, 'Go\\Instrument\\ClassLoading\\SourceTransformingLoader') => 'framework: stream filter (cache hits + misses)',
        str_starts_with($class, 'Go\\Instrument\\'), str_starts_with($class, 'Symfony\\Component\\Finder') => 'framework: class loading + cache metadata',
        str_starts_with($class, 'Go\\Core\\AspectKernel'), str_starts_with($class, 'Go\\Core\\Container'), str_starts_with($class, 'Go\\Core\\FrameworkServices'), str_starts_with($class, 'Go\\Core\\NativeLazyProxy'), str_starts_with($class, 'Go\\Core\\LazyService') => 'framework: kernel + container',
        str_starts_with($class, 'Go\\Aop\\'), str_starts_with($class, 'Go\\Core\\The'), str_starts_with($class, 'Go\\Lang\\') => 'framework: runtime interception (joinpoints, interceptors)',
        str_starts_with($class, 'Go\\')                                           => 'framework: other',
        str_starts_with($function, '{closure')                                    => 'closures (other)',
        default                                                                   => 'other',
    };
}

const FS_FUNCTIONS = [
    'php::file_exists', 'php::is_file', 'php::is_dir', 'php::is_readable', 'php::is_writable', 'php::filemtime',
    'php::filesize', 'php::stat', 'php::realpath', 'php::clearstatcache', 'php::file_get_contents',
    'php::file_put_contents', 'php::rename', 'php::fopen', 'php::opcache_invalidate', 'php::stream_filter_register',
    'php::stream_get_contents', 'php::glob', 'php::scandir', 'php::mkdir',
];

/** @param array{total: int, functions: array<string, array{self: int, incl: int, calls: int, internal: int}>, bucketTime: array<string, int>} $profile */
function report(string $file, array $profile, int $top): void
{
    $ms = static fn(int $cost): float => $cost / 1e5; // Time_(10ns)
    printf("# %s\n\nTotal (profiled): %.2f ms\n\n## Time by bucket (internal functions counted to their caller)\n\n", $file, $ms($profile['total']));
    arsort($profile['bucketTime']);
    echo "| bucket | ms | % |\n|---|---:|---:|\n";
    foreach ($profile['bucketTime'] as $bucket => $cost) {
        printf("| %s | %.2f | %.1f |\n", $bucket, $ms($cost), 100 * $cost / max(1, $profile['total']));
    }
    foreach (['self' => 'exclusive', 'incl' => 'inclusive'] as $key => $label) {
        $functions = $profile['functions'];
        uasort($functions, static fn($a, $b) => $b[$key] <=> $a[$key]);
        printf("\n## Top %d by %s time\n\n| function | calls | self ms | incl ms |\n|---|---:|---:|---:|\n", $top, $label);
        foreach (array_slice($functions, 0, $top, true) as $name => $data) {
            printf("| `%s` | %d | %.2f | %.2f |\n", $name, $data['calls'], $ms($data['self']), $ms($data['incl']));
        }
    }
    echo "\n## File-system functions\n\n| function | calls | ms |\n|---|---:|---:|\n";
    foreach (FS_FUNCTIONS as $name) {
        if (isset($profile['functions'][$name])) {
            printf("| `%s` | %d | %.2f |\n", $name, $profile['functions'][$name]['calls'], $ms($profile['functions'][$name]['self']));
        }
    }
    echo "\n";
}

$args = array_slice($argv, 1);
$top  = 25;
foreach ($args as $i => $arg) {
    if (str_starts_with($arg, '--top=')) {
        $top = (int) substr($arg, 6);
        unset($args[$i]);
    }
}
$args = array_values($args);

if (($args[0] ?? '') === '--index') {
    $index    = json_decode((string) file_get_contents($args[1]), true);
    $profiles = [];
    foreach ($index as $scenario => $file) {
        $profiles[$scenario] = parseProfile($file);
    }
    $buckets = [];
    foreach ($profiles as $profile) {
        $buckets += $profile['bucketTime'];
    }
    $buckets = array_keys($buckets);
    sort($buckets);
    echo "| bucket (ms, profiled) | " . implode(' | ', array_keys($profiles)) . " |\n";
    echo '|---|' . str_repeat('---:|', count($profiles)) . "\n";
    printf("| **total** | %s |\n", implode(' | ', array_map(static fn($p) => sprintf('**%.1f**', $p['total'] / 1e5), $profiles)));
    foreach ($buckets as $bucket) {
        printf("| %s | %s |\n", $bucket, implode(' | ', array_map(static fn($p) => sprintf('%.2f', ($p['bucketTime'][$bucket] ?? 0) / 1e5), $profiles)));
    }
    echo "\n| calls | " . implode(' | ', array_keys($profiles)) . " |\n|---|" . str_repeat('---:|', count($profiles)) . "\n";
    foreach (FS_FUNCTIONS as $name) {
        $row = array_map(static fn($p) => (string) ($p['functions'][$name]['calls'] ?? 0), $profiles);
        if (array_filter($row, static fn($c) => $c !== '0') !== []) {
            printf("| `%s` | %s |\n", $name, implode(' | ', $row));
        }
    }
    $includes = array_map(static fn($p) => (string) count(array_filter(array_keys($p['functions']), static fn($n) => preg_match('/^(include|require)(_once)?::/', $n) === 1)), $profiles);
    printf("| include/require (files) | %s |\n", implode(' | ', $includes));
    exit(0);
}

report($args[0], parseProfile($args[0]), $top);
