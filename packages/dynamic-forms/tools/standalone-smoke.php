<?php

declare(strict_types=1);

require dirname(__DIR__) . '/tests/bootstrap.php';

use Intl\DynamicForms\Definitions\FormDefinition;
use Intl\DynamicForms\Engine\FormValidator;
use Intl\DynamicForms\Exceptions\InvalidForm;

$form = FormDefinition::fromArray([
    'service_code' => 'test', 'version' => 1, 'fields' => [
        ['key'=>'name','label'=>'Name','type'=>'text','required'=>true],
        ['key'=>'urgent','label'=>'Urgent','type'=>'checkbox','required'=>true],
        ['key'=>'reason','label'=>'Reason','type'=>'textarea','required'=>true,'visible_when'=>['field'=>'urgent','equals'=>true]],
    ],
]);
$validator = new FormValidator();
assert($validator->validate($form, ['name'=>'Ava', 'urgent'=>false])->valid());
assert(!$validator->validate($form, ['name'=>'Ava', 'urgent'=>true])->valid());
assert(!$validator->validate($form, ['name'=>'Ava', 'urgent'=>false, 'reason'=>'untrusted'])->valid());
assert(!$validator->validate($form, ['name'=>'Ava', 'urgent'=>false, 'unknown'=>true])->valid());
assert(FormDefinition::fromArray($form->toArray())->toArray() === $form->toArray());
try {
    FormDefinition::fromArray(['service_code'=>'test','version'=>1,'fields'=>[['key'=>'x','label'=>'X','type'=>'text','rule'=>'missing']]])
        ->fields[0];
    $validator->assertPublishable(FormDefinition::fromArray(['service_code'=>'test','version'=>1,'fields'=>[['key'=>'x','label'=>'X','type'=>'text','rule'=>'missing']]]));
    throw new RuntimeException('Expected rejection');
} catch (InvalidForm) {
}
echo "Dynamic Forms Core: 6 standalone checks passed\n";
