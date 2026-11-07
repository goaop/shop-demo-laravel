<?php

declare(strict_types=1);

/**
 * Wall-clock matrix: mode × {cold, warm, hot} through the built-in web server (one worker, OPcache shared memory kept
 * between requests), without Xdebug. Writes results/wallclock.json and prints a table.
 *
 *  cold - no AOP cache and no Laravel caches, fresh server: the first request weaves (debug, prod)
 *  warm - after the deployment step of the mode (optimize, aop:warmup), fresh server (empty OPcache)
 *  hot  - both caches filled: the steady state of a production worker
 *
 * Usage: php bench/run-wallclock.php [samples=7] [hotRequests=50]
 */

require __DIR__ . '/lib.php';

$samples     = (int) ($argv[1] ?? 7);
$hotRequests = (int) ($argv[2] ?? 50);

@mkdir(APP_ROOT . '/results', 0777, true);
$results = ['php' => PHP_VERSION, 'samples' => $samples, 'hot_requests' => $hotRequests, 'build_ms' => [], 'modes' => []];

foreach (MODES as $mode) {
    $data  = ['cold' => [], 'warm' => [], 'hot' => [], 'hot:validate_timestamps=0' => []];
    $build = [];
    for ($i = 0; $i < $samples; $i++) {
        if ($mode !== 'prebuilt') {
            clearMode($mode);
            $server         = startServer($mode);
            $data['cold'][] = request();
            stopServer($server);
        }
        if ($mode !== 'debug') {
            $build[] = buildMode($mode);
        }

        $server         = startServer($mode);
        $data['warm'][] = request();
        for ($r = 0; $r < $hotRequests; $r++) {
            $data['hot'][] = request();
        }
        stopServer($server);
    }
    $server = startServer($mode, ['opcache.validate_timestamps=0']);
    request();
    for ($r = 0; $r < $hotRequests * $samples; $r++) {
        $data['hot:validate_timestamps=0'][] = request();
    }
    stopServer($server);
    if ($build !== []) {
        $results['build_ms'][$mode] = median($build);
    }

    foreach ($data as $state => $runs) {
        if ($runs === []) {
            continue;
        }
        $app = array_column($runs, 'app');
        $results['modes'][$mode][$state] = [
            'n'              => count($runs),
            'client_ms_p50'  => median(array_column($runs, 'client_ms')),
            'client_ms_p90'  => percentile(array_column($runs, 'client_ms'), 0.9),
            'boot_ms_p50'    => median(array_map(static fn($a) => $a['timing']['boot_ms'], $app)),
            'handle_ms_p50'  => median(array_map(static fn($a) => $a['timing']['handle_ms'], $app)),
            'total_ms_p50'   => median(array_map(static fn($a) => $a['timing']['total_ms'], $app)),
            'memory_peak_kb' => max(array_map(static fn($a) => $a['memory_peak_kb'], $app)),
            'included_files' => max(array_map(static fn($a) => $a['included_files'], $app)),
            'audit_records'  => min(array_map(static fn($a) => $a['response']['stats']['audit_records'], $app)),
        ];
    }
    fprintf(STDERR, "%s done\n", $mode);
}

file_put_contents(APP_ROOT . '/results/wallclock.json', json_encode($results, JSON_PRETTY_PRINT) . "\n");

printf("PHP %s, %d samples, %d hot requests per sample, JIT off, Xdebug off\n\n", PHP_VERSION, $samples, $hotRequests);
echo "deployment step from scratch (optimize + aop:warmup): ";
foreach ($results['build_ms'] as $mode => $ms) {
    printf('%s %.0f ms  ', $mode, $ms);
}
echo "\n\n";
printf("%-9s %-26s %10s %10s %10s %10s %10s %8s %6s %7s\n", 'mode', 'state', 'client p50', 'client p90', 'boot p50', 'handle p50', 'total p50', 'mem KB', 'files', 'audit');
foreach ($results['modes'] as $mode => $states) {
    foreach ($states as $state => $row) {
        printf(
            "%-9s %-26s %10.2f %10.2f %10.2f %10.2f %10.2f %8d %6d %7d\n",
            $mode, $state, $row['client_ms_p50'], $row['client_ms_p90'], $row['boot_ms_p50'], $row['handle_ms_p50'],
            $row['total_ms_p50'], $row['memory_peak_kb'], $row['included_files'], $row['audit_records'],
        );
    }
}
