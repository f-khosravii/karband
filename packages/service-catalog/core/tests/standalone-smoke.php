<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Karband\ServiceCatalog\Definitions\ServiceDefinition;
use Karband\ServiceCatalog\Definitions\ServiceDraft;
use Karband\ServiceCatalog\Definitions\ServiceStatus;
use Karband\ServiceCatalog\Definitions\WorkflowBinding;
use Karband\ServiceCatalog\Exceptions\InvalidService;

$checks = 0;
function same(mixed $expected, mixed $actual): void
{
    global $checks;
    if ($expected !== $actual) {
        throw new \RuntimeException('Failed comparison: ' . var_export([$expected, $actual], true));
    }
    $checks++;
}
function rejects(callable $callback): void
{
    global $checks;
    try {
        $callback();
    } catch (InvalidService) {
        $checks++;
        return;
    }
    throw new \RuntimeException('Expected InvalidService was not thrown.');
}

$draft = new ServiceDraft('sample', 'Sample', 'description', ['nested' => ['locale' => 'fa']]);
$service = ServiceDefinition::fromDraft($draft);
same('sample', $service->code);
same(ServiceStatus::Inactive, $service->status);
same(0, $service->revision);
rejects(fn () => $service->transition(ServiceStatus::Active));
$binding = new WorkflowBinding('ncr.sample', 1);
$bound = $service->replace(new ServiceDraft('sample', 'Renamed', 'description', [], $binding));
same(1, $bound->revision);
same('Sample', $service->name);
$active = $bound->transition(ServiceStatus::Active);
same(ServiceStatus::Active, $active->status);
same(1, $active->workflow->version);
rejects(fn () => $active->transition(ServiceStatus::Archived));
rejects(fn () => $active->replace(new ServiceDraft('sample', 'Unbound')));
$archived = $active->transition(ServiceStatus::Inactive)->transition(ServiceStatus::Archived);
same(ServiceStatus::Archived, $archived->status);
rejects(fn () => $archived->transition(ServiceStatus::Active));
rejects(fn () => $archived->replace($draft));
rejects(fn () => new ServiceDraft('INVALID CODE', 'Sample'));
rejects(fn () => new WorkflowBinding('ncr.sample', 0));
rejects(fn () => new ServiceDraft('sample', 'Sample', settings: ['callback' => new \stdClass()]));
rejects(fn () => new ServiceDraft('sample', 'Sample', settings: ['tooBig' => str_repeat('a', 18000)]));
echo "ServiceCatalog standalone: {$checks} checks passed\n";
