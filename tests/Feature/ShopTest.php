<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Product;
use App\Modules\Module01\Module01Service;
use App\Repositories\ProductRepository;
use App\Services\OrderService;
use Go\Aop\Proxy;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/**
 * The request of the demo in the testing environment (Go! AOP in debug mode): the aspects did their work.
 *
 * Every test runs in its own process: a woven method keeps its interceptors, and with them the aspect instances, in a
 * static variable for the rest of the process, while Laravel creates a new application for every test. In one process,
 * the aspects of the first test would keep the singletons of the first application (the audit log, the metrics) and
 * the later tests would read empty counters from theirs.
 */
#[RunTestsInSeparateProcesses]
final class ShopTest extends TestCase
{
    public function test_the_shop_request_runs_every_aspect(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertJsonPath('response.checkout', ['placed' => 47, 'declined' => 3, 'report' => ['paid' => 47, 'failed' => 3, 'revenue' => 4548713]]);
        $response->assertJsonPath('response.modules.modules', 40);
        $response->assertJsonPath('response.stats', [
            // LoggingAspect (350 calls of #[Loggable] methods) + StockAuditAspect (450 writes of Product::$stock)
            'audit_records' => 800,
            // CachingAspect: 301 calls of #[Cacheable] methods, 37 distinct keys
            'cache_lookups' => 301,
            'cache_misses'  => 37,
            // TransactionAspect: one commit per order
            'commits'       => 50,
            // MonitoringAspect: every repository call
            'repo_calls'    => 820,
            // SecurityAspect: once per module
            'security'      => 40,
            // App\Listeners\SendOrderConfirmation, a discovered listener of OrderPlaced
            'notifications' => 50,
        ]);
    }

    public function test_services_and_entities_are_woven(): void
    {
        $this->assertInstanceOf(Proxy::class, $this->app->make(OrderService::class));
        $this->assertInstanceOf(Proxy::class, $this->app->make(ProductRepository::class));
        $this->assertInstanceOf(Proxy::class, $this->app->make(Module01Service::class));
        $this->assertTrue(is_subclass_of(Product::class, Proxy::class), 'Product is woven for its property advice');
    }

    public function test_a_second_application_in_the_same_process_is_woven_too(): void
    {
        // A new process, a new application: the aspects use its singletons
        $this->get('/checkout')->assertOk()->assertJsonPath('response.stats.commits', 50);
    }
}
