<?php

declare(strict_types=1);

namespace App\Aspects;

use App\Infrastructure\Metrics;
use Go\Aop\Aspect;
use Go\Aop\Intercept\MethodInvocation;
use Go\Lang\Attribute\Around;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Caches the result of #[Cacheable] methods in Laravel's "array" cache store, for the request
 */
final class CachingAspect implements Aspect
{
    private readonly Cache $cache;

    public function __construct(
        CacheFactory $caches,
        private readonly Metrics $metrics,
    ) {
        $this->cache = $caches->store('array');
    }

    #[Around('@execution(App\Attributes\Cacheable)')]
    public function aroundCacheable(MethodInvocation $invocation): mixed
    {
        $method = $invocation->getMethod();
        $key    = $method->class . '::' . $method->name;
        foreach ($invocation->getArguments() as $argument) {
            $key .= ':' . (\is_scalar($argument) ? $argument : get_debug_type($argument));
        }
        $this->metrics->increment('cache.lookups');

        return $this->cache->rememberForever($key, function () use ($invocation): mixed {
            $this->metrics->increment('cache.misses');

            return $invocation->proceed();
        });
    }
}
