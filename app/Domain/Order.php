<?php

declare(strict_types=1);

namespace App\Domain;

final class Order
{
    /** @var list<OrderLine> */
    private array $lines = [];

    public OrderStatus $status = OrderStatus::New;

    public function __construct(
        public readonly int $id,
        public readonly Customer $customer,
    ) {}

    public function addLine(OrderLine $line): void
    {
        $this->lines[] = $line;
    }

    /** @return list<OrderLine> */
    public function lines(): array
    {
        return $this->lines;
    }

    public function total(): Money
    {
        $total = new Money(0);
        foreach ($this->lines as $line) {
            $total = $total->add($line->total());
        }

        return $total;
    }
}
