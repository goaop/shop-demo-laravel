<?php

declare(strict_types=1);

namespace App\Services;

use App\Attributes\Transactional;
use App\Domain\OutOfStock;
use App\Domain\Product;
use App\Repositories\ProductRepository;

class InventoryService
{
    public function __construct(
        private readonly ProductRepository $products,
    ) {}

    #[Transactional]
    public function reserve(Product $product, int $quantity): void
    {
        if ($product->stock < $quantity) {
            throw OutOfStock::forProduct($product, $quantity);
        }
        $product->stock -= $quantity;
        $this->products->save($product);
    }
}
