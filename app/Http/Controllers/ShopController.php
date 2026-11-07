<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Infrastructure\AuditLog;
use App\Infrastructure\Metrics;
use App\Infrastructure\TransactionManager;
use App\Services\CheckoutScenario;
use App\Services\ModuleRunner;
use App\Services\NotificationService;
use App\Support\RequestClock;
use Go\Core\AspectKernel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The benchmark request: the checkout workload and the modules, answered with the business result, the counters of
 * the aspects and the cost of the request (timings, memory, included files, OPcache)
 */
final class ShopController extends Controller
{
    public function __construct(
        private readonly CheckoutScenario $checkout,
        private readonly ModuleRunner $modules,
        private readonly AuditLog $auditLog,
        private readonly Metrics $metrics,
        private readonly TransactionManager $transactions,
        private readonly NotificationService $notifications,
    ) {}

    public function shop(Request $request): JsonResponse
    {
        return $this->report([
            'checkout' => $this->checkout->run($request->integer('orders', 50)),
            'modules'  => $this->modules->run($request->integer('seed', 42)),
        ]);
    }

    public function checkout(Request $request): JsonResponse
    {
        return $this->report(['checkout' => $this->checkout->run($request->integer('orders', 50))]);
    }

    public function modules(Request $request): JsonResponse
    {
        return $this->report(['modules' => $this->modules->run($request->integer('seed', 42))]);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function report(array $result): JsonResponse
    {
        $end     = microtime(true);
        $start   = \defined('LARAVEL_START') ? (float) \constant('LARAVEL_START') : $end;
        $booted  = RequestClock::$bootedAt ?? $end;
        $opcache = \function_exists('opcache_get_status') ? opcache_get_status(false) : false;

        return response()->json([
            'env'      => app()->environment(),
            'debug'    => (bool) config('app.debug'),
            'aop'      => app()->bound(AspectKernel::class) ? [
                'debug'    => (bool) config('go_aop.debug'),
                'features' => (int) config('go_aop.features'),
            ] : null,
            'response' => $result + [
                'stats' => [
                    'audit_records' => $this->auditLog->count(),
                    'cache_lookups' => $this->metrics->counter('cache.lookups'),
                    'cache_misses'  => $this->metrics->counter('cache.misses'),
                    'commits'       => $this->transactions->commits,
                    'repo_calls'    => $this->metrics->timedCalls(),
                    'security'      => $this->metrics->counter('security.checks'),
                    'notifications' => $this->notifications->sent(),
                ],
            ],
            'timing'   => [
                // from the first line of public/index.php: autoloader, configuration, providers, boot (aspect kernel)
                'boot_ms'   => ($booted - $start) * 1000,
                'handle_ms' => ($end - $booted) * 1000,
                'total_ms'  => ($end - $start) * 1000,
            ],
            'memory_peak_kb' => intdiv(memory_get_peak_usage(), 1024),
            'included_files' => \count(get_included_files()),
            'opcache'        => \is_array($opcache) ? [
                'enabled' => $opcache['opcache_enabled'],
                'scripts' => $opcache['opcache_statistics']['num_cached_scripts'] ?? null,
                'hits'    => $opcache['opcache_statistics']['hits'] ?? null,
                'misses'  => $opcache['opcache_statistics']['misses'] ?? null,
            ] : null,
        ]);
    }
}
