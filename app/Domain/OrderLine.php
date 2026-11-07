<?php

declare(strict_types=1);

namespace App\Domain;

final readonly class OrderLine
{
    public function __construct(
        public Product $product,
        public int $quantity,
        public Money $unitPrice,
        public Money $tax,
    ) {}

    public function total(): Money
    {
        return $this->unitPrice->multiply($this->quantity)->add($this->tax);
    }
}
