<?php

declare(strict_types=1);

/**
 * Xdebug profiler (xdebug.mode=profile) runs of every mode × {cold, warm, hot} through the built-in web
 * server. Writes results/profiles/<env>-<state>/cachegrind.out.* and results/profiles/index.json.
 *
 * The profiler slows PHP down several times and distorts cheap calls the most: use the profiles for the
 * distribution of the time and the call counts, the wall-clock runs (run-wallclock.php) for absolute numbers.
 *
 * Usage: php bench/run-profiles.php
 */

require __DIR__ . '/lib.php';

$profileRoot = APP_ROOT . '/results/profiles';
removeTree($profileRoot);
@mkdir($profileRoot, 0777, true);

$xdebug = static fn(string $dir): array => [
    'zend_extension=xdebug',
    'xdebug.mode=profile',
    'xdebug.start_with_request=yes',
    'xdebug.output_dir=' . $dir,
    'xdebug.profiler_output_name=cachegrind.out.%u',
];
$newest = static function (string $dir): string {
    $files = glob($dir . '/cachegrind.out.*') ?: [];
    sort($files);

    return (string) end($files);
};

$index = [];
foreach (MODES as $env) {
    if ($env !== 'prebuilt') {
        clearMode($env);
        $dir = "$profileRoot/$env-cold";
        mkdir($dir);
        $server = startServer($env, $xdebug($dir));
        request();
        stopServer($server);
        $index["$env-cold"] = $newest($dir);
    }
    if ($env !== 'debug') {
        buildMode($env); // development builds nothing: the cold request above wove the cache
    }

    $dir = "$profileRoot/$env-warm";
    mkdir($dir);
    $server = startServer($env, $xdebug($dir));
    request();
    $index["$env-warm"] = $newest($dir);
    for ($i = 0; $i < 5; $i++) {
        request();
    }
    stopServer($server);
    mkdir("$profileRoot/$env-hot");
    $hot = $newest($dir);
    rename($hot, "$profileRoot/$env-hot/" . basename($hot));
    $index["$env-hot"] = "$profileRoot/$env-hot/" . basename($hot);
    fprintf(STDERR, "%s profiled\n", $env);
}

file_put_contents("$profileRoot/index.json", json_encode($index, JSON_PRETTY_PRINT) . "\n");
echo json_encode($index, JSON_PRETTY_PRINT), "\n";
