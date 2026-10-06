<?php

declare(strict_types=1);

namespace Intl\Workflow\Contracts;

use Intl\Workflow\ValueObjects\GuardResult;
use Intl\Workflow\ValueObjects\TransitionContext;

/** Implement in each service; guards should only inspect trusted facts / read-only services. */
interface TransitionGuard
{
    public function evaluate(TransitionContext $context): GuardResult;
}
