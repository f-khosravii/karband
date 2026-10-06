<?php

declare(strict_types=1);
namespace Karband\ServiceRecipients;

enum RecipientKind: string
{
    case Person = 'person';
    case Legal = 'legal';
}
