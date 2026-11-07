<?php

declare(strict_types=1);

namespace App\Services;

use App\Attributes\Loggable;
use App\Attributes\Transactional;
use App\Domain\Customer;
use App\Domain\Order;
use App\Domain\OrderLine;
use App\Domain\OrderStatus;
use App\Events\OrderPlaced;
use App\Repositories\OrderRepository;
use Illuminate\Contracts\Events\Dispatcher;

class OrderService
{
    public function __construct(
        private readonly CatalogService $catalog,
        private readonly PricingService $pricing,
        private readonly TaxCalculator $taxes,
        private readonly InventoryService $inventory,
        private readonly PaymentService $payments,
        private readonly OrderRepository $orders,
        private readonly Dispatcher $events,
    ) {}

    /**
     * @param array<int, int> $items product id => quantity
     */
    #[Loggable]
    #[Transactional]
    public function placeOrder(Customer $customer, array $items): Order
    {
        $order = new Order($this->orders->nextId(), $customer);
        foreach ($items as $productId => $quantity) {
            $product   = $this->catalog->product($productId);
            $unitPrice = $this->pricing->priceFor($product, $customer, $quantity);
            $tax       = $this->taxes->taxFor($unitPrice->multiply($quantity), $customer->country, $product->category);
            $this->inventory->reserve($product, $quantity);
            $order->addLine(new OrderLine($product, $quantity, $unitPrice, $tax));
        }
        $order->status = OrderStatus::Reserved;
        $this->payments->charge($order);
        $this->orders->save($order);
        $this->events->dispatch(new OrderPlaced($order));

        return $order;
    }
}
