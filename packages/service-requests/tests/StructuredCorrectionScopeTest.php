<?php

declare(strict_types=1);

namespace Intl\ServiceRequests\Tests;

use Intl\ServiceRequests\Policies\CorrectionScope;
use PHPUnit\Framework\TestCase;

final class StructuredCorrectionScopeTest extends TestCase
{
    // 1. Legacy flat field correction still passes
    public function test_1_legacy_flat_field_correction_passes(): void
    {
        $scope = new CorrectionScope('limited', ['email', 'phone'], []);
        $scope->assertAnswers(
            ['email' => 'old@example.com', 'phone' => '123', 'address' => 'City'],
            ['email' => 'new@example.com', 'phone' => '456', 'address' => 'City']
        );
        self::assertTrue(true);
    }

    // 2. Unauthorized flat field still fails
    public function test_2_unauthorized_flat_field_fails(): void
    {
        $scope = new CorrectionScope('limited', ['email'], []);
        $this->expectException(\DomainException::class);
        $scope->assertAnswers(
            ['email' => 'old@example.com', 'phone' => '123'],
            ['email' => 'new@example.com', 'phone' => '456']
        );
    }

    // 3. Wildcard child-field edit passes
    public function test_3_wildcard_child_field_edit_passes(): void
    {
        $scope = new CorrectionScope('limited', ['children.*.national_id'], []);
        $prev = [
            'children' => [
                ['_item_id' => 'child_A', 'name' => 'Sara', 'national_id' => '111'],
                ['_item_id' => 'child_B', 'name' => 'Ali', 'national_id' => '222'],
            ],
        ];
        $next = [
            'children' => [
                ['_item_id' => 'child_A', 'name' => 'Sara', 'national_id' => '111-updated'],
                ['_item_id' => 'child_B', 'name' => 'Ali', 'national_id' => '222-updated'],
            ],
        ];
        $scope->assertAnswers($prev, $next);
        self::assertTrue(true);
    }

    // 4. Exact-item child-field edit passes
    public function test_4_exact_item_child_field_edit_passes(): void
    {
        $scope = new CorrectionScope('limited', ['children.child_A.national_id'], []);
        $prev = [
            'children' => [
                ['_item_id' => 'child_A', 'name' => 'Sara', 'national_id' => '111'],
                ['_item_id' => 'child_B', 'name' => 'Ali', 'national_id' => '222'],
            ],
        ];
        $next = [
            'children' => [
                ['_item_id' => 'child_A', 'name' => 'Sara', 'national_id' => '111-updated'],
                ['_item_id' => 'child_B', 'name' => 'Ali', 'national_id' => '222'], // unchanged
            ],
        ];
        $scope->assertAnswers($prev, $next);
        self::assertTrue(true);
    }

    // 5. Exact-item path cannot modify another item
    public function test_5_exact_item_path_cannot_modify_another_item(): void
    {
        $scope = new CorrectionScope('limited', ['children.child_A.national_id'], []);
        $prev = [
            'children' => [
                ['_item_id' => 'child_A', 'name' => 'Sara', 'national_id' => '111'],
                ['_item_id' => 'child_B', 'name' => 'Ali', 'national_id' => '222'],
            ],
        ];
        $next = [
            'children' => [
                ['_item_id' => 'child_A', 'name' => 'Sara', 'national_id' => '111'],
                ['_item_id' => 'child_B', 'name' => 'Ali', 'national_id' => '222-updated'], // modified child_B!
            ],
        ];
        $this->expectException(\DomainException::class);
        $scope->assertAnswers($prev, $next);
    }

    // 6. Wildcard child edit does not authorize another child field
    public function test_6_wildcard_child_edit_does_not_authorize_another_child_field(): void
    {
        $scope = new CorrectionScope('limited', ['children.*.national_id'], []);
        $prev = [
            'children' => [
                ['_item_id' => 'child_A', 'name' => 'Sara', 'national_id' => '111'],
            ],
        ];
        $next = [
            'children' => [
                ['_item_id' => 'child_A', 'name' => 'Sara-Renamed', 'national_id' => '111-updated'],
            ],
        ];
        $this->expectException(\DomainException::class);
        $scope->assertAnswers($prev, $next);
    }

    // 7. Reorder only creates no semantic change
    public function test_7_reorder_only_creates_no_semantic_change(): void
    {
        $scope = new CorrectionScope('limited', ['email'], []); // no children permissions!
        $prev = [
            'children' => [
                ['_item_id' => 'child_A', 'name' => 'Sara'],
                ['_item_id' => 'child_B', 'name' => 'Ali'],
            ],
        ];
        $next = [
            'children' => [
                ['_item_id' => 'child_B', 'name' => 'Ali'],
                ['_item_id' => 'child_A', 'name' => 'Sara'],
            ],
        ];
        $scope->assertAnswers($prev, $next);
        self::assertTrue(true);
    }

    // 8. Reorder + authorized field edit passes
    public function test_8_reorder_plus_authorized_field_edit_passes(): void
    {
        $scope = new CorrectionScope('limited', ['children.*.national_id'], []);
        $prev = [
            'children' => [
                ['_item_id' => 'child_A', 'national_id' => '111'],
                ['_item_id' => 'child_B', 'national_id' => '222'],
            ],
        ];
        $next = [
            'children' => [
                ['_item_id' => 'child_B', 'national_id' => '222-new'],
                ['_item_id' => 'child_A', 'national_id' => '111-new'],
            ],
        ];
        $scope->assertAnswers($prev, $next);
        self::assertTrue(true);
    }

    // 9. Reorder + unauthorized field edit fails
    public function test_9_reorder_plus_unauthorized_field_edit_fails(): void
    {
        $scope = new CorrectionScope('limited', ['children.*.national_id'], []);
        $prev = [
            'children' => [
                ['_item_id' => 'child_A', 'name' => 'Sara', 'national_id' => '111'],
                ['_item_id' => 'child_B', 'name' => 'Ali', 'national_id' => '222'],
            ],
        ];
        $next = [
            'children' => [
                ['_item_id' => 'child_B', 'name' => 'Ali-Renamed', 'national_id' => '222'],
                ['_item_id' => 'child_A', 'name' => 'Sara', 'national_id' => '111'],
            ],
        ];
        $this->expectException(\DomainException::class);
        $scope->assertAnswers($prev, $next);
    }

    // 10. Add without _add fails
    public function test_10_add_without_add_permission_fails(): void
    {
        $scope = new CorrectionScope('limited', ['children.*.national_id'], []);
        $prev = [
            'children' => [
                ['_item_id' => 'child_A', 'national_id' => '111'],
            ],
        ];
        $next = [
            'children' => [
                ['_item_id' => 'child_A', 'national_id' => '111'],
                ['_item_id' => 'child_B', 'national_id' => '222'], // new item B
            ],
        ];
        $this->expectException(\DomainException::class);
        $scope->assertAnswers($prev, $next);
    }

    // 11. Add with _add passes
    public function test_11_add_with_add_permission_passes(): void
    {
        $scope = new CorrectionScope('limited', ['children._add'], []);
        $prev = [
            'children' => [
                ['_item_id' => 'child_A', 'name' => 'Sara'],
            ],
        ];
        $next = [
            'children' => [
                ['_item_id' => 'child_A', 'name' => 'Sara'],
                ['_item_id' => 'child_B', 'name' => 'Ali'],
            ],
        ];
        $scope->assertAnswers($prev, $next);
        self::assertTrue(true);
    }

    // 12. _add does not authorize existing-item edits
    public function test_12_add_does_not_authorize_existing_item_edits(): void
    {
        $scope = new CorrectionScope('limited', ['children._add'], []);
        $prev = [
            'children' => [
                ['_item_id' => 'child_A', 'name' => 'Sara'],
            ],
        ];
        $next = [
            'children' => [
                ['_item_id' => 'child_A', 'name' => 'Sara-Edited'],
                ['_item_id' => 'child_B', 'name' => 'Ali'],
            ],
        ];
        $this->expectException(\DomainException::class);
        $scope->assertAnswers($prev, $next);
    }

    // 13. Remove exact item with exact _remove passes
    public function test_13_remove_exact_item_with_exact_remove_passes(): void
    {
        $scope = new CorrectionScope('limited', ['children.child_A._remove'], []);
        $prev = [
            'children' => [
                ['_item_id' => 'child_A', 'name' => 'Sara'],
                ['_item_id' => 'child_B', 'name' => 'Ali'],
            ],
        ];
        $next = [
            'children' => [
                ['_item_id' => 'child_B', 'name' => 'Ali'],
            ],
        ];
        $scope->assertAnswers($prev, $next);
        self::assertTrue(true);
    }

    // 14. Exact remove cannot remove another item
    public function test_14_exact_remove_cannot_remove_another_item(): void
    {
        $scope = new CorrectionScope('limited', ['children.child_A._remove'], []);
        $prev = [
            'children' => [
                ['_item_id' => 'child_A', 'name' => 'Sara'],
                ['_item_id' => 'child_B', 'name' => 'Ali'],
            ],
        ];
        $next = [
            'children' => [
                ['_item_id' => 'child_A', 'name' => 'Sara'], // child_B removed instead!
            ],
        ];
        $this->expectException(\DomainException::class);
        $scope->assertAnswers($prev, $next);
    }

    // 15. Wildcard _remove works
    public function test_15_wildcard_remove_works(): void
    {
        $scope = new CorrectionScope('limited', ['children.*._remove'], []);
        $prev = [
            'children' => [
                ['_item_id' => 'child_A', 'name' => 'Sara'],
                ['_item_id' => 'child_B', 'name' => 'Ali'],
            ],
        ];
        $next = [
            'children' => [
                ['_item_id' => 'child_B', 'name' => 'Ali'],
            ],
        ];
        $scope->assertAnswers($prev, $next);
        self::assertTrue(true);
    }

    // 16. Field permission does not authorize remove
    public function test_16_field_permission_does_not_authorize_remove(): void
    {
        $scope = new CorrectionScope('limited', ['children.*.name'], []);
        $prev = [
            'children' => [
                ['_item_id' => 'child_A', 'name' => 'Sara'],
                ['_item_id' => 'child_B', 'name' => 'Ali'],
            ],
        ];
        $next = [
            'children' => [
                ['_item_id' => 'child_A', 'name' => 'Sara'],
            ],
        ];
        $this->expectException(\DomainException::class);
        $scope->assertAnswers($prev, $next);
    }

    // 17. Changing _item_id requires add + remove
    public function test_17_changing_item_id_requires_add_and_remove(): void
    {
        $scopeFieldOnly = new CorrectionScope('limited', ['children.*.name'], []);
        $prev = [
            'children' => [
                ['_item_id' => 'child_A', 'name' => 'Sara'],
            ],
        ];
        $next = [
            'children' => [
                ['_item_id' => 'child_B', 'name' => 'Sara'], // item A replaced by item B
            ],
        ];
        try {
            $scopeFieldOnly->assertAnswers($prev, $next);
            self::fail('Changing item_id should be treated as remove A + add B');
        } catch (\DomainException) {
            self::assertTrue(true);
        }

        $scopeWithAddRemove = new CorrectionScope('limited', ['children.child_A._remove', 'children._add'], []);
        $scopeWithAddRemove->assertAnswers($prev, $next);
        self::assertTrue(true);
    }

    // 18. Malformed duplicate item IDs fail closed
    public function test_18_malformed_duplicate_item_ids_fail_closed(): void
    {
        $scope = new CorrectionScope('limited', ['children.*.name'], []);
        $prev = [
            'children' => [
                ['_item_id' => 'dup', 'name' => 'Sara'],
                ['_item_id' => 'dup', 'name' => 'Ali'],
            ],
        ];
        $next = [
            'children' => [
                ['_item_id' => 'dup', 'name' => 'Sara'],
            ],
        ];
        $this->expectException(\DomainException::class);
        $scope->assertAnswers($prev, $next);
    }

    // 19. Missing item ID fails closed
    public function test_19_missing_item_id_fails_closed(): void
    {
        $scope = new CorrectionScope('limited', ['children.*.name'], []);
        $prev = [
            'children' => [
                ['name' => 'Sara'], // missing _item_id
            ],
        ];
        $next = [
            'children' => [
                ['name' => 'Sara'],
            ],
        ];
        $this->expectException(\DomainException::class);
        $scope->assertAnswers($prev, $next);
    }

    // 20. Mixed fully authorized operation set passes
    public function test_20_mixed_fully_authorized_operation_set_passes(): void
    {
        $scope = new CorrectionScope('limited', [
            'email',
            'children.*.national_id',
            'children._add',
            'children.child_A._remove',
        ], []);

        $prev = [
            'email' => 'old@example.com',
            'children' => [
                ['_item_id' => 'child_A', 'name' => 'Sara', 'national_id' => '111'],
                ['_item_id' => 'child_B', 'name' => 'Ali', 'national_id' => '222'],
            ],
        ];

        $next = [
            'email' => 'new@example.com', // edit email
            'children' => [
                ['_item_id' => 'child_B', 'name' => 'Ali', 'national_id' => '222-new'], // update national_id
                ['_item_id' => 'child_C', 'name' => 'Charlie', 'national_id' => '333'], // add child_C
            ], // child_A removed
        ];

        $scope->assertAnswers($prev, $next);
        self::assertTrue(true);
    }

    // 21. Mixed operation with one unauthorized change fails entirely
    public function test_21_mixed_operation_with_one_unauthorized_change_fails_entirely(): void
    {
        $scope = new CorrectionScope('limited', [
            'email',
            'children.*.national_id',
            'children._add',
            'children.child_A._remove',
        ], []);

        $prev = [
            'email' => 'old@example.com',
            'children' => [
                ['_item_id' => 'child_A', 'name' => 'Sara', 'national_id' => '111'],
                ['_item_id' => 'child_B', 'name' => 'Ali', 'national_id' => '222'],
            ],
        ];

        $next = [
            'email' => 'new@example.com',
            'children' => [
                ['_item_id' => 'child_B', 'name' => 'Ali-Renamed', 'national_id' => '222-new'], // name edit NOT allowed!
                ['_item_id' => 'child_C', 'name' => 'Charlie', 'national_id' => '333'],
            ],
        ];

        $this->expectException(\DomainException::class);
        $scope->assertAnswers($prev, $next);
    }

    // 22. Malformed correction path rejected
    public function test_22_malformed_correction_path_rejected(): void
    {
        $malformedPaths = [
            'children..national_id',
            'children.*.*',
            'children._add.national_id',
            'children.foo._add',
            'children.*._add',
            'children.a.b.c',
            '*',
        ];

        foreach ($malformedPaths as $badPath) {
            try {
                new CorrectionScope('limited', [$badPath], []);
                self::fail("Malformed path '{$badPath}' should have been rejected.");
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    // 23. Reserved/path-unsafe item IDs handled according to safety strategy
    public function test_23_reserved_path_unsafe_item_ids_handled_safely(): void
    {
        $scope = new CorrectionScope('limited', ['children.validId._remove'], []);
        $prev = [
            'children' => [
                ['_item_id' => '_add', 'name' => 'Unsafe'],
            ],
        ];
        $next = [
            'children' => [],
        ];
        $this->expectException(\DomainException::class);
        $scope->assertAnswers($prev, $next);
    }

    // 24. Full correction remains unrestricted according to existing semantics
    public function test_24_full_correction_remains_unrestricted(): void
    {
        $scope = new CorrectionScope('full', [], []);
        $prev = [
            'email' => 'old@example.com',
            'children' => [
                ['_item_id' => 'child_A', 'name' => 'Sara'],
            ],
        ];
        $next = [
            'email' => 'any@example.com',
            'children' => [
                ['_item_id' => 'child_B', 'name' => 'Replaced Completely'],
            ],
        ];
        $scope->assertAnswers($prev, $next);
        self::assertTrue(true);
    }

    // 25. Document slots existing behavior remains unchanged
    public function test_25_document_slots_existing_behavior_remains_unchanged(): void
    {
        $scope = new CorrectionScope('limited', ['email'], ['passport', 'national_id']);
        $scope->assertDocumentSlot('passport');
        $scope->assertDocumentSlot('national_id');

        $this->expectException(\DomainException::class);
        $scope->assertDocumentSlot('birth_certificate');
    }

    // 26. Explicit correction parser semantics for _add and _remove
    public function test_26_correction_parser_semantics(): void
    {
        $addPath = \Intl\ServiceRequests\Policies\CorrectionPath::parse('children._add');
        self::assertSame(\Intl\ServiceRequests\Policies\CorrectionPath::KIND_GROUP_ADD, $addPath->kind);
        self::assertSame('children', $addPath->group);
        self::assertNull($addPath->target);
        self::assertNull($addPath->field);

        $removePath = \Intl\ServiceRequests\Policies\CorrectionPath::parse('children.child_A._remove');
        self::assertSame(\Intl\ServiceRequests\Policies\CorrectionPath::KIND_GROUP_REMOVE, $removePath->kind);
        self::assertSame('children', $removePath->group);
        self::assertSame('child_A', $removePath->target);
        self::assertNull($removePath->field);

        $wildcardRemove = \Intl\ServiceRequests\Policies\CorrectionPath::parse('children.*._remove');
        self::assertSame(\Intl\ServiceRequests\Policies\CorrectionPath::KIND_GROUP_REMOVE, $wildcardRemove->kind);
        self::assertSame('children', $wildcardRemove->group);
        self::assertSame('*', $wildcardRemove->target);
        self::assertNull($wildcardRemove->field);
    }
}
