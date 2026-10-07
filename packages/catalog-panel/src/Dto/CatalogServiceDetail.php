<?php

declare(strict_types=1);

namespace Karband\CatalogPanel\Dto;

final readonly class CatalogServiceDetail
{
    /**
     * @param array<string, string> $refundEvents
     * @param array<string, mixed> $settings
     */
    public function __construct(
        public string $code,
        public string $name,
        public ?string $description,
        public string $status,
        public int $revision,
        public ?int $fixedPriceMinor = null,
        public ?string $currency = null,
        public ?string $formattedPrice = null,
        public ?string $pricingMode = null,
        public array $refundEvents = [],
        public array $settings = [],
        public ?string $workflowId = null,
        public ?int $workflowVersion = null,
        public bool $isActive = false,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {
    }
}
