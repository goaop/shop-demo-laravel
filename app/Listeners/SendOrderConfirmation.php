<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrderPlaced;
use App\Services\NotificationService;

/**
 * Discovered by Laravel's event discovery (app/Listeners, typed handle() argument)
 */
final class SendOrderConfirmation
{
    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    public function handle(OrderPlaced $event): void
    {
        $this->notifications->orderConfirmation($event->order);
    }
}
