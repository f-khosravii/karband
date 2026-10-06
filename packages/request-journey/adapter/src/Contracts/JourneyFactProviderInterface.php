<?php

declare(strict_types=1);

namespace Karband\RequestJourneyLaravel\Contracts;

use Karband\RequestJourney\ValueObjects\JourneyFacts;

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
