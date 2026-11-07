<?php

declare(strict_types=1);

/**
 * "Pod start": the first requests of a freshly started server (empty OPcache shared memory) after the deployment step,
 * with the start-up helpers a container image can ship:
 *  - opcache.file_cache primed at build time by the bridge's aop:warmup-opcache
 *  - opcache.preload of the _preload.php written by aop:warmup --preload
 *
 * Usage: php bench/run-startup.php [samples=7]
 */

require __DIR__ . '/lib.php';

$samples   = (int) ($argv[1] ?? 7);
$fileCache = APP_ROOT . '/storage/framework/opcache-file';
$preload   = [
    'opcache.preload=' . modeEnv('prebuilt')['GOAOP_CACHE_DIR'] . '/_preload.php',
    'opcache.preload_user=' . (get_current_user() ?: 'root'),
];
$fileIni   = ['opcache.file_cache=' . $fileCache];
$variants  = [
    'prebuilt'                                  => ['prebuilt', [], null],
    'prebuilt + file cache (aop:warmup-opcache)' => ['prebuilt', $fileIni, 'command'],
    'prebuilt + file cache (primed by a request)' => ['prebuilt', $fileIni, 'request'],
    'prebuilt + preload'                        => ['prebuilt', $preload, null],
    'prebuilt + preload + file cache (command)' => ['prebuilt', [...$preload, ...$fileIni], 'command'],
    'baseline (no AOP)'                         => ['baseline', [], null],
    'baseline + file cache (request)'           => ['baseline', $fileIni, 'request'],
];

$results = [];
foreach (['prebuilt', 'baseline'] as $mode) {
    buildMode($mode);
}
foreach ($variants as $name => [$mode, $ini, $prime]) {
    removeTree($fileCache);
    mkdir($fileCache, 0777, true);
    if ($prime === 'command') {
        // build step of an image: the bridge's primer, in a CLI process that writes the file cache; the entry points
        // and Laravel's bootstrap caches are passed with --entry
        $entries = ['--entry=' . APP_ROOT . '/public/index.php', '--entry=' . APP_ROOT . '/artisan'];
        foreach ([...glob(APP_ROOT . '/bootstrap/*.php'), ...glob(APP_ROOT . '/bootstrap/cache/*-prebuilt.php'), ...glob(APP_ROOT . '/bootstrap/cache/packages.php'), ...glob(APP_ROOT . '/routes/*.php'), ...glob(APP_ROOT . '/config/*.php')] as $file) {
            $entries[] = '--entry=' . $file;
        }
        artisan($mode, ['aop:warmup-opcache', '--file-cache=' . $fileCache, ...$entries],
            ['opcache.file_cache=' . $fileCache, 'opcache.file_cache_only=1', 'opcache.file_cache_read_only=0']);
    } elseif ($prime === 'request') {
        $server = startServer($mode, $ini);
        request();
        stopServer($server);
    }
    $first = [];
    $hot   = [];
    for ($s = 0; $s < $samples; $s++) {
        $server  = startServer($mode, $ini);
        $first[] = request();
        for ($i = 0; $i < 40; $i++) {
            $hot[] = request()['app']['timing']['total_ms'];
        }
        stopServer($server);
    }
    $row = [
        'first_total_ms' => median(array_map(static fn($r) => $r['app']['timing']['total_ms'], $first)),
        'hot_total_ms'   => median($hot),
        'included_files' => $first[0]['app']['included_files'],
        'audit_records'  => $first[0]['app']['response']['stats']['audit_records'],
    ];
    $results[$name] = $row;
    printf("%-46s first request %7.2f ms   hot %5.2f ms   files %4d   audit %d\n", $name, $row['first_total_ms'], $row['hot_total_ms'], $row['included_files'], $row['audit_records']);
}
@mkdir(APP_ROOT . '/results', 0777, true);
file_put_contents(APP_ROOT . '/results/startup.json', json_encode($results, JSON_PRETTY_PRINT) . "\n");
