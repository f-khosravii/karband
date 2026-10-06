<?php

declare(strict_types=1);

namespace Karband\ServiceCatalog\Financial;

enum PricingMode: string
{
    case FixedCatalog = 'fixed_catalog';
    case RequestQuote = 'request_quote';
    case NoCharge = 'no_charge';
}
