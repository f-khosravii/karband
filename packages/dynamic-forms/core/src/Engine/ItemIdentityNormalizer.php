<?php

declare(strict_types=1);

namespace Karband\DynamicForms\Engine;

use Karband\DynamicForms\Definitions\FormDefinition;

/** Pre-validation helper to ensure new repeatable items have stable _item_id identifiers. */
final readonly class ItemIdentityNormalizer
{
    /**
     * @param array<string,mixed> $input
     * @param (callable(): string)|null $idGenerator
     * @return array<string,mixed>
     */
    public static function ensureItemIds(FormDefinition $form, array $input, ?callable $idGenerator = null): array
    {
        if ($form->repeatableGroups === []) {
            return $input;
        }

        $idGenerator ??= static fn (): string => bin2hex(random_bytes(13));

        foreach ($form->repeatableGroups as $group) {
            if (isset($input[$group->key]) && is_array($input[$group->key]) && array_is_list($input[$group->key])) {
                foreach ($input[$group->key] as $idx => $item) {
                    if (is_array($item) && !array_is_list($item)) {
                        if (!isset($item['_item_id']) || !is_string($item['_item_id']) || trim($item['_item_id']) === '') {
                            $input[$group->key][$idx]['_item_id'] = $idGenerator();
                        }
                    }
                }
            }
        }

        return $input;
    }
}
