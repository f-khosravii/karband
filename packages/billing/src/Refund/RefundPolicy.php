<?php

declare(strict_types=1);

namespace Intl\Billing\Refund;

/** An immutable, per-attempt snapshot of the LOCAL service refund policy. */
final readonly class RefundPolicy
{
    /** @var array<string, RefundInitiation> */
    private array $events;

    /** @param array<string, RefundInitiation> $events */
    public function __construct(array $events)
    {
        $valid = [];
        foreach ($events as $event => $mode) {
            if (!is_string($event) || !preg_match('/^[a-z][a-z0-9_.-]{0,79}$/D', $event)
                || !$mode instanceof RefundInitiation) {
                throw new \InvalidArgumentException('Invalid refund event or initiation mode.');
            }
            $valid[$event] = $mode;
        }
        ksort($valid);
        $this->events = $valid;
    }

    public static function nonRefundable(): self { return new self([]); }

    public function modeFor(string $event): ?RefundInitiation { return $this->events[$event] ?? null; }

    /** @return array<string, string> */
    public function toArray(): array
    {
        $result = [];
        foreach ($this->events as $event => $mode) { $result[$event] = $mode->value; }
        return $result;
    }

    /** @param array<string, mixed> $events */
    public static function fromArray(array $events): self
    {
        $typed = [];
        foreach ($events as $event => $mode) {
            if (!is_string($event) || !is_string($mode)) {
                throw new \InvalidArgumentException('Invalid serialized refund policy.');
            }
            $typed[$event] = RefundInitiation::from($mode);
        }
        return new self($typed);
    }
}
