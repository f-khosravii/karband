<?php

declare(strict_types=1);

namespace Karband\RequestJourney\ValueObjects;

final readonly class JourneyFacts
{
    /**
     * @param array<string, bool> $completedFormSections
     * @param array<string, bool> $customFacts
     */
    public function __construct(
        public ?string $workflowState = null,
        public ?string $pricingMode = null,
        public bool $formCompleted = false,
        public array $completedFormSections = [],
        public bool $documentsCompleted = false,
        public bool $quoteProposed = false,
        public bool $quoteAccepted = false,
        public bool $quoteRejected = false,
        public bool $signatureCompleted = false,
        public bool $paymentConfirmed = false,
        public bool $reviewConfirmed = false,
        public array $customFacts = [],
    ) {
    }

    public function isFormSectionCompleted(string $sectionKey): bool
    {
        return $this->completedFormSections[$sectionKey] ?? false;
    }

    public function hasCustomFact(string $key): bool
    {
        return $this->customFacts[$key] ?? false;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'workflow_state' => $this->workflowState,
            'pricing_mode' => $this->pricingMode,
            'form_completed' => $this->formCompleted,
            'completed_form_sections' => $this->completedFormSections,
            'documents_completed' => $this->documentsCompleted,
            'quote_proposed' => $this->quoteProposed,
            'quote_accepted' => $this->quoteAccepted,
            'quote_rejected' => $this->quoteRejected,
            'signature_completed' => $this->signatureCompleted,
            'payment_confirmed' => $this->paymentConfirmed,
            'review_confirmed' => $this->reviewConfirmed,
            'custom_facts' => $this->customFacts,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            workflowState: isset($data['workflow_state']) ? (string) $data['workflow_state'] : null,
            pricingMode: isset($data['pricing_mode']) ? (string) $data['pricing_mode'] : null,
            formCompleted: (bool) ($data['form_completed'] ?? false),
            completedFormSections: isset($data['completed_form_sections']) && is_array($data['completed_form_sections']) ? $data['completed_form_sections'] : [],
            documentsCompleted: (bool) ($data['documents_completed'] ?? false),
            quoteProposed: (bool) ($data['quote_proposed'] ?? false),
            quoteAccepted: (bool) ($data['quote_accepted'] ?? false),
            quoteRejected: (bool) ($data['quote_rejected'] ?? false),
            signatureCompleted: (bool) ($data['signature_completed'] ?? false),
            paymentConfirmed: (bool) ($data['payment_confirmed'] ?? false),
            reviewConfirmed: (bool) ($data['review_confirmed'] ?? false),
            customFacts: isset($data['custom_facts']) && is_array($data['custom_facts']) ? $data['custom_facts'] : [],
        );
    }
}
