<?php

declare(strict_types=1);

namespace App\Services;

use App\Attributes\Cacheable;
use App\Domain\OrderStatus;
use App\Repositories\OrderRepository;

class ReportService
{
    public function __construct(
        private readonly OrderRepository $orders,
    ) {}

    /** @return array{paid: int, failed: int, revenue: int} */
    #[Cacheable]
    public function salesReport(): array
    {
        $paid    = $this->orders->findByStatus(OrderStatus::Paid);
        $revenue = 0;
        foreach ($paid as $order) {
            $revenue += $order->total()->cents;
        }

        return [
            'paid'    => count($paid),
            'failed'  => count($this->orders->findByStatus(OrderStatus::Failed)),
            'revenue' => $revenue,
        ];
    }
}
