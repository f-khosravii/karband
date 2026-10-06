# karband/dynamic-forms

Framework-independent form schema and server-side answer validation. Designed for **one form per local service**, with immutable sequential publication versions.

- Field types: text, textarea, email, number, select, checkbox, date.
- Conditional visibility: `visible_when: {field: "urgent", equals: true}` referencing an earlier field; evaluated on **trusted validated answers**, never arbitrary expressions.
- Unregistered custom rules fail closed; app-registered `CustomRule` implementations can handle business-specific checks.
- Unknown or hidden submitted fields are rejected; optional omitted fields are excluded from normalized answers.
- A required checkbox means its boolean value **must be supplied**, not that it must be `true`.
- File uploads, personal-data encryption, workflows and storage are separate concerns.

Example:

```php
use Karband\DynamicForms\Definitions\FormDefinition;
use Karband\DynamicForms\Engine\FormValidator;

$form = FormDefinition::fromArray([
    'service_code' => 'benefit', 'version' => 1,
    'fields' => [
        ['key' => 'full_name', 'label' => 'Full name', 'type' => 'text', 'required' => true, 'max_length' => 120],
        ['key' => 'urgent', 'label' => 'Urgent?', 'type' => 'checkbox'],
        ['key' => 'reason', 'label' => 'Reason', 'type' => 'textarea', 'required' => true,
         'visible_when' => ['field' => 'urgent', 'equals' => true]],
    ],
]);
$result = (new FormValidator())->validate($form, ['full_name'=>'Ava', 'urgent'=>false]);
if (!$result->valid()) {
    // Return $result->errors to caller; do not save.
}
```
