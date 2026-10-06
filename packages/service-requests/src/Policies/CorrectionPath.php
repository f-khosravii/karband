<?php

declare(strict_types=1);

namespace Intl\ServiceRequests\Policies;

/** Value object representing a parsed structured correction permission path. */
final readonly class CorrectionPath
{
    public const KIND_FLAT_FIELD = 'flat_field';
    public const KIND_GROUP_ADD = 'group_add';
    public const KIND_GROUP_REMOVE = 'group_remove';
    public const KIND_CHILD_FIELD = 'child_field';

    public function __construct(
        public string $raw,
        public string $kind,
        public ?string $group = null,
        public ?string $target = null, // '*' or item_id or null
        public ?string $field = null,  // top-level field name or child field name
    ) {
    }

    public static function parse(string $path): self
    {
        if ($path === '' || strlen($path) > 120) {
            throw new \InvalidArgumentException("Invalid correction path length: {$path}");
        }

        $parts = explode('.', $path);
        $count = count($parts);

        foreach ($parts as $part) {
            if ($part === '') {
                throw new \InvalidArgumentException("Invalid correction path segment in: {$path}");
            }
        }

        $keyRegex = '/\A[a-z][a-z0-9_]{0,63}\z/D';
        $itemIdRegex = '/\A[a-zA-Z0-9_-]{1,64}\z/D';

        if ($count === 1) {
            $field = $parts[0];
            if (!preg_match($keyRegex, $field) || $field === '*' || $field === '_add' || $field === '_remove') {
                throw new \InvalidArgumentException("Invalid flat field name in correction path: {$path}");
            }
            return new self($path, self::KIND_FLAT_FIELD, field: $field);
        }

        if ($count === 2) {
            [$group, $action] = $parts;
            if (!preg_match($keyRegex, $group)) {
                throw new \InvalidArgumentException("Invalid group key in correction path: {$path}");
            }
            if ($action === '_add') {
                return new self($path, self::KIND_GROUP_ADD, group: $group);
            }
            throw new \InvalidArgumentException("Invalid 2-segment correction path action: {$path}");
        }

        if ($count === 3) {
            [$group, $target, $actionOrField] = $parts;
            if (!preg_match($keyRegex, $group)) {
                throw new \InvalidArgumentException("Invalid group key in correction path: {$path}");
            }

            if ($target === '_add' || $target === '_remove') {
                throw new \InvalidArgumentException("Target cannot be reserved operation in correction path: {$path}");
            }

            if ($target !== '*' && !preg_match($itemIdRegex, $target)) {
                throw new \InvalidArgumentException("Invalid target item ID in correction path: {$path}");
            }

            if ($actionOrField === '_remove') {
                return new self($path, self::KIND_GROUP_REMOVE, group: $group, target: $target);
            }

            if ($actionOrField === '*' || $actionOrField === '_add' || !preg_match($keyRegex, $actionOrField)) {
                throw new \InvalidArgumentException("Invalid child field name in correction path: {$path}");
            }

            return new self($path, self::KIND_CHILD_FIELD, group: $group, target: $target, field: $actionOrField);
        }

        throw new \InvalidArgumentException("Correction path has too many segments: {$path}");
    }
}
