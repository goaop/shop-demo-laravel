<?php

declare(strict_types=1);

namespace App\Infrastructure;

final class TransactionManager
{
    private int $level = 0;

    public int $commits = 0;

    public int $rollbacks = 0;

    public function begin(): void
    {
        ++$this->level;
    }

    public function commit(): void
    {
        --$this->level;
        if ($this->level === 0) {
            ++$this->commits;
        }
    }

    public function rollback(): void
    {
        --$this->level;
        ++$this->rollbacks;
    }
}
