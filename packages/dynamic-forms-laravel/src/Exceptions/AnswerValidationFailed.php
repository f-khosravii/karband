<?php

declare(strict_types=1);

namespace Intl\DynamicFormsLaravel\Exceptions;

final class AnswerValidationFailed extends \InvalidArgumentException
{
    /** @param array<string,string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Form answers did not pass validation.');
    }
}
