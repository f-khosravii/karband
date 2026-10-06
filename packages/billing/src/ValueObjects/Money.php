<?php

declare(strict_types=1);

namespace Intl\Billing\ValueObjects;

/** Integer amount in the unit agreed with the central payment provider; NEVER use floats. */
final readonly class Money
{
    public function __construct(public int $amount, public string $currency)
    {
        if ($amount < 0 || !preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new \InvalidArgumentException('Use a non-negative integer amount and a three-letter uppercase currency.');
        }
    }

    public static function zero(string $currency = 'IRR'): self
    {
        return new self(0, $currency);
    }

    public function sameAs(self $other): bool
    {
        return $this->amount === $other->amount && $this->currency === $other->currency;
    }
}
