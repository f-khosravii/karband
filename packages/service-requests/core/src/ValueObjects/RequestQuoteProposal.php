<?php

declare(strict_types=1);

namespace Karband\ServiceRequests\ValueObjects;

final readonly class RequestQuoteProposal
{
    public function __construct(
        public string $id,
        public string $requestId,
        public int $revision,
        public int $amount,
        public string $currency,
        public QuoteStatus $status,
        public string $proposedBy,
        public \DateTimeImmutable $proposedAt,
        public ?\DateTimeImmutable $acceptedAt = null,
        public ?string $acceptedBy = null,
        public ?\DateTimeImmutable $rejectedAt = null,
        public ?string $rejectedBy = null,
        public ?string $rejectionReason = null,
        public ?string $notes = null,
    ) {
        if (trim($this->id) === '') {
            throw new \InvalidArgumentException('Quote proposal ID cannot be empty.');
        }
        if (trim($this->requestId) === '') {
            throw new \InvalidArgumentException('Request ID cannot be empty.');
        }
        if ($this->revision < 1) {
            throw new \InvalidArgumentException('Quote revision must be greater than or equal to 1.');
        }
        if ($this->amount <= 0) {
            throw new \InvalidArgumentException('Quote amount must be positive.');
        }
        if (!preg_match('/^[A-Z]{3}$/D', $this->currency)) {
            throw new \InvalidArgumentException('Currency must be a valid 3-letter ISO code.');
        }
        if (trim($this->proposedBy) === '') {
            throw new \InvalidArgumentException('Proposed by actor ID cannot be empty.');
        }
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'request_id' => $this->requestId,
            'revision' => $this->revision,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status->value,
            'proposed_by' => $this->proposedBy,
            'proposed_at' => $this->proposedAt->format(\DateTimeInterface::ATOM),
            'accepted_at' => $this->acceptedAt?->format(\DateTimeInterface::ATOM),
            'accepted_by' => $this->acceptedBy,
            'rejected_at' => $this->rejectedAt?->format(\DateTimeInterface::ATOM),
            'rejected_by' => $this->rejectedBy,
            'rejection_reason' => $this->rejectionReason,
            'notes' => $this->notes,
        ];
    }
}
