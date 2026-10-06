<?php

declare(strict_types=1);

namespace Karband\DynamicFormsLaravel\Services;

use Illuminate\Database\Connection;
use Karband\DynamicForms\Definitions\FormDefinition;

/** Only storage for the one isolated microservice's local database. */
final readonly class FormStorage
{
    public function __construct(public Connection $connection)
    {
    }

    public function hydrate(object $row): FormDefinition
    {
        $data = json_decode((string) $row->definition, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \UnexpectedValueException('Stored form definition must be an object.');
        }
        $form = FormDefinition::fromArray($data);
        if ($form->serviceCode !== (string) $row->service_code || $form->version !== (int) $row->version) {
            throw new \UnexpectedValueException('Stored form definition metadata does not match.');
        }
        return $form;
    }

    public static function json(FormDefinition $form): string
    {
        return json_encode($form->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public static function attempts(): int
    {
        $attempts = config('dynamic-forms.deadlock_attempts', 3);
        if (!is_int($attempts) || $attempts < 1 || $attempts > 5) {
            throw new \InvalidArgumentException('Invalid dynamic forms transaction retry count.');
        }
        return $attempts;
    }
}
