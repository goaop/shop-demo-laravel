<?php

declare(strict_types=1);

namespace App\Services;

use App\Attributes\Loggable;
use App\Domain\Order;
use App\Domain\OrderStatus;

class PaymentService
{
    #[Loggable]
    public function charge(Order $order): bool
    {
        // A fake gateway: every 13th order is declined
        $accepted      = $order->id % 13 !== 0;
        $order->status = $accepted ? OrderStatus::Paid : OrderStatus::Failed;

        return $accepted;
    }
}
