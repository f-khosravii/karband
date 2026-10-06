<?php

declare(strict_types=1);
namespace Intl\ServiceRecipients;

enum RecipientKind: string
{
    case Person = 'person';
    case Legal = 'legal';
}
