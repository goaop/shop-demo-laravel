<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Money;
use App\Domain\Product;

class ProductRepository
{
    private const array CATEGORIES = ['books', 'games', 'music', 'garden', 'kitchen', 'tools'];

    /** @var array<int, Product> */
    private array $products = [];

    public function __construct(int $count = 200)
    {
        for ($id = 1; $id <= $count; $id++) {
            $this->products[$id] = new Product(
                $id,
                sprintf('SKU-%05d', $id),
                'Product #' . $id,
                self::CATEGORIES[$id % count(self::CATEGORIES)],
                new Money(500 + ($id * 137) % 9500),
                1000,
            );
        }
    }

    public function find(int $id): ?Product
    {
        return $this->products[$id] ?? null;
    }

    /** @return list<Product> */
    public function findByCategory(string $category): array
    {
        $result = [];
        foreach ($this->products as $product) {
            if ($product->category === $category) {
                $result[] = $product;
            }
        }

        return $result;
    }

    public function count(): int
    {
        return count($this->products);
    }

    public function save(Product $product): void
    {
        $this->products[$product->id] = $product;
    }
}
