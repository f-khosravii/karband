<?php

declare(strict_types=1);

// Standalone smoke: syntax and configuration validation ONLY, no Composer/Laravel or external API.
require dirname(__DIR__, 2) . '/packages/service-catalog/src/Financial/ServiceFinancialTerms.php';
use Intl\ServiceCatalog\Financial\ServiceFinancialTerms;

$valid = ServiceFinancialTerms::fromCatalogSettings([
    'billing_fixed_price_minor' => 12000,
    'billing_currency' => 'IRR',
    'refund_events' => ['request.cancelled' => 'admin_approval', 'request.rejected' => 'automatic'],
]);
if ($valid->fixedPrice !== 12000 || $valid->refundEvents['request.rejected'] !== 'automatic') {
    throw new \RuntimeException('Valid terms produced unexpected values.');
}
$cases = [
    ['billing_fixed_price_minor' => 100, 'billing_currency' => 'IRR'],
    ['billing_fixed_price_minor' => '100', 'billing_currency' => 'IRR', 'refund_events' => []],
    ['billing_fixed_price_minor' => 100, 'billing_currency' => 'irr', 'refund_events' => []],
    ['billing_fixed_price_minor' => 100, 'billing_currency' => 'IRR', 'refund_events' => ['x' => 'partial']],
];
foreach ($cases as $case) {
    try { ServiceFinancialTerms::fromCatalogSettings($case); }
    catch (\InvalidArgumentException) { continue; }
    throw new \RuntimeException('Unsafe financial configuration was accepted.');
}
echo "PASS: 1 valid config + " . count($cases) . " invalid configs rejected (offline smoke only).\n";
