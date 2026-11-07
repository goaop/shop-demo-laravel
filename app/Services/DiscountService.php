<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Customer;

class DiscountService
{
    public function discountPercent(Customer $customer, int $quantity): int
    {
        return $customer->tier->value + ($quantity >= 5 ? 3 : 0);
    }
}
