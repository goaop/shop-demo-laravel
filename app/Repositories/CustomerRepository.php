<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Customer;
use App\Domain\CustomerTier;

class CustomerRepository
{
    private const array COUNTRIES = ['DE', 'FR', 'NL', 'PL', 'ES'];

    /** @var array<int, Customer> */
    private array $customers = [];

    public function __construct(int $count = 50)
    {
        $tiers = CustomerTier::cases();
        for ($id = 1; $id <= $count; $id++) {
            $this->customers[$id] = new Customer(
                $id,
                'Customer ' . $id,
                "customer$id@example.com",
                self::COUNTRIES[$id % count(self::COUNTRIES)],
                $tiers[$id % count($tiers)],
            );
        }
    }

    public function find(int $id): ?Customer
    {
        return $this->customers[$id] ?? null;
    }
}
