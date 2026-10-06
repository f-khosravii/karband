<?php

declare(strict_types=1);

namespace App\Bridges;

use Illuminate\Contracts\Container\Container;
use Karband\DocumentsLaravel\Services\TrustedDocumentFacts;
use Karband\DynamicForms\Engine\FormValidator;
use Karband\DynamicFormsLaravel\Services\FormRegistry;
use Karband\RequestJourney\ValueObjects\JourneyFacts;
use Karband\RequestJourneyLaravel\Contracts\JourneyFactProviderInterface;
use Karband\RequestJourneyLaravel\Services\JourneyStorage;

/** Host composition fact provider for Request Journey. */
final class DefaultJourneyFactProvider implements JourneyFactProviderInterface
{
    /** @var array<string, callable> */
    private array $customResolvers = [];

    public function __construct(
        private readonly JourneyStorage $storage,
        private readonly Container $container,
    ) {
    }

    public function registerCustomResolver(string $key, callable $resolver): self
    {
        $this->customResolvers[$key] = $resolver;
        return $this;
    }

    public function factsFor(string $requestId, string $serviceCode): JourneyFacts
    {
        $db = $this->storage->connection;

        // 1. Workflow State
        $workflowState = null;
        $requestRow = $db->table('service_requests')->where('id', $requestId)->first();
        if ($requestRow !== null && $requestRow->workflow_instance_id !== null) {
            $wf = $db->table('workflow_instances')->where('id', $requestRow->workflow_instance_id)->first();
            if ($wf !== null) {
                $workflowState = (string) $wf->current_state;
            }
        }

        // 2. Pricing Mode
        $pricingMode = null;
        $catalogRow = $db->table('catalog_services')->where('code', $serviceCode)->first();
        if ($catalogRow !== null) {
            $settings = json_decode((string) $catalogRow->settings, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($settings)) {
                $pricingMode = (string) ($settings['financial']['pricing_mode'] ?? $settings['pricing_mode'] ?? 'fixed_catalog');
            }
        }

        // 3. Form Section & Full Form Completion
        $formCompleted = false;
        $completedSections = [];

        $answersRow = $db->table('form_answers')
            ->where('service_code', $serviceCode)
            ->where('resource_type', 'service_request')
            ->where('resource_id', $requestId)
            ->first();

        if ($answersRow !== null && class_exists(FormRegistry::class) && class_exists(FormValidator::class)) {
            try {
                $formRegistry = $this->container->make(FormRegistry::class);
                $validator = $this->container->make(FormValidator::class);

                $formVersion = (int) $answersRow->form_version;
                $answers = json_decode((string) $answersRow->answers, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($answers)) {
                    $answers = [];
                }

                $formDefinition = $formRegistry->loadPublished($serviceCode, $formVersion);
                $validationResult = $validator->validate($formDefinition, $answers);
                $formCompleted = $validationResult->valid();

                $errors = $validationResult->errors;

                foreach ($formDefinition->sections as $section) {
                    $sectionValid = true;
                    foreach ($section->elements as $elem) {
                        $elemKey = $elem['key'];
                        $elemKind = $elem['kind'];

                        if (isset($errors[$elemKey])) {
                            $sectionValid = false;
                            break;
                        }

                        if ($elemKind === 'field') {
                            $field = null;
                            foreach ($formDefinition->fields as $f) {
                                if ($f->key === $elemKey) {
                                    $field = $f;
                                    break;
                                }
                            }
                            if ($field !== null && $field->required && (!isset($answers[$elemKey]) || $answers[$elemKey] === null || $answers[$elemKey] === '')) {
                                $sectionValid = false;
                                break;
                            }
                        }

                        if ($elemKind === 'repeatable_group') {
                            if (!isset($answers[$elemKey]) || !is_array($answers[$elemKey]) || count($answers[$elemKey]) === 0) {
                                $sectionValid = false;
                                break;
                            }
                        }
                    }
                    $completedSections[$section->key] = $sectionValid;
                }
            } catch (\Throwable) {
                // Ignore errors
            }
        }

        // 4. Document Completeness
        $documentsCompleted = false;
        if (class_exists(TrustedDocumentFacts::class)) {
            try {
                $docFacts = $this->container->make(TrustedDocumentFacts::class);
                $documentsCompleted = $docFacts->isComplete($serviceCode, 'service_request', $requestId);
            } catch (\Throwable) {
                $documentsCompleted = false;
            }
        }

        // 5. Quote Facts
        $quoteProposed = false;
        $quoteAccepted = false;
        $quoteRejected = false;

        $quoteRow = $db->table('request_quotes')->where('request_id', $requestId)->first();
        if ($quoteRow !== null) {
            $quoteProposed = ($quoteRow->status === 'proposed');
            $quoteAccepted = ($quoteRow->status === 'accepted' || $quoteRow->accepted_revision !== null);
            $quoteRejected = ($quoteRow->status === 'rejected');
        }

        // 6. Payment Facts
        $paymentConfirmed = $db->table('payment_intents')
            ->where('resource_type', 'service_request')
            ->where('resource_id', $requestId)
            ->where('status', 'succeeded')
            ->exists();

        // 7. Signature Fact
        $signatureCompleted = false;
        $hasSignedDoc = $db->table('document_files')
            ->join('document_sets', 'document_files.set_id', '=', 'document_sets.id')
            ->where('document_sets.resource_type', 'service_request')
            ->where('document_sets.resource_id', $requestId)
            ->whereIn('document_files.requirement_code', ['signed_proposal', 'signed_document', 'signature', 'signed_contract'])
            ->exists();

        if ($hasSignedDoc) {
            $signatureCompleted = true;
        }

        $customFacts = [
            'signed_document_present' => $hasSignedDoc,
        ];

        foreach ($this->customResolvers as $key => $resolver) {
            $customFacts[$key] = (bool) $resolver($requestId, $serviceCode, $this->container);
        }

        return new JourneyFacts(
            workflowState: $workflowState,
            pricingMode: $pricingMode,
            formCompleted: $formCompleted,
            completedFormSections: $completedSections,
            documentsCompleted: $documentsCompleted,
            quoteProposed: $quoteProposed,
            quoteAccepted: $quoteAccepted,
            quoteRejected: $quoteRejected,
            signatureCompleted: $signatureCompleted,
            paymentConfirmed: $paymentConfirmed,
            reviewConfirmed: false,
            customFacts: $customFacts,
        );
    }
}
