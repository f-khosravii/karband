<?php

declare(strict_types=1);

namespace Intl\RequestJourneyLaravel\Contracts;

use Intl\RequestJourney\ValueObjects\JourneyFacts;

interface JourneyFactProviderInterface
{
    /**
     * Gather a coherent fact snapshot for the given service request.
     *
     * @param string $requestId The unique request identifier
     * @param string $serviceCode The service catalog code
     * @return JourneyFacts
     */
    public function factsFor(string $requestId, string $serviceCode): JourneyFacts;
}
