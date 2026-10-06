<?php

declare(strict_types=1);

namespace Intl\ServiceCatalog\Definitions;

enum ServiceStatus: string
{
    case Inactive = 'inactive';
    case Active = 'active';
    case Archived = 'archived';
}
