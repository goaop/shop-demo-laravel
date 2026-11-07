<?php

declare(strict_types=1);

/**
 * Smoke test of every mode (CI): deployment step, a fresh server, two requests, every aspect counter checked
 *
 * Usage: php bench/smoke.php
 */

require __DIR__ . '/lib.php';

$failed = false;
foreach (MODES as $mode) {
    buildMode($mode);
    $server = startServer($mode);
    try {
        foreach (['first', 'second'] as $run) {
            $app      = request()['app'];
            $stats    = $app['response']['stats'];
            $expected = $mode === 'baseline' ? 0 : 800;
            $ok       = $app['response']['checkout']['placed'] === 47 && $stats['audit_records'] === $expected
                && ($mode === 'baseline') === ($app['aop'] === null);
            $failed   = $failed || !$ok;
            printf("%-8s %-6s %-4s %6.1f ms %4d files %s\n", $mode, $run, $ok ? 'OK' : 'FAIL', $app['timing']['total_ms'], $app['included_files'], json_encode($stats));
        }
    } finally {
        stopServer($server);
    }
}
exit($failed ? 1 : 0);
