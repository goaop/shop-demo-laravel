<?php

declare(strict_types=1);

namespace App\Domain;

final class Product
{
    public function __construct(
        public readonly int $id,
        public readonly string $sku,
        public readonly string $name,
        public readonly string $category,
        public readonly Money $price,
        public int $stock,
    ) {}
}
