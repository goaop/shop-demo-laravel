<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Order;

/**
 * Order confirmations of the request (a singleton, see AppServiceProvider); App\Listeners\SendOrderConfirmation
 * calls it for every OrderPlaced event
 */
class NotificationService
{
    /** @var list<string> */
    private array $outbox = [];

    public function orderConfirmation(Order $order): void
    {
        $this->outbox[] = \sprintf(
            "To: %s\nYour order #%d of %d items: %.2f %s",
            $order->customer->email,
            $order->id,
            \count($order->lines()),
            $order->total()->cents / 100,
            $order->total()->currency,
        );
    }

    public function sent(): int
    {
        return \count($this->outbox);
    }
}
