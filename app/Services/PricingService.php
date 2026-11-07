<?php

declare(strict_types=1);

namespace App\Services;

use App\Attributes\Loggable;
use App\Domain\Customer;
use App\Domain\Money;
use App\Domain\Product;

class PricingService
{
    public function __construct(
        private readonly DiscountService $discounts,
    ) {}

    #[Loggable]
    public function priceFor(Product $product, Customer $customer, int $quantity): Money
    {
        $price    = $product->price;
        $discount = $this->discounts->discountPercent($customer, $quantity);

        return $discount > 0 ? $price->multiply((100 - $discount) / 100) : $price;
    }
}
