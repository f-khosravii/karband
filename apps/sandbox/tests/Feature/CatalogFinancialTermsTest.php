<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Karband\Billing\Refund\RefundInitiation;
use App\Bridges\LocalCatalogFinancialTermsProvider;
use Karband\BillingLaravel\Contracts\PayableRequestGuard;
use Karband\ServiceCatalogLaravel\Services\CatalogStorage;
use Tests\TestCase;

/** Sandbox fixtures ONLY. This does not certify real authorization or central API. */
final class CatalogFinancialTermsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::connection(config('service-catalog.connection'))->table('catalog_services')->insert([
            'code' => 'phase19_local', 'name' => 'Test local service',
            'status' => 'active', 'settings' => json_encode([
                'billing_fixed_price_minor' => 9000,
                'billing_currency' => 'IRR',
                'refund_events' => ['request.rejected' => 'automatic'],
            ], JSON_THROW_ON_ERROR),
            'revision' => 3, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function bridge(bool $payable = true, ?string $connection = null): LocalCatalogFinancialTermsProvider
    {
        $storage = app(CatalogStorage::class);
        return new LocalCatalogFinancialTermsProvider($storage,
            new Phase19VerifiedRequestGate($connection ?? $storage->connection->getName(), $payable));
    }

    public function test_amount_policy_and_revision_come_from_one_catalog_row(): void
    {
        $terms = $this->bridge()->snapshot('phase19_local', 'service_request', 'req-1', 'agency-a');
        self::assertSame(9000, $terms->money->amount);
        self::assertSame('IRR', $terms->money->currency);
        self::assertSame(3, $terms->catalogRevision);
        self::assertSame(RefundInitiation::Automatic, $terms->refundPolicy->modeFor('request.rejected'));
    }

    public function test_second_snapshot_reflects_updated_price_without_rewriting_first(): void
    {
        $bridge = $this->bridge();
        $first = $bridge->snapshot('phase19_local', 'service_request', 'req-1', 'agency-a');
        DB::connection(config('service-catalog.connection'))->table('catalog_services')
            ->where('code', 'phase19_local')->update(['settings' => json_encode([
                'billing_fixed_price_minor' => 12000, 'billing_currency' => 'IRR',
                'refund_events' => ['request.cancelled' => 'manual'],
            ], JSON_THROW_ON_ERROR), 'revision' => 4]);
        $second = $bridge->snapshot('phase19_local', 'service_request', 'req-1', 'agency-a');
        self::assertSame(9000, $first->money->amount);
        self::assertSame(12000, $second->money->amount);
        self::assertSame(4, $second->catalogRevision);
        self::assertNull($second->refundPolicy->modeFor('request.rejected'));
    }

    public function test_unverified_resource_fails_closed(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->bridge(false)->snapshot('phase19_local', 'service_request', 'req-1', 'agency-a');
    }

    public function test_disabled_service_cannot_be_quoted(): void
    {
        DB::connection(config('service-catalog.connection'))->table('catalog_services')
            ->where('code', 'phase19_local')->update(['status' => 'inactive']);
        $this->expectException(\RuntimeException::class);
        $this->bridge()->snapshot('phase19_local', 'service_request', 'req-1', 'agency-a');
    }

    public function test_missing_refund_policy_is_not_interpreted_as_no_refund(): void
    {
        DB::connection(config('service-catalog.connection'))->table('catalog_services')
            ->where('code', 'phase19_local')->update(['settings' => json_encode([
                'billing_fixed_price_minor' => 9000, 'billing_currency' => 'IRR',
            ], JSON_THROW_ON_ERROR)]);
        $this->expectException(\InvalidArgumentException::class);
        $this->bridge()->snapshot('phase19_local', 'service_request', 'req-1', 'agency-a');
    }
}

/** Only for tests. A real host MUST check live principal, agency and pinned Workflow. */
final readonly class Phase19VerifiedRequestGate implements PayableRequestGuard
{
    public function __construct(private ?string $connection, private bool $payable) {}
    public function connectionName(): ?string { return $this->connection; }
    public function assertPayable(string $serviceCode, string $resourceType, string $resourceId, string $agencyId): void
    {
        if (!$this->payable || $serviceCode !== 'phase19_local' ||
            $resourceType !== 'service_request' || $resourceId !== 'req-1' || $agencyId !== 'agency-a') {
            throw new \RuntimeException('Not a trusted payable request.');
        }
    }
}
