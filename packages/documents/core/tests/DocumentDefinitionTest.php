<?php

declare(strict_types=1);

use Karband\Documents\Definitions\DocumentDefinition;
use Karband\Documents\Definitions\DocumentRequirement;
use Karband\Documents\Engine\DocumentValidator;
use Karband\Documents\Exceptions\InvalidDocumentDefinition;
use Karband\Documents\ValueObjects\UploadDescriptor;
use PHPUnit\Framework\TestCase;

final class DocumentDefinitionTest extends TestCase
{
    private function definition(): DocumentDefinition
    {
        return new DocumentDefinition('ncr.birth', 1, [
            new DocumentRequirement('passport', 'Passport', true),
            new DocumentRequirement('extra', 'Additional', false),
        ]);
    }

    public function test_definition_round_trip(): void
    {
        $d = $this->definition();
        self::assertSame($d->toArray(), DocumentDefinition::fromArray($d->toArray())->toArray());
    }

    public function test_required_slot_only(): void
    {
        self::assertSame(['passport'], (new DocumentValidator())->missingRequired($this->definition(), ['extra']));
        self::assertSame([], (new DocumentValidator())->missingRequired($this->definition(), ['passport']));
    }

    public function test_mime_is_checked(): void
    {
        self::assertSame(['mime_not_allowed'], (new DocumentValidator())->validateUpload($this->definition()->requirement('passport'), new UploadDescriptor('text/html', 30, str_repeat('a', 64))));
    }

    public function test_size_is_checked(): void
    {
        self::assertSame(['file_too_large'], (new DocumentValidator())->validateUpload(new DocumentRequirement('x', 'X', true, ['application/pdf'], 20), new UploadDescriptor('application/pdf', 21, str_repeat('b', 64))));
    }

    public function test_duplicate_slots_rejected(): void
    {
        $this->expectException(InvalidDocumentDefinition::class);
        new DocumentDefinition('ncr.birth', 1, [new DocumentRequirement('x', 'X', true), new DocumentRequirement('x', 'X', false)]);
    }

    public function test_invalid_code_rejected(): void
    {
        $this->expectException(InvalidDocumentDefinition::class);
        new DocumentRequirement('../../file', 'File', true);
    }

    public function test_invalid_version_rejected(): void
    {
        $this->expectException(InvalidDocumentDefinition::class);
        new DocumentDefinition('birth', 0, []);
    }

    public function test_unknown_definition_keys_rejected(): void
    {
        $this->expectException(InvalidDocumentDefinition::class);
        DocumentDefinition::fromArray($this->definition()->toArray() + ['execute' => 'untrusted']);
    }

    public function test_empty_optional_definition_is_valid(): void
    {
        self::assertSame([], (new DocumentValidator())->missingRequired(new DocumentDefinition('x', 1, []), []));
    }

    public function test_mime_duplicate_rejected(): void
    {
        $this->expectException(InvalidDocumentDefinition::class);
        new DocumentRequirement('x', 'X', false, ['image/png', 'image/png']);
    }

    public function test_v2_definition_round_trip(): void
    {
        $d = new DocumentDefinition('insurance.policy', 2, [
            new DocumentRequirement(
                'power_of_attorney',
                'Power of Attorney',
                false,
                ['application/pdf'],
                5_000_000,
                ['field' => 'has_representative', 'equals' => true],
                ['signing', 'underwriting']
            ),
            new DocumentRequirement(
                'final_certificate',
                'Final Certificate',
                true,
                ['application/pdf'],
                10_000_000,
                null,
                ['completion']
            ),
        ]);

        $serialized = $d->toArray();
        $deserialized = DocumentDefinition::fromArray($serialized);
        self::assertSame($serialized, $deserialized->toArray());
    }

    public function test_required_when_evaluation(): void
    {
        $req = new DocumentRequirement(
            'poa',
            'POA',
            false,
            requiredWhen: ['field' => 'has_representative', 'equals' => true]
        );
        $def = new DocumentDefinition('srv', 1, [$req]);
        $val = new DocumentValidator();

        // 1. Condition matches: required
        self::assertTrue($req->isRequired(['has_representative' => true]));
        self::assertSame(['poa'], $val->missingRequired($def, [], ['has_representative' => true]));
        self::assertSame([], $val->missingRequired($def, ['poa'], ['has_representative' => true]));

        // 2. Condition does not match: not required
        self::assertFalse($req->isRequired(['has_representative' => false]));
        self::assertSame([], $val->missingRequired($def, [], ['has_representative' => false]));

        // 3. Missing condition field: not required
        self::assertFalse($req->isRequired([]));
        self::assertSame([], $val->missingRequired($def, [], []));
    }

    public function test_required_when_scalar_string_condition(): void
    {
        $req = new DocumentRequirement(
            'business_license',
            'Business License',
            false,
            requiredWhen: ['field' => 'applicant_type', 'equals' => 'company']
        );

        self::assertTrue($req->isRequired(['applicant_type' => 'company']));
        self::assertFalse($req->isRequired(['applicant_type' => 'individual']));
        self::assertFalse($req->isRequired(['applicant_type' => null]));
    }

    public function test_required_at_checkpoint_evaluation(): void
    {
        $req = new DocumentRequirement(
            'signed_contract',
            'Signed Contract',
            false,
            requiredAt: ['signing', 'completion']
        );
        $def = new DocumentDefinition('srv', 1, [$req]);
        $val = new DocumentValidator();

        // 1. Checkpoint matches
        self::assertTrue($req->isRequired([], 'signing'));
        self::assertTrue($req->isRequired([], 'completion'));
        self::assertSame(['signed_contract'], $val->missingRequired($def, [], [], 'signing'));

        // 2. Checkpoint does not match
        self::assertFalse($req->isRequired([], 'draft'));
        self::assertFalse($req->isRequired([], 'underwriting'));
        self::assertSame([], $val->missingRequired($def, [], [], 'draft'));

        // 3. No checkpoint supplied
        self::assertFalse($req->isRequired([], null));
        self::assertSame([], $val->missingRequired($def, [], []));
    }

    public function test_combined_required_when_and_required_at(): void
    {
        $req = new DocumentRequirement(
            'rep_id',
            'Rep ID',
            false,
            requiredWhen: ['field' => 'has_representative', 'equals' => true],
            requiredAt: ['underwriting']
        );

        // Both match
        self::assertTrue($req->isRequired(['has_representative' => true], 'underwriting'));

        // Condition matches, but wrong checkpoint
        self::assertFalse($req->isRequired(['has_representative' => true], 'draft'));

        // Checkpoint matches, but condition false
        self::assertFalse($req->isRequired(['has_representative' => false], 'underwriting'));
    }

    public function test_malformed_required_when_rejected(): void
    {
        $this->expectException(InvalidDocumentDefinition::class);
        new DocumentRequirement('x', 'X', false, requiredWhen: ['unknown' => 'bad']);
    }

    public function test_malformed_required_at_rejected(): void
    {
        $this->expectException(InvalidDocumentDefinition::class);
        new DocumentRequirement('x', 'X', false, requiredAt: ['']);
    }

    public function test_requirement_combination_semantics(): void
    {
        // 1. Legacy unconditional required: true slot remains required across all states
        $legacy = new DocumentRequirement('id_card', 'National ID', true);
        self::assertTrue($legacy->isRequired([], null));
        self::assertTrue($legacy->isRequired([], 'draft'));
        self::assertTrue($legacy->isRequired([], 'completion'));

        // 2. Stage-specific requirement: required only when checkpoint matches
        $stageSlot = new DocumentRequirement('signed_contract', 'Signed Contract', false, requiredAt: ['signing']);
        self::assertFalse($stageSlot->isRequired([], null));
        self::assertFalse($stageSlot->isRequired([], 'draft'));
        self::assertTrue($stageSlot->isRequired([], 'signing'));
    }
}
