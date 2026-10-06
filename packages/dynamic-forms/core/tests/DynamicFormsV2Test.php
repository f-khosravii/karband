<?php

declare(strict_types=1);

namespace Karband\DynamicForms\Tests;

use Karband\DynamicForms\Definitions\FormDefinition;
use Karband\DynamicForms\Definitions\FormField;
use Karband\DynamicForms\Definitions\FormSection;
use Karband\DynamicForms\Definitions\RepeatableGroupDefinition;
use Karband\DynamicForms\Engine\FormValidator;
use Karband\DynamicForms\Engine\ItemIdentityNormalizer;
use Karband\DynamicForms\Exceptions\InvalidForm;
use PHPUnit\Framework\TestCase;

final class DynamicFormsV2Test extends TestCase
{
    // 1. Legacy flat form remains valid
    public function test_1_legacy_flat_form_remains_valid(): void
    {
        $form = new FormDefinition('ncr.test', 1, [
            new FormField('full_name', 'Full Name', 'text', true),
            new FormField('email', 'Email', 'email'),
        ]);

        $validator = new FormValidator();
        $result = $validator->validate($form, ['full_name' => 'Alice', 'email' => 'alice@example.com']);
        self::assertTrue($result->valid());
        self::assertSame('Alice', $result->answers['full_name']);
        self::assertEmpty($form->sections);
        self::assertEmpty($form->repeatableGroups);
    }

    // 2. Section definition with ordered field elements
    public function test_2_section_definition_with_ordered_field_elements(): void
    {
        $form = new FormDefinition(
            'ncr.test',
            1,
            [
                new FormField('applicant_name', 'Applicant Name', 'text', true),
                new FormField('applicant_dob', 'Date of Birth', 'date'),
            ],
            [
                new FormSection('personal', 'Personal Details', 'Basic information', [
                    ['kind' => 'field', 'key' => 'applicant_name'],
                    ['kind' => 'field', 'key' => 'applicant_dob'],
                ]),
            ]
        );

        self::assertCount(1, $form->sections);
        self::assertSame('personal', $form->sections[0]->key);
        self::assertCount(2, $form->sections[0]->elements);
        self::assertSame('applicant_name', $form->sections[0]->elements[0]['key']);
    }

    // 3. Section with repeatable group reference
    public function test_3_section_with_repeatable_group_reference(): void
    {
        $form = new FormDefinition(
            'ncr.test',
            1,
            [new FormField('applicant_name', 'Applicant Name', 'text', true)],
            [
                new FormSection('family', 'Family Members', null, [
                    ['kind' => 'field', 'key' => 'applicant_name'],
                    ['kind' => 'repeatable_group', 'key' => 'children'],
                ]),
            ],
            [
                new RepeatableGroupDefinition('children', 'Children', null, 0, 5, [
                    new FormField('child_name', 'Child Name', 'text', true),
                ]),
            ]
        );

        self::assertCount(1, $form->sections);
        self::assertSame('repeatable_group', $form->sections[0]->elements[1]['kind']);
        self::assertSame('children', $form->sections[0]->elements[1]['key']);
    }

    // 4. Invalid missing section reference rejected
    public function test_4_invalid_missing_section_reference_rejected(): void
    {
        $this->expectException(InvalidForm::class);
        new FormDefinition(
            'ncr.test',
            1,
            [new FormField('applicant_name', 'Applicant Name', 'text', true)],
            [
                new FormSection('personal', 'Personal', null, [
                    ['kind' => 'field', 'key' => 'missing_field_key'],
                ]),
            ]
        );
    }

    // 5. Duplicate section key rejected
    public function test_5_duplicate_section_key_rejected(): void
    {
        $this->expectException(InvalidForm::class);
        new FormDefinition(
            'ncr.test',
            1,
            [new FormField('applicant_name', 'Applicant Name', 'text', true)],
            [
                new FormSection('sec1', 'Section 1', null, [['kind' => 'field', 'key' => 'applicant_name']]),
                new FormSection('sec1', 'Section 1 Duplicate', null, [['kind' => 'field', 'key' => 'applicant_name']]),
            ]
        );
    }

    // 6. Repeatable group valid definition
    public function test_6_repeatable_group_valid_definition(): void
    {
        $group = new RepeatableGroupDefinition('children', 'Children', 'List of children', 1, 4, [
            new FormField('first_name', 'First Name', 'text', true),
            new FormField('birth_date', 'Birth Date', 'date'),
        ]);

        self::assertSame('children', $group->key);
        self::assertSame(1, $group->minItems);
        self::assertSame(4, $group->maxItems);
        self::assertCount(2, $group->fields);
    }

    // 7. Invalid min/max rejected
    public function test_7_invalid_min_max_rejected(): void
    {
        $this->expectException(InvalidForm::class);
        new RepeatableGroupDefinition('children', 'Children', null, 5, 2, [
            new FormField('name', 'Name', 'text'),
        ]);
    }

    // 8. Nested repeatable group rejected (child field cannot be repeatable group or nested group)
    public function test_8_nested_repeatable_group_rejected(): void
    {
        $this->expectException(InvalidForm::class);
        new FormField('nested_group', 'Nested', 'repeatable_group');
    }

    // 9. _item_id reserved from normal field definitions
    public function test_9_item_id_reserved_from_normal_field_definitions(): void
    {
        $this->expectException(InvalidForm::class);
        new FormField('_item_id', 'Reserved ID', 'text');
    }

    // 10. Valid repeatable answers pass
    public function test_10_valid_repeatable_answers_pass(): void
    {
        $form = new FormDefinition(
            'ncr.test',
            1,
            [new FormField('applicant_name', 'Applicant', 'text', true)],
            [],
            [
                new RepeatableGroupDefinition('children', 'Children', null, 1, 5, [
                    new FormField('first_name', 'First Name', 'text', true),
                    new FormField('age', 'Age', 'number'),
                ]),
            ]
        );

        $validator = new FormValidator();
        $input = [
            'applicant_name' => 'John',
            'children' => [
                ['_item_id' => 'item_1', 'first_name' => 'Alice', 'age' => 10],
                ['_item_id' => 'item_2', 'first_name' => 'Bob', 'age' => 8],
            ],
        ];

        $result = $validator->validate($form, $input);
        self::assertTrue($result->valid());
        self::assertCount(2, $result->answers['children']);
        self::assertSame('item_1', $result->answers['children'][0]['_item_id']);
        self::assertSame('Alice', $result->answers['children'][0]['first_name']);
    }

    // 11. Missing _item_id behavior according to generation boundary
    public function test_11_missing_item_id_assigned_by_normalizer_and_rejected_by_validator_if_raw(): void
    {
        $form = new FormDefinition(
            'ncr.test',
            1,
            [new FormField('applicant', 'Applicant', 'text', true)],
            [],
            [
                new RepeatableGroupDefinition('children', 'Children', null, 1, 5, [
                    new FormField('first_name', 'First Name', 'text', true),
                ]),
            ]
        );

        $rawInput = [
            'applicant' => 'John',
            'children' => [
                ['first_name' => 'Alice'], // missing _item_id
            ],
        ];

        $validator = new FormValidator();
        $rawResult = $validator->validate($form, $rawInput);
        self::assertFalse($rawResult->valid());
        self::assertArrayHasKey('children.0._item_id', $rawResult->errors);

        $normalized = ItemIdentityNormalizer::ensureItemIds($form, $rawInput, static fn (): string => 'auto_id_123');
        $normalizedResult = $validator->validate($form, $normalized);
        self::assertTrue($normalizedResult->valid());
        self::assertSame('auto_id_123', $normalizedResult->answers['children'][0]['_item_id']);
    }

    // 12. Duplicate _item_id rejected
    public function test_12_duplicate_item_id_rejected(): void
    {
        $form = new FormDefinition(
            'ncr.test',
            1,
            [new FormField('applicant', 'Applicant', 'text', true)],
            [],
            [
                new RepeatableGroupDefinition('children', 'Children', null, 1, 5, [
                    new FormField('first_name', 'First Name', 'text', true),
                ]),
            ]
        );

        $input = [
            'applicant' => 'John',
            'children' => [
                ['_item_id' => 'same_id', 'first_name' => 'Alice'],
                ['_item_id' => 'same_id', 'first_name' => 'Bob'],
            ],
        ];

        $result = (new FormValidator())->validate($form, $input);
        self::assertFalse($result->valid());
        self::assertArrayHasKey('children.same_id._item_id', $result->errors);
    }

    // 13. minItems enforced
    public function test_13_min_items_enforced(): void
    {
        $form = new FormDefinition(
            'ncr.test',
            1,
            [new FormField('applicant', 'Applicant', 'text', true)],
            [],
            [
                new RepeatableGroupDefinition('children', 'Children', null, 2, 5, [
                    new FormField('first_name', 'First Name', 'text', true),
                ]),
            ]
        );

        $input = [
            'applicant' => 'John',
            'children' => [
                ['_item_id' => 'id_1', 'first_name' => 'Alice'],
            ],
        ];

        $result = (new FormValidator())->validate($form, $input);
        self::assertFalse($result->valid());
        self::assertSame('At least 2 item(s) required.', $result->errors['children']);
    }

    // 14. maxItems enforced
    public function test_14_max_items_enforced(): void
    {
        $form = new FormDefinition(
            'ncr.test',
            1,
            [new FormField('applicant', 'Applicant', 'text', true)],
            [],
            [
                new RepeatableGroupDefinition('children', 'Children', null, 1, 2, [
                    new FormField('first_name', 'First Name', 'text', true),
                ]),
            ]
        );

        $input = [
            'applicant' => 'John',
            'children' => [
                ['_item_id' => 'id_1', 'first_name' => 'Alice'],
                ['_item_id' => 'id_2', 'first_name' => 'Bob'],
                ['_item_id' => 'id_3', 'first_name' => 'Charlie'],
            ],
        ];

        $result = (new FormValidator())->validate($form, $input);
        self::assertFalse($result->valid());
        self::assertSame('At most 2 item(s) allowed.', $result->errors['children']);
    }

    // 15. Child required field enforced
    public function test_15_child_required_field_enforced(): void
    {
        $form = new FormDefinition(
            'ncr.test',
            1,
            [new FormField('applicant', 'Applicant', 'text', true)],
            [],
            [
                new RepeatableGroupDefinition('children', 'Children', null, 1, 5, [
                    new FormField('first_name', 'First Name', 'text', true),
                ]),
            ]
        );

        $input = [
            'applicant' => 'John',
            'children' => [
                ['_item_id' => 'id_1'], // missing first_name
            ],
        ];

        $result = (new FormValidator())->validate($form, $input);
        self::assertFalse($result->valid());
        self::assertSame('This field is required.', $result->errors['children.id_1.first_name']);
    }

    // 16. Child field type validation enforced
    public function test_16_child_field_type_validation_enforced(): void
    {
        $form = new FormDefinition(
            'ncr.test',
            1,
            [new FormField('applicant', 'Applicant', 'text', true)],
            [],
            [
                new RepeatableGroupDefinition('children', 'Children', null, 1, 5, [
                    new FormField('age', 'Age', 'number', true, min: 0, max: 100),
                ]),
            ]
        );

        $input = [
            'applicant' => 'John',
            'children' => [
                ['_item_id' => 'id_1', 'age' => 150],
            ],
        ];

        $result = (new FormValidator())->validate($form, $input);
        self::assertFalse($result->valid());
        self::assertSame('Invalid numeric value.', $result->errors['children.id_1.age']);
    }

    // 17. Unknown nested field rejected consistently
    public function test_17_unknown_nested_field_rejected_consistently(): void
    {
        $form = new FormDefinition(
            'ncr.test',
            1,
            [new FormField('applicant', 'Applicant', 'text', true)],
            [],
            [
                new RepeatableGroupDefinition('children', 'Children', null, 1, 5, [
                    new FormField('first_name', 'First Name', 'text', true),
                ]),
            ]
        );

        $input = [
            'applicant' => 'John',
            'children' => [
                ['_item_id' => 'id_1', 'first_name' => 'Alice', 'unexpected_key' => 'hack'],
            ],
        ];

        $result = (new FormValidator())->validate($form, $input);
        self::assertFalse($result->valid());
        self::assertSame('Unknown field.', $result->errors['children.id_1.unexpected_key']);
    }

    // 18. Sibling conditional field evaluated per item
    public function test_18_sibling_conditional_field_evaluated_per_item(): void
    {
        $form = new FormDefinition(
            'ncr.test',
            1,
            [new FormField('applicant', 'Applicant', 'text', true)],
            [],
            [
                new RepeatableGroupDefinition('children', 'Children', null, 1, 5, [
                    new FormField('has_passport', 'Has Passport?', 'checkbox', true),
                    new FormField('passport_number', 'Passport #', 'text', true, visibleWhen: ['field' => 'has_passport', 'equals' => true]),
                ]),
            ]
        );

        $input = [
            'applicant' => 'John',
            'children' => [
                ['_item_id' => 'id_1', 'has_passport' => true, 'passport_number' => 'A1234567'],
                ['_item_id' => 'id_2', 'has_passport' => false],
            ],
        ];

        $result = (new FormValidator())->validate($form, $input);
        self::assertTrue($result->valid());
        self::assertSame('A1234567', $result->answers['children'][0]['passport_number']);
        self::assertArrayNotHasKey('passport_number', $result->answers['children'][1]);
    }

    // 19. One item's condition does not affect another item
    public function test_19_one_items_condition_does_not_affect_another_item(): void
    {
        $form = new FormDefinition(
            'ncr.test',
            1,
            [new FormField('applicant', 'Applicant', 'text', true)],
            [],
            [
                new RepeatableGroupDefinition('children', 'Children', null, 1, 5, [
                    new FormField('has_passport', 'Has Passport?', 'checkbox', true),
                    new FormField('passport_number', 'Passport #', 'text', true, visibleWhen: ['field' => 'has_passport', 'equals' => true]),
                ]),
            ]
        );

        // Item 1 has passport=true, Item 2 has passport=true but missing passport_number
        $input = [
            'applicant' => 'John',
            'children' => [
                ['_item_id' => 'id_1', 'has_passport' => true, 'passport_number' => 'P1001'],
                ['_item_id' => 'id_2', 'has_passport' => true], // missing passport_number for item 2
            ],
        ];

        $result = (new FormValidator())->validate($form, $input);
        self::assertFalse($result->valid());
        self::assertArrayNotHasKey('children.id_1.passport_number', $result->errors);
        self::assertSame('This field is required.', $result->errors['children.id_2.passport_number']);
    }

    // 20. Reordered items preserve stable identity semantics
    public function test_20_reordered_items_preserve_stable_identity_semantics(): void
    {
        $form = new FormDefinition(
            'ncr.test',
            1,
            [new FormField('applicant', 'Applicant', 'text', true)],
            [],
            [
                new RepeatableGroupDefinition('children', 'Children', null, 1, 5, [
                    new FormField('first_name', 'First Name', 'text', true),
                ]),
            ]
        );

        $input1 = [
            'applicant' => 'John',
            'children' => [
                ['_item_id' => 'child_A', 'first_name' => 'Alice'],
                ['_item_id' => 'child_B', 'first_name' => 'Bob'],
            ],
        ];

        $input2 = [
            'applicant' => 'John',
            'children' => [
                ['_item_id' => 'child_B', 'first_name' => 'Bob'],
                ['_item_id' => 'child_A', 'first_name' => 'Alice'],
            ],
        ];

        $validator = new FormValidator();
        $res1 = $validator->validate($form, $input1);
        $res2 = $validator->validate($form, $input2);

        self::assertTrue($res1->valid());
        self::assertTrue($res2->valid());

        self::assertSame('child_A', $res1->answers['children'][0]['_item_id']);
        self::assertSame('child_B', $res1->answers['children'][1]['_item_id']);

        self::assertSame('child_B', $res2->answers['children'][0]['_item_id']);
        self::assertSame('child_A', $res2->answers['children'][1]['_item_id']);
    }

    // 21. Reserved and malformed _item_id values are rejected
    public function test_21_reserved_and_malformed_item_ids_rejected(): void
    {
        $form = new FormDefinition(
            'ncr.test',
            1,
            [new FormField('applicant', 'Applicant', 'text', true)],
            [],
            [
                new RepeatableGroupDefinition('children', 'Children', null, 1, 5, [
                    new FormField('first_name', 'First Name', 'text', true),
                ]),
            ]
        );

        $validator = new FormValidator();

        $rejectedIds = ['_add', '_remove', '*', 'a.b', 'children..name', ''];
        foreach ($rejectedIds as $badId) {
            $input = [
                'applicant' => 'John',
                'children' => [
                    ['_item_id' => $badId, 'first_name' => 'Alice'],
                ],
            ];
            $res = $validator->validate($form, $input);
            self::assertFalse($res->valid(), "Expected _item_id = '{$badId}' to be rejected");
            self::assertArrayHasKey('children.0._item_id', $res->errors);
        }

        $acceptedIds = ['child_A', '01J9X9P78W9M5Z1K4Q8V2R3T9P', 'child_additional', 'remove_old', 'item-123_abc'];
        foreach ($acceptedIds as $goodId) {
            $input = [
                'applicant' => 'John',
                'children' => [
                    ['_item_id' => $goodId, 'first_name' => 'Alice'],
                ],
            ];
            $res = $validator->validate($form, $input);
            self::assertTrue($res->valid(), "Expected _item_id = '{$goodId}' to be accepted");
            self::assertSame($goodId, $res->answers['children'][0]['_item_id']);
        }
    }
}
