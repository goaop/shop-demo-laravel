<?php

declare(strict_types=1);

namespace App\Domain;

enum OrderStatus: string
{
    case New = 'new';
    case Reserved = 'reserved';
    case Paid = 'paid';
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return $this === self::Paid || $this === self::Failed;
    }
}
