<?php

declare(strict_types=1);

namespace Karband\CatalogPanel\Dto;

final readonly class CatalogServiceItem
{
    public function __construct(
        public string $code,
        public string $name,
        public ?string $description,
        public string $status,
        public int $revision,
        public ?int $fixedPriceMinor = null,
        public ?string $currency = null,
        public ?string $formattedPrice = null,
        public bool $isActive = false,
    ) {
    }
}
