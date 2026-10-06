<?php

declare(strict_types=1);

namespace Karband\Workflow\Support;

use Karband\Workflow\Definitions\Transition;
use Karband\Workflow\Definitions\WorkflowDefinition;
use Karband\Workflow\Exceptions\InvalidWorkflowDefinition;

/** Strict, side-effect-free import of a definition from code or JSON. */
final class DefinitionLoader
{
    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): WorkflowDefinition
    {
        self::checkKeys($data, ['id', 'version', 'initial_state', 'states', 'transitions'], [], 'Workflow');
        self::requireString($data, 'id', 'Workflow');
        self::requireString($data, 'initial_state', 'Workflow');
        if (!isset($data['version']) || !is_int($data['version'])) {
            throw new InvalidWorkflowDefinition('Workflow version must be an integer.');
        }
        if (!isset($data['states']) || !is_array($data['states']) || !array_is_list($data['states'])) {
            throw new InvalidWorkflowDefinition('Workflow states must be a list.');
        }
        if (!isset($data['transitions']) || !is_array($data['transitions']) || !array_is_list($data['transitions'])) {
            throw new InvalidWorkflowDefinition('Workflow transitions must be a list.');
        }

        $transitions = [];
        foreach ($data['transitions'] as $index => $raw) {
            if (!is_array($raw) || array_is_list($raw)) {
                throw new InvalidWorkflowDefinition('Transition at index ' . $index . ' must be an object.');
            }
            $where = 'Transition ' . $index;
            self::checkKeys(
                $raw,
                ['action', 'from', 'to', 'allowed_roles'],
                ['comment_required', 'guards'],
                $where,
            );
            foreach (['action', 'from', 'to'] as $field) {
                self::requireString($raw, $field, $where);
            }
            if (!isset($raw['allowed_roles']) || !is_array($raw['allowed_roles']) || !array_is_list($raw['allowed_roles'])) {
                throw new InvalidWorkflowDefinition($where . ' allowed_roles must be a list.');
            }
            if (array_key_exists('comment_required', $raw) && !is_bool($raw['comment_required'])) {
                throw new InvalidWorkflowDefinition($where . ' comment_required must be a boolean.');
            }
            if (array_key_exists('guards', $raw) && (!is_array($raw['guards']) || !array_is_list($raw['guards']))) {
                throw new InvalidWorkflowDefinition($where . ' guards must be a list.');
            }
            $transitions[] = new Transition(
                action: $raw['action'],
                from: $raw['from'],
                to: $raw['to'],
                allowedRoles: $raw['allowed_roles'],
                commentRequired: $raw['comment_required'] ?? false,
                guardKeys: $raw['guards'] ?? [],
            );
        }

        return new WorkflowDefinition(
            id: $data['id'],
            version: $data['version'],
            initialState: $data['initial_state'],
            states: $data['states'],
            transitions: $transitions,
        );
    }

    public static function fromJson(string $json): WorkflowDefinition
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidWorkflowDefinition('Invalid workflow JSON: ' . $exception->getMessage(), 0, $exception);
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new InvalidWorkflowDefinition('Workflow JSON must be an object.');
        }
        return self::fromArray($data);
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $required
     * @param list<string> $optional
     */
    private static function checkKeys(array $data, array $required, array $optional, string $where): void
    {
        $known = array_merge($required, $optional);
        foreach ($required as $field) {
            if (!array_key_exists($field, $data)) {
                throw new InvalidWorkflowDefinition($where . ' missing field: ' . $field);
            }
        }
        foreach (array_keys($data) as $field) {
            if (!in_array($field, $known, true)) {
                throw new InvalidWorkflowDefinition($where . ' unknown field: ' . $field);
            }
        }
    }

    /** @param array<string, mixed> $data */
    private static function requireString(array $data, string $field, string $where): void
    {
        if (!isset($data[$field]) || !is_string($data[$field])) {
            throw new InvalidWorkflowDefinition($where . ' ' . $field . ' must be a string.');
        }
    }

    private function __construct()
    {
    }
}
