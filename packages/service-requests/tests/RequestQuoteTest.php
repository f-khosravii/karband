<?php

declare(strict_types=1);

namespace Intl\ServiceRequests\Tests;

use Intl\ServiceRequests\ValueObjects\QuoteStatus;
use Intl\ServiceRequests\ValueObjects\RequestQuoteProposal;
use PHPUnit\Framework\TestCase;

final class RequestQuoteTest extends TestCase
{
    public function test_quote_status_values_and_helpers(): void
    {
        self::assertSame('proposed', QuoteStatus::Proposed->value);
        self::assertSame('accepted', QuoteStatus::Accepted->value);
        self::assertSame('rejected', QuoteStatus::Rejected->value);
        self::assertSame('superseded', QuoteStatus::Superseded->value);

        self::assertTrue(QuoteStatus::Accepted->isTerminal());
        self::assertFalse(QuoteStatus::Proposed->isTerminal());
        self::assertTrue(QuoteStatus::Proposed->isCurrent());
        self::assertTrue(QuoteStatus::Accepted->isCurrent());
        self::assertFalse(QuoteStatus::Superseded->isCurrent());
    }

    public function test_proposal_requires_positive_amount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Quote amount must be positive.');

        new RequestQuoteProposal(
            id: 'q1',
            requestId: 'req-1',
            revision: 1,
            amount: 0,
            currency: 'IRR',
            status: QuoteStatus::Proposed,
            proposedBy: 'admin-1',
            proposedAt: new \DateTimeImmutable(),
        );
    }

    public function test_proposal_requires_valid_revision(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Quote revision must be greater than or equal to 1.');

        new RequestQuoteProposal(
            id: 'q1',
            requestId: 'req-1',
            revision: 0,
            amount: 50000,
            currency: 'IRR',
            status: QuoteStatus::Proposed,
            proposedBy: 'admin-1',
            proposedAt: new \DateTimeImmutable(),
        );
    }

    public function test_proposal_requires_valid_currency(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Currency must be a valid 3-letter ISO code.');

        new RequestQuoteProposal(
            id: 'q1',
            requestId: 'req-1',
            revision: 1,
            amount: 50000,
            currency: 'irr',
            status: QuoteStatus::Proposed,
            proposedBy: 'admin-1',
            proposedAt: new \DateTimeImmutable(),
        );
    }

    public function test_valid_proposal_instantiation_and_array_export(): void
    {
        $now = new \DateTimeImmutable('2026-10-05T10:00:00Z');
        $proposal = new RequestQuoteProposal(
            id: 'prop-1',
            requestId: 'req-100',
            revision: 2,
            amount: 75000,
            currency: 'IRR',
            status: QuoteStatus::Accepted,
            proposedBy: 'admin-42',
            proposedAt: $now,
            acceptedAt: $now,
            acceptedBy: 'agency-7',
            notes: 'Discount applied',
        );

        self::assertSame('prop-1', $proposal->id);
        self::assertSame('req-100', $proposal->requestId);
        self::assertSame(2, $proposal->revision);
        self::assertSame(75000, $proposal->amount);
        self::assertSame('IRR', $proposal->currency);
        self::assertSame(QuoteStatus::Accepted, $proposal->status);

        $array = $proposal->toArray();
        self::assertSame('accepted', $array['status']);
        self::assertSame('agency-7', $array['accepted_by']);
        self::assertSame('Discount applied', $array['notes']);
    }
}
