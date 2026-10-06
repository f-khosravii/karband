<?php

declare(strict_types=1);

namespace Karband\Workflow\Contracts;

use Karband\Workflow\ValueObjects\GuardResult;
use Karband\Workflow\ValueObjects\TransitionContext;

/** Implement in each service; guards should only inspect trusted facts / read-only services. */
interface TransitionGuard
{
    public function evaluate(TransitionContext $context): GuardResult;
}
