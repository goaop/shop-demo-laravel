<?php

declare(strict_types=1);

namespace App\Domain;

final class OutOfStock extends \RuntimeException
{
    public static function forProduct(Product $product, int $requested): self
    {
        return new self(sprintf('Product %s has %d items, %d requested', $product->sku, $product->stock, $requested));
    }
}
