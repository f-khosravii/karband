<?php

declare(strict_types=1);

namespace Karband\RequestJourney\Definitions;

final class StepType
{
    public const FORM = 'form';
    public const DOCUMENTS = 'documents';
    public const REVIEW = 'review';
    public const QUOTE = 'quote';
    public const SIGNATURE = 'signature';
    public const PAYMENT = 'payment';
    public const CUSTOM = 'custom';

    public const ALL = [
        self::FORM,
        self::DOCUMENTS,
        self::REVIEW,
        self::QUOTE,
        self::SIGNATURE,
        self::PAYMENT,
        self::CUSTOM,
    ];

    public static function isValid(string $type): bool
    {
        return in_array($type, self::ALL, true);
    }
}
