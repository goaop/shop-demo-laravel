<?php

declare(strict_types=1);

namespace App\Infrastructure;

/**
 * In-memory audit trail of the request: keeps the advice bodies cheap, so the benchmark measures the framework,
 * not I/O
 */
final class AuditLog
{
    /** @var list<string> */
    private array $records = [];

    public function record(string $channel, string $message): void
    {
        $this->records[] = $channel . ': ' . $message;
    }

    public function count(): int
    {
        return \count($this->records);
    }
}
