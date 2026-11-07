<?php

declare(strict_types=1);

namespace App\Aspects;

use App\Infrastructure\Metrics;
use Go\Aop\Aspect;
use Go\Aop\Intercept\MethodInvocation;
use Go\Lang\Attribute\Before;

/**
 * Guards the entry point of every module
 */
final class SecurityAspect implements Aspect
{
    public function __construct(
        private readonly Metrics $metrics,
    ) {}

    #[Before('execution(public App\Modules\**\*Service->handle(*))')]
    public function checkAccess(MethodInvocation $invocation): void
    {
        $this->metrics->increment('security.checks');
        if ($invocation->getArguments() === []) {
            throw new \LogicException('Anonymous access denied');
        }
    }
}
