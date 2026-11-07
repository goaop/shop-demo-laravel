<?php

declare(strict_types=1);

namespace App\Domain;

final readonly class Money
{
    public function __construct(
        public int $cents,
        public string $currency = 'EUR',
    ) {}

    public function add(Money $other): self
    {
        return new self($this->cents + $other->cents, $this->currency);
    }

    public function multiply(int|float $factor): self
    {
        return new self((int) round($this->cents * $factor), $this->currency);
    }

    public function percent(int $percent): self
    {
        return new self(intdiv($this->cents * $percent, 100), $this->currency);
    }
}
