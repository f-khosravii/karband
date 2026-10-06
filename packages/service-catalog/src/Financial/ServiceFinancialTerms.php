<?php

declare(strict_types=1);

namespace Intl\ServiceCatalog\Financial;

/**
 * Fail-closed interpretation of one LOCAL catalog revision.
 * Field names match the existing reference-host fixture; no wallet data.
 * Empty refund_events is an EXPLICIT no-refund policy; missing is invalid.
 */
final readonly class ServiceFinancialTerms
{
    /** @param array<string,string> $refundEvents */
    public function __construct(
        public int $fixedPrice,
        public string $currency,
        public array $refundEvents,
        public PricingMode $pricingMode = PricingMode::FixedCatalog,
    ) {}

    /** @param array<string,mixed> $settings */
    public static function fromCatalogSettings(array $settings): self
    {
        $rawMode = $settings['pricing_mode'] ?? 'fixed_catalog';
        $mode = match ($rawMode) {
            'fixed_catalog', PricingMode::FixedCatalog => PricingMode::FixedCatalog,
            'request_quote', PricingMode::RequestQuote => PricingMode::RequestQuote,
            'no_charge', PricingMode::NoCharge => PricingMode::NoCharge,
            default => throw new \InvalidArgumentException('Invalid pricing_mode: ' . (string) $rawMode),
        };

        if ($mode === PricingMode::NoCharge) {
            $amount = 0;
            $currency = (string) ($settings['billing_currency'] ?? 'IRR');
            if ($currency === '' || preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1) {
                $currency = 'IRR';
            }
        } elseif ($mode === PricingMode::RequestQuote) {
            $amount = 0;
            $currency = (string) ($settings['billing_currency'] ?? 'IRR');
            if (preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1) {
                throw new \InvalidArgumentException('Currency must be an uppercase three-letter identifier for request_quote.');
            }
        } else {
            foreach (['billing_fixed_price_minor', 'billing_currency'] as $required) {
                if (!array_key_exists($required, $settings)) {
                    throw new \InvalidArgumentException('Missing required financial setting for fixed_catalog: ' . $required);
                }
            }
            $amount = $settings['billing_fixed_price_minor'];
            $currency = $settings['billing_currency'];
            if (!is_int($amount) || $amount <= 0) {
                throw new \InvalidArgumentException('Fixed price must be a positive integer in the agreed central unit.');
            }
            if (!is_string($currency) || preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1) {
                throw new \InvalidArgumentException('Currency must be an uppercase three-letter identifier.');
            }
        }

        if (!array_key_exists('refund_events', $settings)) {
            throw new \InvalidArgumentException('Missing required financial setting: refund_events');
        }

        $events = $settings['refund_events'];
        if (!is_array($events) || ($events !== [] && array_is_list($events))) {
            throw new \InvalidArgumentException('Refund events must be an explicit object (or empty object).');
        }
        $modes = ['automatic', 'admin_approval', 'manual'];
        $verified = [];
        foreach ($events as $event => $eventMode) {
            if (!is_string($event) || preg_match('/\A[a-z][a-z0-9_.-]{0,79}\z/D', $event) !== 1 ||
                !is_string($eventMode) || !in_array($eventMode, $modes, true)) {
                throw new \InvalidArgumentException('Invalid refund event or initiation method.');
            }
            $verified[$event] = $eventMode;
        }
        ksort($verified);

        return new self($amount, $currency, $verified, $mode);
    }
}
