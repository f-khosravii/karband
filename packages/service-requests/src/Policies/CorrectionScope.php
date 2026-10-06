<?php

declare(strict_types=1);

namespace Intl\ServiceRequests\Policies;

/** One correction round; validates a server-defined allowlist, never a client-selected one. */
final readonly class CorrectionScope
{
    /** @var list<CorrectionPath> */
    public array $parsedPaths;

    /** @param list<string> $fields @param list<string> $documentSlots */
    public function __construct(public string $mode, public array $fields, public array $documentSlots)
    {
        if (!in_array($mode, ['full', 'limited'], true)) {
            throw new \InvalidArgumentException('Unknown correction mode.');
        }
        if ($mode === 'full' && ($fields !== [] || $documentSlots !== [])) {
            throw new \InvalidArgumentException('Full correction cannot include a limited allowlist.');
        }
        if (!array_is_list($fields) || count($fields) !== count(array_unique($fields))) {
            throw new \InvalidArgumentException('Correction allowlists must be unique lists.');
        }
        if (!array_is_list($documentSlots) || count($documentSlots) !== count(array_unique($documentSlots))) {
            throw new \InvalidArgumentException('Correction allowlists must be unique lists.');
        }

        $parsed = [];
        foreach ($fields as $pathStr) {
            $parsed[] = CorrectionPath::parse($pathStr);
        }
        $this->parsedPaths = $parsed;

        foreach ($documentSlots as $slot) {
            if (!is_string($slot) || !preg_match('/\A[a-z][a-z0-9_.-]{0,119}\z/D', $slot)) {
                throw new \InvalidArgumentException('Invalid correction field or slot.');
            }
        }

        if ($mode === 'limited' && $fields === [] && $documentSlots === []) {
            throw new \InvalidArgumentException('Limited correction needs a field or a document slot.');
        }
    }

    /** @param array<string,mixed> $previous @param array<string,mixed> $next */
    public function assertAnswers(array $previous, array $next): void
    {
        if ($this->mode === 'full') {
            return;
        }

        $flatAllowed = [];
        $groupAddAllowed = [];
        $groupRemoveAllowed = [];
        $childFieldAllowed = [];

        foreach ($this->parsedPaths as $p) {
            if ($p->kind === CorrectionPath::KIND_FLAT_FIELD) {
                $flatAllowed[$p->field] = true;
            } elseif ($p->kind === CorrectionPath::KIND_GROUP_ADD) {
                $groupAddAllowed[$p->group] = true;
            } elseif ($p->kind === CorrectionPath::KIND_GROUP_REMOVE) {
                $groupRemoveAllowed[$p->group][$p->target] = true;
            } elseif ($p->kind === CorrectionPath::KIND_CHILD_FIELD) {
                $childFieldAllowed[$p->group][$p->field][$p->target] = true;
            }
        }

        $allTopKeys = array_unique([...array_keys($previous), ...array_keys($next)]);

        foreach ($allTopKeys as $key) {
            $prevVal = $previous[$key] ?? null;
            $nextVal = $next[$key] ?? null;
            $wasPresent = array_key_exists($key, $previous);
            $nowPresent = array_key_exists($key, $next);

            $isPrevGroup = $wasPresent && is_array($prevVal) && array_is_list($prevVal);
            $isNextGroup = $nowPresent && is_array($nextVal) && array_is_list($nextVal);

            if ($isPrevGroup || $isNextGroup) {
                $this->assertRepeatableGroupDiff(
                    (string) $key,
                    $prevVal ?? [],
                    $nextVal ?? [],
                    $groupAddAllowed,
                    $groupRemoveAllowed,
                    $childFieldAllowed
                );
            } else {
                if (($wasPresent !== $nowPresent || $prevVal !== $nextVal) && !isset($flatAllowed[$key])) {
                    throw new \DomainException('Field is locked for this correction round.');
                }
            }
        }
    }

    private function assertRepeatableGroupDiff(
        string $groupKey,
        mixed $prevItems,
        mixed $nextItems,
        array $groupAddAllowed,
        array $groupRemoveAllowed,
        array $childFieldAllowed
    ): void {
        if (!is_array($prevItems) || !array_is_list($prevItems) || !is_array($nextItems) || !array_is_list($nextItems)) {
            throw new \DomainException("Malformed repeatable group value in {$groupKey}.");
        }

        $prevMap = $this->indexItemsByItemId($groupKey, $prevItems);
        $nextMap = $this->indexItemsByItemId($groupKey, $nextItems);

        $allItemIds = array_unique([...array_keys($prevMap), ...array_keys($nextMap)]);

        foreach ($allItemIds as $itemId) {
            $inPrev = isset($prevMap[$itemId]);
            $inNext = isset($nextMap[$itemId]);

            if (!$inPrev && $inNext) {
                if (!isset($groupAddAllowed[$groupKey])) {
                    throw new \DomainException("Adding items to {$groupKey} is locked for this correction round.");
                }
            } elseif ($inPrev && !$inNext) {
                $allowed = isset($groupRemoveAllowed[$groupKey]['*']) || isset($groupRemoveAllowed[$groupKey][$itemId]);
                if (!$allowed) {
                    throw new \DomainException("Removing item {$itemId} from {$groupKey} is locked for this correction round.");
                }
            } else {
                $pItem = $prevMap[$itemId];
                $nItem = $nextMap[$itemId];

                $allChildKeys = array_unique([...array_keys($pItem), ...array_keys($nItem)]);
                foreach ($allChildKeys as $cKey) {
                    if ($cKey === '_item_id') {
                        continue;
                    }
                    $cWas = array_key_exists($cKey, $pItem);
                    $cNow = array_key_exists($cKey, $nItem);
                    $cBefore = $cWas ? $pItem[$cKey] : null;
                    $cAfter = $cNow ? $nItem[$cKey] : null;

                    if ($cWas !== $cNow || $cBefore !== $cAfter) {
                        $allowed = isset($childFieldAllowed[$groupKey][$cKey]['*'])
                            || isset($childFieldAllowed[$groupKey][$cKey][$itemId]);
                        if (!$allowed) {
                            throw new \DomainException("Child field {$groupKey}.{$itemId}.{$cKey} is locked for this correction round.");
                        }
                    }
                }
            }
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function indexItemsByItemId(string $groupKey, array $items): array
    {
        $map = [];
        foreach ($items as $idx => $item) {
            if (!is_array($item) || array_is_list($item)) {
                throw new \DomainException("Malformed repeatable item in {$groupKey}.");
            }
            $id = $item['_item_id'] ?? null;
            if (!is_string($id) || !preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/D', $id) || $id === '_add' || $id === '_remove') {
                throw new \DomainException("Missing or invalid item ID in {$groupKey}.");
            }
            if (isset($map[$id])) {
                throw new \DomainException("Duplicate item ID {$id} in {$groupKey}.");
            }
            $map[$id] = $item;
        }
        return $map;
    }

    public function assertDocumentSlot(string $slot): void
    {
        if ($this->mode === 'limited' && !in_array($slot, $this->documentSlots, true)) {
            throw new \DomainException('Document slot is locked for this correction round.');
        }
    }
}
