<?php

declare(strict_types=1);

namespace App\Infrastructure;

/**
 * In-memory counters and timers of the request
 */
final class Metrics
{
    /** @var array<string, array{int, int}> */
    private array $timers = [];

    /** @var array<string, int> */
    private array $counters = [];

    public function record(string $name, int $nanoseconds): void
    {
        $timer               = $this->timers[$name] ?? [0, 0];
        $this->timers[$name] = [$timer[0] + 1, $timer[1] + $nanoseconds];
    }

    public function increment(string $counter): void
    {
        $this->counters[$counter] = ($this->counters[$counter] ?? 0) + 1;
    }

    public function counter(string $counter): int
    {
        return $this->counters[$counter] ?? 0;
    }

    public function timedCalls(): int
    {
        return array_sum(array_column($this->timers, 0));
    }
}
