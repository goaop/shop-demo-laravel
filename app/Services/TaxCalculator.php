<?php

declare(strict_types=1);

namespace App\Services;

use App\Attributes\Cacheable;
use App\Domain\Money;

class TaxCalculator
{
    private const array RATES = ['DE' => 19, 'FR' => 20, 'NL' => 21, 'PL' => 23, 'ES' => 21];

    #[Cacheable]
    public function rateFor(string $country, string $category): int
    {
        $rate = self::RATES[$country] ?? 20;

        return $category === 'books' ? intdiv($rate, 3) : $rate;
    }

    public function taxFor(Money $net, string $country, string $category): Money
    {
        return $net->percent($this->rateFor($country, $category));
    }
}
