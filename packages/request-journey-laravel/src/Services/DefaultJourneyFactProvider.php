<?php

declare(strict_types=1);

namespace Intl\RequestJourneyLaravel\Services;

use Illuminate\Contracts\Container\Container;
use Intl\DocumentsLaravel\Services\TrustedDocumentFacts;
use Intl\DynamicForms\Engine\FormValidator;
use Intl\DynamicFormsLaravel\Services\FormRegistry;
use Intl\DynamicFormsLaravel\Services\FormStorage;
use Intl\RequestJourney\ValueObjects\JourneyFacts;
use Intl\RequestJourneyLaravel\Contracts\JourneyFactProviderInterface;

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
        $requestRow = $db->table('intl_service_requests')->where('id', $requestId)->first();
        if ($requestRow !== null && $requestRow->workflow_instance_id !== null) {
            $wf = $db->table('intl_workflow_instances')->where('id', $requestRow->workflow_instance_id)->first();
            if ($wf !== null) {
                $workflowState = (string) $wf->current_state;
            }
        }

        // 2. Pricing Mode
        $pricingMode = null;
        $catalogRow = $db->table('intl_catalog_services')->where('code', $serviceCode)->first();
        if ($catalogRow !== null) {
            $settings = json_decode((string) $catalogRow->settings, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($settings)) {
                $pricingMode = (string) ($settings['financial']['pricing_mode'] ?? $settings['pricing_mode'] ?? 'fixed_catalog');
            }
        }

        // 3. Form Section & Full Form Completion
        $formCompleted = false;
        $completedSections = [];

        $answersRow = $db->table('intl_form_answers')
            ->where('service_code', $serviceCode)
            ->where('resource_type', 'intl.service_request')
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

                        // Also verify if required field is filled
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
                // If form cannot be loaded or validated, leave formCompleted = false
            }
        }

        // 4. Document Completeness
        $documentsCompleted = false;
        if (class_exists(TrustedDocumentFacts::class)) {
            try {
                $docFacts = $this->container->make(TrustedDocumentFacts::class);
                $documentsCompleted = $docFacts->isComplete($serviceCode, 'intl.service_request', $requestId);
            } catch (\Throwable) {
                $documentsCompleted = false;
            }
        }

        // 5. Quote Facts
        $quoteProposed = false;
        $quoteAccepted = false;
        $quoteRejected = false;

        $quoteRow = $db->table('intl_request_quotes')->where('request_id', $requestId)->first();
        if ($quoteRow !== null) {
            $quoteProposed = ($quoteRow->status === 'proposed');
            $quoteAccepted = ($quoteRow->status === 'accepted' || $quoteRow->accepted_revision !== null);
            $quoteRejected = ($quoteRow->status === 'rejected');
        }

        // 6. Payment Facts
        $paymentConfirmed = $db->table('intl_payment_intents')
            ->where('resource_type', 'intl.service_request')
            ->where('resource_id', $requestId)
            ->where('status', 'succeeded')
            ->exists();

        // 7. Signature Fact (check if signed proposal or signed document is attached)
        $signatureCompleted = false;
        $hasSignedDoc = $db->table('intl_document_files')
            ->join('intl_document_sets', 'intl_document_files.set_id', '=', 'intl_document_sets.id')
            ->where('intl_document_sets.resource_type', 'intl.service_request')
            ->where('intl_document_sets.resource_id', $requestId)
            ->whereIn('intl_document_files.requirement_code', ['signed_proposal', 'signed_document', 'signature'])
            ->exists();

        if ($hasSignedDoc) {
            $signatureCompleted = true;
        }

        // 8. Custom Facts from registered resolvers
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
