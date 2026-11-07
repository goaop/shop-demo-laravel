<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\OrderStatus;
use App\Domain\OutOfStock;
use App\Repositories\CustomerRepository;

/**
 * The checkout workload of one request: browse a category, place an order of five lines, repeat
 */
class CheckoutScenario
{
    private const array CATEGORIES = ['books', 'games', 'music', 'garden', 'kitchen', 'tools'];

    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly CatalogService $catalog,
        private readonly OrderService $orders,
        private readonly ReportService $reports,
    ) {}

    /**
     * @return array{placed: int, declined: int, report: array{paid: int, failed: int, revenue: int}}
     */
    public function run(int $orderCount): array
    {
        $placed   = 0;
        $declined = 0;
        for ($i = 1; $i <= $orderCount; $i++) {
            $customer = $this->customers->find(1 + $i % 50) ?? throw new \LogicException('No customer');
            // Browsing: a category page, mostly answered by the caching aspect
            $products = $this->catalog->listCategory(self::CATEGORIES[$i % 6]);
            $items    = [];
            for ($line = 0; $line < 5; $line++) {
                $items[$products[($i * 7 + $line * 3) % \count($products)]->id] = 1 + ($i + $line) % 6;
            }
            try {
                $order = $this->orders->placeOrder($customer, $items);
                $order->status === OrderStatus::Paid ? $placed++ : $declined++;
            } catch (OutOfStock) {
                $declined++;
            }
        }

        return ['placed' => $placed, 'declined' => $declined, 'report' => $this->reports->salesReport()];
    }
}
