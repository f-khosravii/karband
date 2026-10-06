<?php

declare(strict_types=1);

namespace Intl\ServiceCatalogLaravel\ValueObjects;

/** Minimal, agency-facing catalog entry; never leaks internal settings or binding. */
final readonly class PublicService
{
    public function __construct(
        public string $code,
        public string $name,
        public ?string $description,
    ) {
    }
}
