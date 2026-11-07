<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Order;
use App\Domain\OrderStatus;

class OrderRepository
{
    /** @var array<int, Order> */
    private array $orders = [];

    private int $sequence = 0;

    public function nextId(): int
    {
        return ++$this->sequence;
    }

    public function save(Order $order): void
    {
        $this->orders[$order->id] = $order;
    }

    /** @return list<Order> */
    public function findByStatus(OrderStatus $status): array
    {
        return array_values(array_filter($this->orders, static fn(Order $order): bool => $order->status === $status));
    }
}
