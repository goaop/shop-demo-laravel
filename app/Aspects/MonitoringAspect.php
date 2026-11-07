<?php

declare(strict_types=1);

namespace App\Aspects;

use App\Infrastructure\AuditLog;
use App\Infrastructure\Metrics;
use Go\Aop\Aspect;
use Go\Aop\Intercept\MethodInvocation;
use Go\Lang\Attribute\AfterThrowing;
use Go\Lang\Attribute\Around;
use Go\Lang\Attribute\Pointcut;

/**
 * Times every repository call and records every exception thrown by a service
 */
final class MonitoringAspect implements Aspect
{
    public function __construct(
        private readonly Metrics $metrics,
        private readonly AuditLog $auditLog,
    ) {}

    #[Pointcut('execution(public App\Repositories\*->*(*)) || execution(public App\Modules\**\*Repository->*(*))')]
    protected function repositories(): void {}

    #[Around('$this->repositories')]
    public function measure(MethodInvocation $invocation): mixed
    {
        $start = hrtime(true);
        try {
            return $invocation->proceed();
        } finally {
            $this->metrics->record($invocation->getMethod()->name, hrtime(true) - $start);
        }
    }

    #[AfterThrowing('execution(public App\Services\*->*(*))')]
    public function logFailure(MethodInvocation $invocation, \Throwable $exception): void
    {
        $this->auditLog->record('error', $invocation->getMethod()->name . ' failed: ' . $exception->getMessage());
    }
}
