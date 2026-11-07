<?php

declare(strict_types=1);

/**
 * Helpers of the benchmark scripts: built-in web server per mode, requests, cache builds, statistics.
 *
 * A mode is a set of real environment variables on top of .env (see modeEnv()). Every mode has its own AOP cache
 * directory and its own Laravel bootstrap caches (APP_CONFIG_CACHE, ...), so the modes do not overwrite each other.
 */

const APP_ROOT = __DIR__ . '/..';
const PORT     = 8090;
const MODES    = ['baseline', 'debug', 'prod', 'prebuilt'];

/**
 * @return array<string, string>
 */
function modeEnv(string $mode): array
{
    $root = realpath(APP_ROOT);
    $env  = [
        'GOAOP_CACHE_DIR'    => "$root/storage/framework/aop-$mode",
        'APP_CONFIG_CACHE'   => "$root/bootstrap/cache/config-$mode.php",
        'APP_SERVICES_CACHE' => "$root/bootstrap/cache/services-$mode.php",
        'APP_ROUTES_CACHE'   => "$root/bootstrap/cache/routes-$mode.php",
        'APP_EVENTS_CACHE'   => "$root/bootstrap/cache/events-$mode.php",
        'LOG_CHANNEL'        => 'stderr',
    ];

    return $env + match ($mode) {
        // the plain application: GoAopServiceProvider is not registered (bootstrap/providers.php)
        'baseline' => ['APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'GOAOP_ENABLED' => 'false'],
        // development: Go! AOP debug mode follows APP_DEBUG, no Laravel caches
        'debug'    => ['APP_ENV' => 'local', 'APP_DEBUG' => 'true'],
        // production: optimize + aop:warmup at deploy time, woven classes served from the cache
        'prod'     => ['APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'GOAOP_FEATURES' => '0'],
        // production with PREBUILT_CACHE (Features::PREBUILT_CACHE = 64): the cache is trusted completely
        'prebuilt' => ['APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'GOAOP_FEATURES' => '64'],
    };
}

/**
 * php.ini overrides of every run: OPcache on, JIT off (PHP 8.5.11/8.5.12 tracing JIT bug), file_update_protection
 * off (files written a moment ago are cached at once; the AOP cache is written atomically), no Xdebug
 *
 * @return list<string>
 */
function baseIni(): array
{
    return [
        'opcache.enable=1',
        'opcache.enable_cli=1',
        'opcache.jit=disable',
        'opcache.file_update_protection=0',
        'opcache.memory_consumption=128',
        'opcache.max_accelerated_files=20000',
        'opcache.interned_strings_buffer=16',
        'memory_limit=512M',
    ];
}

/**
 * @param list<string> $ini
 * @return resource
 */
function startServer(string $mode, array $ini = [])
{
    $command = [PHP_BINARY];
    foreach (array_merge(baseIni(), $ini) as $setting) {
        $command[] = '-d';
        $command[] = $setting;
    }
    array_push($command, '-S', '127.0.0.1:' . PORT, '-t', APP_ROOT . '/public', APP_ROOT . '/public/index.php');

    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
        APP_ROOT,
        modeEnv($mode) + getenv(),
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Can not start the web server');
    }
    for ($i = 0; $i < 300; $i++) {
        $socket = @fsockopen('127.0.0.1', PORT, $errno, $errstr, 0.05);
        if ($socket !== false) {
            fclose($socket);

            return $process;
        }
        usleep(10_000);
    }
    throw new RuntimeException('The web server did not start');
}

/** @param resource $process */
function stopServer($process): void
{
    proc_terminate($process);
    proc_close($process);
    for ($i = 0; $i < 200; $i++) {
        $socket = @fsockopen('127.0.0.1', PORT, $errno, $errstr, 0.02);
        if ($socket === false) {
            return;
        }
        fclose($socket);
        usleep(10_000);
    }
}

/**
 * @return array{client_ms: float, app: array<string, mixed>}
 */
function request(string $path = '/'): array
{
    $start = hrtime(true);
    $body  = file_get_contents('http://127.0.0.1:' . PORT . $path);
    $time  = (hrtime(true) - $start) / 1e6;
    $data  = is_string($body) ? json_decode($body, true) : null;
    if (!is_array($data)) {
        throw new RuntimeException('Bad response: ' . substr((string) $body, 0, 2000));
    }

    return ['client_ms' => $time, 'app' => $data];
}

function removeTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        is_dir($path) && !is_link($path) ? removeTree($path) : unlink($path);
    }
    rmdir($dir);
}

/**
 * Removes the AOP cache and the Laravel bootstrap caches of a mode
 */
function clearMode(string $mode): void
{
    $env = modeEnv($mode);
    removeTree($env['GOAOP_CACHE_DIR']);
    foreach (['APP_CONFIG_CACHE', 'APP_SERVICES_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE'] as $file) {
        @unlink($env[$file]);
    }
}

/**
 * @param list<string> $arguments
 * @param list<string> $ini
 */
function artisan(string $mode, array $arguments, array $ini = []): string
{
    $command = '';
    foreach (modeEnv($mode) as $name => $value) {
        $command .= $name . '=' . escapeshellarg($value) . ' ';
    }
    $command .= escapeshellarg(PHP_BINARY);
    foreach (array_merge(['opcache.enable_cli=1', 'opcache.jit=disable', 'opcache.file_update_protection=0', 'memory_limit=512M'], $ini) as $setting) {
        $command .= ' -d ' . escapeshellarg($setting);
    }
    $command .= ' ' . escapeshellarg(APP_ROOT . '/artisan') . ' ' . implode(' ', array_map(escapeshellarg(...), $arguments)) . ' 2>&1';
    exec($command, $output, $code);
    if ($code !== 0) {
        throw new RuntimeException("artisan failed:\n" . implode("\n", $output));
    }

    return implode("\n", $output);
}

/**
 * The deployment step of a mode, from scratch: the AOP cache (aop:warmup, with the preload script for prebuilt), then
 * Laravel's caches (optimize). Development (debug) builds nothing. Returns the wall time in ms.
 *
 * aop:warmup comes first: with PREBUILT_CACHE every other artisan command fails while the AOP cache misses a class
 * (the bridge loads the configured aspect classes when the kernel starts), only aop:warmup may build it
 */
function buildMode(string $mode): float
{
    clearMode($mode);
    $start = hrtime(true);
    if ($mode === 'prod' || $mode === 'prebuilt') {
        artisan($mode, $mode === 'prebuilt' ? ['aop:warmup', '--preload'] : ['aop:warmup']);
    }
    if ($mode !== 'debug') {
        artisan($mode, ['optimize']);
    }

    return (hrtime(true) - $start) / 1e6;
}

/** @param list<float> $values */
function median(array $values): float
{
    sort($values);
    $count = count($values);
    if ($count === 0) {
        return NAN;
    }
    $middle = intdiv($count, 2);

    return $count % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
}

/** @param list<float> $values */
function percentile(array $values, float $p): float
{
    sort($values);

    return $values[(int) min(count($values) - 1, floor($p * count($values)))];
}
