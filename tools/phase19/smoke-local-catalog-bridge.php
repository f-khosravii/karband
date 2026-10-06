<?php

declare(strict_types=1);

// Isolated CLI smoke using tiny fake CatalogStorage, NOT an Illuminate integration test.
namespace Intl\ServiceCatalogLaravel\Services {
    final readonly class CatalogStorage {
        public function __construct(public \Phase19FakeDb $connection) {}
    }
}
namespace {
    use Intl\BillingLaravel\Bridge\LocalCatalogFinancialTermsProvider;
    use Intl\BillingLaravel\Contracts\PayableRequestGuard;
    use Intl\ServiceCatalogLaravel\Services\CatalogStorage;

    $root = dirname(__DIR__, 2);
    foreach ([
        'packages/service-catalog/src/Financial/ServiceFinancialTerms.php',
        'packages/billing/src/ValueObjects/Money.php',
        'packages/billing/src/Refund/RefundInitiation.php',
        'packages/billing/src/Refund/RefundPolicy.php',
        'packages/billing-laravel/src/ValueObjects/FinancialTermsSnapshot.php',
        'packages/billing-laravel/src/Contracts/TrustedFinancialTermsProvider.php',
        'packages/billing-laravel/src/Contracts/TrustedQuoteProvider.php',
        'packages/billing-laravel/src/Contracts/TrustedRefundPolicyProvider.php',
        'packages/billing-laravel/src/Contracts/PayableRequestGuard.php',
        'packages/billing-laravel/src/Bridge/LocalCatalogFinancialTermsProvider.php',
    ] as $file) { require $root . '/' . $file; }

    final class Phase19FakeDb {
        public ?object $row;
        public function __construct(?object $row) { $this->row = $row; }
        public function getName(): ?string { return 'fixture-db'; }
        public function table(string $table): self {
            if ($table !== 'intl_catalog_services') { throw new \RuntimeException('Unexpected table.'); }
            return $this;
        }
        public function where(string $key, string $value): self {
            if ($this->row !== null && ($this->row->{$key} ?? null) !== $value) { $this->row = null; }
            return $this;
        }
        public function first(): ?object { return $this->row; }
    }
    final readonly class Phase19FakeGuard implements PayableRequestGuard {
        public function __construct(private bool $authorized, private ?string $connectionName = 'fixture-db') {}
        public function connectionName(): ?string { return $this->connectionName; }
        public function assertPayable(string $serviceCode, string $resourceType, string $resourceId, string $agencyId): void {
            if (!$this->authorized || $serviceCode !== 'test' || $resourceType !== 'service_request'
                || $resourceId !== 'req-1' || $agencyId !== 'agency-a') { throw new \RuntimeException('Denied.'); }
        }
    }
    $make = static fn () => (object) [
        'code' => 'test', 'status' => 'active', 'revision' => 9,
        'settings' => json_encode([
            'billing_fixed_price_minor' => 3000, 'billing_currency' => 'IRR',
            'refund_events' => ['request.rejected' => 'automatic'],
        ], JSON_THROW_ON_ERROR),
    ];
    $provider = new LocalCatalogFinancialTermsProvider(new CatalogStorage(new Phase19FakeDb($make())), new Phase19FakeGuard(true));
    $actual = $provider->snapshot('test', 'service_request', 'req-1', 'agency-a');
    if ($actual->money->amount !== 3000 || $actual->catalogRevision !== 9 ||
        $actual->refundPolicy->toArray() !== ['request.rejected' => 'automatic']) { throw new \RuntimeException('Incorrect financial snapshot.'); }
    $negativeCases = [
        new LocalCatalogFinancialTermsProvider(new CatalogStorage(new Phase19FakeDb($make())), new Phase19FakeGuard(false)),
        new LocalCatalogFinancialTermsProvider(new CatalogStorage(new Phase19FakeDb($make())), new Phase19FakeGuard(true, 'other-db')),
        new LocalCatalogFinancialTermsProvider(new CatalogStorage(new Phase19FakeDb((object) [...(array) $make(), 'status' => 'inactive'])), new Phase19FakeGuard(true)),
    ];
    foreach ($negativeCases as $bad) {
        try { $bad->snapshot('test', 'service_request', 'req-1', 'agency-a'); }
        catch (\RuntimeException) { continue; }
        throw new \RuntimeException('Bridge failed to deny unsafe access.');
    }
    echo "PASS: isolated price/policy snapshot + 3 fail-closed cases; NOT Laravel/central API certification.\n";
}
