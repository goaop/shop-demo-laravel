<?php

declare(strict_types=1);

namespace App\Support;

/**
 * When the application (and with it the aspect kernel of GoAopServiceProvider) finished booting, for the timings
 * of the benchmark responses. Set by AppServiceProvider
 */
final class RequestClock
{
    public static ?float $bootedAt = null;
}
