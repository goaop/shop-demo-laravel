<?php

declare(strict_types=1);

namespace App\Aspects;

use App\Infrastructure\AuditLog;
use Go\Aop\Aspect;
use Go\Aop\Intercept\MethodInvocation;
use Go\Lang\Attribute\Before;

/**
 * Records every call of a #[Loggable] method. Implementing Aspect is enough: autoconfiguration registers it,
 * autowiring injects the audit log
 */
final class LoggingAspect implements Aspect
{
    public function __construct(
        private readonly AuditLog $auditLog,
    ) {}

    #[Before('@execution(App\Attributes\Loggable)')]
    public function beforeLoggable(MethodInvocation $invocation): void
    {
        $method = $invocation->getMethod();
        $this->auditLog->record('call', $method->class . '->' . $method->name . ' with ' . \count($invocation->getArguments()) . ' arguments');
    }
}
