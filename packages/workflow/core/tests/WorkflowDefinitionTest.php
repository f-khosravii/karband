<?php

declare(strict_types=1);

namespace Karband\Workflow\Tests;

use Karband\Workflow\Definitions\Transition;
use Karband\Workflow\Definitions\WorkflowDefinition;
use Karband\Workflow\Exceptions\InvalidWorkflowDefinition;
use PHPUnit\Framework\TestCase;

final class WorkflowDefinitionTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function config(): array
    {
        return [
            'id' => 'sample.service',
            'version' => 1,
            'initial_state' => 'draft',
            'states' => ['draft', 'submitted', 'approved'],
            'transitions' => [
                [
                    'action' => 'submit',
                    'from' => 'draft',
                    'to' => 'submitted',
                    'allowed_roles' => ['agency'],
                ],
                [
                    'action' => 'approve',
                    'from' => 'submitted',
                    'to' => 'approved',
                    'allowed_roles' => ['government_admin'],
                    'comment_required' => true,
                    'guards' => ['review_complete'],
                ],
            ],
        ];
    }

    public function test_loading_a_service_specific_versioned_definition(): void
    {
        $definition = WorkflowDefinition::fromArray(self::config());
        self::assertSame('sample.service', $definition->id);
        self::assertSame(1, $definition->version);
        self::assertSame('draft', $definition->initialState);
        self::assertTrue($definition->containsState('approved'));
        self::assertFalse($definition->containsState('unknown'));
        self::assertSame('submitted', $definition->findTransition('draft', 'submit')?->to);
        self::assertNull($definition->findTransition('draft', 'approve'));
    }

    public function test_json_and_array_definitions_behave_the_same(): void
    {
        $json = json_encode(self::config(), JSON_THROW_ON_ERROR);
        $definition = WorkflowDefinition::fromJson($json);
        self::assertSame('sample.service', $definition->id);
        self::assertSame(['review_complete'], $definition->findTransition('submitted', 'approve')?->guardKeys);
        self::assertTrue($definition->findTransition('submitted', 'approve')?->commentRequired);
    }

    public function test_independent_versions_have_independent_transition_sets(): void
    {
        $v1 = WorkflowDefinition::fromArray(self::config());
        $v2 = self::config();
        $v2['version'] = 2;
        $v2['transitions'][] = [
            'action' => 'withdraw', 'from' => 'draft', 'to' => 'approved', 'allowed_roles' => ['agency'],
        ];
        $v2 = WorkflowDefinition::fromArray($v2);
        self::assertSame(1, $v1->version);
        self::assertSame(2, $v2->version);
        self::assertNull($v1->findTransition('draft', 'withdraw'));
        self::assertNotNull($v2->findTransition('draft', 'withdraw'));
    }

    public function test_duplicate_state_is_rejected(): void
    {
        $data = self::config();
        $data['states'][] = 'draft';
        $this->expectException(InvalidWorkflowDefinition::class);
        WorkflowDefinition::fromArray($data);
    }

    public function test_unknown_initial_state_is_rejected(): void
    {
        $data = self::config();
        $data['initial_state'] = 'missing';
        $this->expectException(InvalidWorkflowDefinition::class);
        WorkflowDefinition::fromArray($data);
    }

    public function test_unknown_target_state_is_rejected(): void
    {
        $data = self::config();
        $data['transitions'][0]['to'] = 'missing';
        $this->expectException(InvalidWorkflowDefinition::class);
        WorkflowDefinition::fromArray($data);
    }

    public function test_same_action_from_same_state_cannot_have_role_specific_destinations(): void
    {
        $data = self::config();
        $data['transitions'][] = [
            'action' => 'submit', 'from' => 'draft', 'to' => 'approved', 'allowed_roles' => ['platform_admin'],
        ];
        $this->expectException(InvalidWorkflowDefinition::class);
        WorkflowDefinition::fromArray($data);
    }

    public function test_same_action_from_different_states_is_allowed(): void
    {
        $data = self::config();
        $data['transitions'][] = [
            'action' => 'submit', 'from' => 'submitted', 'to' => 'approved', 'allowed_roles' => ['agency'],
        ];
        $definition = WorkflowDefinition::fromArray($data);
        self::assertSame('approved', $definition->findTransition('submitted', 'submit')?->to);
    }

    public function test_duplicate_allowed_roles_are_rejected(): void
    {
        $data = self::config();
        $data['transitions'][0]['allowed_roles'] = ['agency', 'agency'];
        $this->expectException(InvalidWorkflowDefinition::class);
        WorkflowDefinition::fromArray($data);
    }

    public function test_duplicate_guard_keys_are_rejected(): void
    {
        $data = self::config();
        $data['transitions'][1]['guards'] = ['ready', 'ready'];
        $this->expectException(InvalidWorkflowDefinition::class);
        WorkflowDefinition::fromArray($data);
    }

    public function test_version_zero_is_rejected(): void
    {
        $data = self::config();
        $data['version'] = 0;
        $this->expectException(InvalidWorkflowDefinition::class);
        WorkflowDefinition::fromArray($data);
    }

    public function test_boolean_version_is_rejected_instead_of_coerced(): void
    {
        $data = self::config();
        $data['version'] = true;
        $this->expectException(InvalidWorkflowDefinition::class);
        WorkflowDefinition::fromArray($data);
    }

    public function test_unknown_top_level_field_is_rejected(): void
    {
        $data = self::config();
        $data['initialStatus'] = 'draft';
        $this->expectException(InvalidWorkflowDefinition::class);
        WorkflowDefinition::fromArray($data);
    }

    public function test_unknown_transition_field_is_rejected(): void
    {
        $data = self::config();
        $data['transitions'][0]['allowed_role'] = ['agency'];
        $this->expectException(InvalidWorkflowDefinition::class);
        WorkflowDefinition::fromArray($data);
    }

    public function test_wrong_comment_required_type_is_rejected(): void
    {
        $data = self::config();
        $data['transitions'][0]['comment_required'] = 'true';
        $this->expectException(InvalidWorkflowDefinition::class);
        WorkflowDefinition::fromArray($data);
    }

    public function test_invalid_json_is_rejected_with_specific_exception(): void
    {
        $this->expectException(InvalidWorkflowDefinition::class);
        WorkflowDefinition::fromJson('{invalid');
    }

    public function test_root_json_list_is_rejected(): void
    {
        $this->expectException(InvalidWorkflowDefinition::class);
        WorkflowDefinition::fromJson('[]');
    }

    public function test_transition_identifiers_cannot_be_blank(): void
    {
        $this->expectException(InvalidWorkflowDefinition::class);
        new Transition(' ', 'draft', 'submitted', ['agency']);
    }

    public function test_transition_rejects_empty_role_list(): void
    {
        $this->expectException(InvalidWorkflowDefinition::class);
        new Transition('submit', 'draft', 'submitted', []);
    }

    public function test_direct_constructor_rejects_non_transition_entries(): void
    {
        $this->expectException(InvalidWorkflowDefinition::class);
        new WorkflowDefinition('sample.service', 1, 'draft', ['draft'], ['not-a-transition']);
    }
}
