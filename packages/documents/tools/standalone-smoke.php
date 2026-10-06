<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Intl\\Documents\\';
    if (str_starts_with($class, $prefix)) {
        require_once dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});
use Intl\Documents\Definitions\DocumentDefinition;
use Intl\Documents\Definitions\DocumentRequirement;
use Intl\Documents\Engine\DocumentValidator;
use Intl\Documents\ValueObjects\UploadDescriptor;

$d = new DocumentDefinition('benefit', 1, [new DocumentRequirement('passport', 'Passport', true), new DocumentRequirement('notes', 'Notes', false)]);
assert(DocumentDefinition::fromArray($d->toArray())->toArray() === $d->toArray());
assert((new DocumentValidator())->missingRequired($d, []) === ['passport']);
assert((new DocumentValidator())->missingRequired($d, ['passport']) === []);
assert((new DocumentValidator())->validateUpload($d->requirement('passport'), new UploadDescriptor('text/html', 200, str_repeat('f', 64))) === ['mime_not_allowed']);
echo "Document core smoke OK\n";
