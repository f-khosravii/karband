<?php

declare(strict_types=1);

namespace Intl\DynamicForms\Tests;

use Intl\DynamicForms\Contracts\CustomRule;
use Intl\DynamicForms\Definitions\FormDefinition;
use Intl\DynamicForms\Definitions\FormField;
use Intl\DynamicForms\Engine\EmptyRuleRegistry;
use Intl\DynamicForms\Engine\FormValidator;
use Intl\DynamicForms\Engine\MapRuleRegistry;
use Intl\DynamicForms\Exceptions\InvalidForm;
use PHPUnit\Framework\TestCase;

final class FormValidatorTest extends TestCase
{
    private function form(): FormDefinition
    {
        return new FormDefinition('birth.certificate', 1, [
            new FormField('name', 'Applicant name', 'text', true, maxLength: 12),
            new FormField('urgent', 'Urgent?', 'checkbox', true),
            new FormField('reason', 'Urgency reason', 'textarea', true, visibleWhen: ['field'=>'urgent','equals'=>true]),
            new FormField('channel', 'Delivery', 'select', true, ['post', 'pickup']),
            new FormField('birthdate', 'Date', 'date'),
            new FormField('age', 'Age', 'number', min: 0, max: 120),
            new FormField('contact', 'Contact', 'email'),
        ]);
    }

    public function test_valid_normalized_answers(): void
    {
        $result = (new FormValidator())->validate($this->form(), [
            'name'=>'Ava', 'urgent'=>false, 'channel'=>'post', 'age'=>31, 'birthdate'=>'1995-09-29', 'contact'=>'a@b.com',
        ]);
        self::assertTrue($result->valid());
        self::assertArrayNotHasKey('reason', $result->answers);
        self::assertSame(false, $result->answers['urgent']);
    }

    public function test_missing_required_is_rejected(): void
    {
        $result = (new FormValidator())->validate($this->form(), ['urgent'=>false, 'channel'=>'post']);
        self::assertSame('This field is required.', $result->errors['name']);
    }

    public function test_visible_conditional_field_is_required(): void
    {
        $result = (new FormValidator())->validate($this->form(), ['name'=>'Ava', 'urgent'=>true, 'channel'=>'post']);
        self::assertSame('This field is required.', $result->errors['reason']);
    }

    public function test_hidden_field_injection_is_rejected(): void
    {
        $result = (new FormValidator())->validate($this->form(), [
            'name'=>'Ava', 'urgent'=>false, 'reason'=>'hidden override', 'channel'=>'post',
        ]);
        self::assertSame('Hidden fields must not be submitted.', $result->errors['reason']);
    }

    public function test_unknown_field_is_rejected(): void
    {
        $result = (new FormValidator())->validate($this->form(), ['name'=>'Ava','urgent'=>false,'channel'=>'post','is_admin'=>true]);
        self::assertSame('Unknown field.', $result->errors['is_admin']);
    }

    public function test_wrong_types_and_option_are_rejected(): void
    {
        $result = (new FormValidator())->validate($this->form(), [
            'name'=>['wrong'], 'urgent'=>'false', 'channel'=>'secret', 'birthdate'=>'2025-02-30',
            'age'=>121, 'contact'=>'bad-address',
        ]);
        foreach (['name','urgent','channel','birthdate','age','contact'] as $key) {
            self::assertArrayHasKey($key, $result->errors);
        }
    }

    public function test_text_length_counts_unicode_characters(): void
    {
        $result = (new FormValidator())->validate($this->form(), ['name'=>str_repeat('آ', 13),'urgent'=>false,'channel'=>'post']);
        self::assertArrayHasKey('name', $result->errors);
        $valid = (new FormValidator())->validate($this->form(), ['name'=>str_repeat('آ', 12),'urgent'=>false,'channel'=>'post']);
        self::assertTrue($valid->valid());
    }

    public function test_duplicate_key_and_forward_reference_fail(): void
    {
        $this->expectException(InvalidForm::class);
        new FormDefinition('test', 1, [new FormField('x','X','text'), new FormField('x','Again','text')]);
    }

    public function test_forward_reference_fails(): void
    {
        $this->expectException(InvalidForm::class);
        new FormDefinition('test', 1, [
            new FormField('dependent','Dependent','text', visibleWhen:['field'=>'later','equals'=>true]),
            new FormField('later','Later','checkbox'),
        ]);
    }

    public function test_unregistered_rule_prevents_publication(): void
    {
        $this->expectException(InvalidForm::class);
        (new FormValidator(new EmptyRuleRegistry()))->assertPublishable(
            new FormDefinition('test', 1, [new FormField('value','Value','text', rule:'safe.rule')]),
        );
    }

    public function test_registered_rule_can_validate(): void
    {
        $rules = new MapRuleRegistry(['prefix.a'=>new class implements CustomRule {
            public function validate(mixed $value, array $validatedAnswers): ?string
            {
                return str_starts_with($value, 'a') ? null : 'Must start with a.';
            }
        }]);
        $form = new FormDefinition('test', 1, [new FormField('value','Value','text',rule:'prefix.a')]);
        $validator = new FormValidator($rules);
        $validator->assertPublishable($form);
        self::assertTrue($validator->validate($form, ['value'=>'ava'])->valid());
        self::assertSame('Must start with a.', $validator->validate($form, ['value'=>'bob'])->errors['value']);
    }

    public function test_throwing_rule_fails_closed(): void
    {
        $rules = new MapRuleRegistry(['broken'=>new class implements CustomRule {
            public function validate(mixed $value, array $validatedAnswers): ?string
            {
                throw new \RuntimeException('External lookup failed');
            }
        }]);
        $this->expectException(InvalidForm::class);
        (new FormValidator($rules))->validate(
            new FormDefinition('test', 1, [new FormField('value','Value','text',rule:'broken')]), ['value'=>'hello'],
        );
    }

    public function test_json_round_trip_is_stable(): void
    {
        self::assertEquals($this->form(), FormDefinition::fromArray($this->form()->toArray()));
    }

    public function test_unknown_schema_property_fails(): void
    {
        $this->expectException(InvalidForm::class);
        FormDefinition::fromArray(['service_code'=>'test','version'=>1,'fields'=>[
            ['key'=>'field','label'=>'Field','type'=>'text','php_expression'=>'die()'],
        ]]);
    }

    public function test_select_requires_distinct_options(): void
    {
        $this->expectException(InvalidForm::class);
        new FormField('status', 'Status', 'select', options:['yes','yes']);
    }
}
