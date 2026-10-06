<?php

declare(strict_types=1);

namespace Intl\ServiceCatalog\Tests;

use Intl\ServiceCatalog\Financial\PricingMode;
use Intl\ServiceCatalog\Financial\ServiceFinancialTerms;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ServiceFinancialTermsTest extends TestCase
{
    public function test_fixed_price_and_full_refund_mapping_are_validated(): void
    {
        $terms = ServiceFinancialTerms::fromCatalogSettings([
            'pricing_mode' => 'fixed_catalog',
            'billing_fixed_price_minor' => 65000,
            'billing_currency' => 'IRR',
            'refund_events' => ['request.cancelled' => 'admin_approval', 'request.rejected' => 'automatic'],
        ]);
        self::assertSame(PricingMode::FixedCatalog, $terms->pricingMode);
        self::assertSame(65000, $terms->fixedPrice);
        self::assertSame('IRR', $terms->currency);
        self::assertSame('automatic', $terms->refundEvents['request.rejected']);
    }

    public function test_request_quote_pricing_mode_is_supported(): void
    {
        $terms = ServiceFinancialTerms::fromCatalogSettings([
            'pricing_mode' => 'request_quote',
            'billing_currency' => 'IRR',
            'refund_events' => ['request.cancelled' => 'automatic'],
        ]);
        self::assertSame(PricingMode::RequestQuote, $terms->pricingMode);
        self::assertSame(0, $terms->fixedPrice);
        self::assertSame('IRR', $terms->currency);
    }

    public function test_no_charge_pricing_mode_is_supported(): void
    {
        $terms = ServiceFinancialTerms::fromCatalogSettings([
            'pricing_mode' => 'no_charge',
            'refund_events' => [],
        ]);
        self::assertSame(PricingMode::NoCharge, $terms->pricingMode);
        self::assertSame(0, $terms->fixedPrice);
    }

    public function test_explicit_no_refund_policy_is_accepted(): void
    {
        $terms = ServiceFinancialTerms::fromCatalogSettings([
            'billing_fixed_price_minor' => 1,
            'billing_currency' => 'IRR',
            'refund_events' => [],
        ]);
        self::assertSame([], $terms->refundEvents);
    }

    #[DataProvider('invalidSettings')]
    public function test_invalid_or_missing_terms_fail_closed(array $settings): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ServiceFinancialTerms::fromCatalogSettings($settings);
    }

    public static function invalidSettings(): array
    {
        $base = ['billing_fixed_price_minor' => 100, 'billing_currency' => 'IRR', 'refund_events' => []];
        return [
            'missing-refund-policy' => [['billing_fixed_price_minor' => 100, 'billing_currency' => 'IRR']],
            'client-string-price' => [[...$base, 'billing_fixed_price_minor' => '100']],
            'float-price' => [[...$base, 'billing_fixed_price_minor' => 100.00]],
            'zero-price-fixed-catalog' => [['pricing_mode' => 'fixed_catalog', 'billing_fixed_price_minor' => 0, 'billing_currency' => 'IRR', 'refund_events' => []]],
            'lowercase-currency' => [[...$base, 'billing_currency' => 'irr']],
            'invalid-pricing-mode' => [['pricing_mode' => 'invalid_mode', 'refund_events' => []]],
            'invalid-policy-mode' => [[...$base, 'refund_events' => ['request.cancelled' => 'partial']]],
            'invalid-event-name' => [[...$base, 'refund_events' => ['Request Cancelled' => 'automatic']]],
            'list-rather-than-event-map' => [[...$base, 'refund_events' => ['automatic']]],
        ];
    }
}
