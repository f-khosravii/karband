<?php

declare(strict_types=1);

namespace Illuminate\Contracts\Auth {
    interface Authenticatable { public function getAuthIdentifier(): mixed; }
}
namespace {
    use Illuminate\Contracts\Auth\Authenticatable;
    use Intl\AuthorizationLaravel\Bridge\WorkflowActorResolver;
    use Intl\AuthorizationLaravel\Bridge\WorkflowAuthorizationBridge;
    use Intl\AuthorizationLaravel\Contracts\PrincipalProvider;
    use Intl\AuthorizationLaravel\Principal;
    use Intl\Contracts\Authorization\AccessRequest;
    use Intl\Contracts\Authorization\ActorContext;
    use Intl\Contracts\Authorization\AuthorizerInterface;
    use Intl\Workflow\Definitions\WorkflowDefinition;
    use Intl\WorkflowLaravel\ValueObjects\WorkflowInstance;

    $workflowSrc = getenv('WORKFLOW_SRC') ?: (__DIR__ . '/../../workflow/src');
    $workflowLaravelSrc = getenv('WORKFLOW_LARAVEL_SRC') ?: (__DIR__ . '/../../workflow-laravel/src');
    spl_autoload_register(static function (string $class) use ($workflowSrc, $workflowLaravelSrc): void {
        $prefixes = [
            'Intl\\AuthorizationLaravel\\' => __DIR__ . '/../src/',
            'Intl\\Contracts\\' => __DIR__ . '/../../contracts/src/',
            'Intl\\WorkflowLaravel\\' => $workflowLaravelSrc . '/',
            'Intl\\Workflow\\' => $workflowSrc . '/',
        ];
        foreach ($prefixes as $prefix => $base) {
            if (str_starts_with($class, $prefix)) {
                $file = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($file)) { require_once $file; }
                return;
            }
        }
    });
    $seen = new class implements AuthorizerInterface {
        public array $seen = [];
        public function authorize(ActorContext $actor, AccessRequest $request): void {
            $this->seen[] = [$actor, $request];
        }
    };
    $bridge = new WorkflowAuthorizationBridge($seen);
    $actor = new \Intl\Workflow\ValueObjects\ActorContext('reviewer-1', 'government_admin', 'government');
    $instance = new WorkflowInstance('w1', 'ncr.request', 'req-1', 'ncr.sample', 1, 'submitted', 1);
    $definition = WorkflowDefinition::fromArray([
        'id' => 'ncr.sample', 'version' => 1, 'initial_state' => 'draft',
        'states' => ['draft', 'submitted'],
        'transitions' => [['action'=>'submit','from'=>'draft','to'=>'submitted','allowed_roles'=>['agency']]],
    ]);
    $bridge->authorizePublish($definition, $actor);
    $bridge->authorizeStart($definition, 'ncr.request', 'req-1', $actor);
    $bridge->authorizeAction($instance, 'require_correction', $actor);
    $bridge->authorizeView($instance, $actor);
    $expected = ['workflow.definition.publish', 'workflow.instance.start', 'workflow.action.require_correction', 'workflow.instance.view'];
    foreach ($expected as $index => $ability) {
        if ($seen->seen[$index][1]->ability !== $ability) {
            throw new \RuntimeException("Bridge mapping failed for {$ability}");
        }
    }
    if ($seen->seen[2][1]->resourceId !== 'req-1') {
        throw new \RuntimeException('Resource mapping failed.');
    }
    $fakeUser = new class implements Authenticatable { public function getAuthIdentifier(): mixed { return 'reviewer-1'; } };
    $provider = new class($fakeUser) implements PrincipalProvider {
        public function __construct(private Authenticatable $user) {}
        public function current(): Principal { return new Principal($this->user, new ActorContext('reviewer-1','government_admin','government'), 'web'); }
    };
    $derived = (new WorkflowActorResolver($provider))->resolve();
    if ($derived->actorId !== 'reviewer-1' || $derived->organizationId !== 'government' || $derived->role !== 'government_admin') {
        throw new \RuntimeException('Actor mapping failed.');
    }
    echo "Workflow bridge: 6 checks passed\n";
}
