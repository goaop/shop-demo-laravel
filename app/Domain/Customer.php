<?php

declare(strict_types=1);

namespace App\Domain;

final class Customer
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $email,
        public readonly string $country,
        public readonly CustomerTier $tier,
    ) {}
}
