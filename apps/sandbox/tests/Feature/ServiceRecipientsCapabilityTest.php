<?php

declare(strict_types=1);
namespace Tests\Feature;

use Illuminate\Foundation\Auth\User as BaseUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Karband\AuthorizationLaravel\Contracts\PrincipalProvider;
use Karband\AuthorizationLaravel\Contracts\ScopeChecker;
use Karband\AuthorizationLaravel\Principal;
use Karband\Authorization\AccessRequest;
use Karband\Authorization\ActorContext;
use Karband\Authorization\AuthorizationDenied;
use Karband\ServiceRecipients\Contracts\RecipientRules;
use Karband\ServiceRecipients\Exceptions\DuplicateRecipient;
use Karband\ServiceRecipients\Exceptions\RecipientConflict;
use Karband\ServiceRecipients\Identity;
use Karband\ServiceRecipients\RecipientInput;
use Karband\ServiceRecipients\RecipientKind;
use Karband\ServiceRecipientsLaravel\Contracts\RecipientAudit;
use Karband\ServiceRecipientsLaravel\Contracts\RecipientRequestBridge;
use Karband\ServiceRecipientsLaravel\Services\Recipients;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;
use Tests\TestCase;

/** Sandbox test fixtures deliberately simulate ONLY local host policies. No actual request creation. */
final class ServiceRecipientsCapabilityTest extends TestCase
{
    use RefreshDatabase;

    private RecipientTestUser $user;
    private RecipientTestPrincipal $principal;
    private RecipientTestRequests $requests;
    private RecipientTestAudit $audit;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('service-recipients.identity_hmac_key', str_repeat('Z', 48));
        $connection = config('service-recipients.connection');
        $this->requests = new RecipientTestRequests($connection);
        $this->audit = new RecipientTestAudit($connection);
        $this->app->instance(RecipientRules::class, new RecipientTestRules());
        $this->app->instance(RecipientRequestBridge::class, $this->requests);
        $this->app->instance(RecipientAudit::class, $this->audit);
        $this->principal = new RecipientTestPrincipal();
        $this->app->instance(PrincipalProvider::class, $this->principal);
        // Permissive TEST scope is intentional: Recipients enforces agency ownership separately.
        $this->app->instance(ScopeChecker::class, new RecipientTestScopes());
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['agency','platform_admin','government_admin'] as $r) { Role::findOrCreate($r,'web'); }
        $agencyAbilities = ['recipients.create','recipients.list','recipients.view','recipients.update',
            'recipients.identity.request','recipients.representative.add',
            'recipients.representative.view','recipients.representative.deactivate'];
        $platformAbilities = ['recipients.identity.decide','recipients.archive','recipients.restore'];
        foreach (array_merge($agencyAbilities,$platformAbilities) as $ability) { Permission::findOrCreate($ability,'web'); }
        Role::findByName('agency','web')->givePermissionTo($agencyAbilities);
        Role::findByName('platform_admin','web')->givePermissionTo($platformAbilities);
        $this->user = RecipientTestUser::create([
            'name'=>'Recipient tester','email'=>'recipient-test@example.test','password'=>'test-only',
        ]);
        $this->asRole('agency','agency-a');
    }

    private function asRole(string $role, ?string $agency): void
    {
        $this->user->syncRoles([$role]);
        Auth::guard('web')->setUser($this->user);
        $this->principal->value = new Principal($this->user,
            new ActorContext((string)$this->user->getAuthIdentifier(),$role,$agency),'web');
    }
    private function svc(): Recipients { return app(Recipients::class); }
    private function person(string $id='ID123'): RecipientInput
    {
        return new RecipientInput(RecipientKind::Person,'national_id',$id,['full_name'=>'Fixture Person']);
    }
    public function test_creates_encrypted_agency_owned_profile_and_audits(): void
    {
        $r=$this->svc()->create($this->person());
        self::assertSame('agency-a',$r->agencyId);
        self::assertSame('Fixture Person',$this->svc()->get($r->id)->profile['full_name']);
        $row=\DB::table('recipients')->where('id',$r->id)->first();
        self::assertStringNotContainsString('Fixture Person',(string)$row->profile_cipher);
        self::assertStringNotContainsString('ID123',(string)$row->identity_cipher);
        self::assertSame('recipients.created',$this->audit->events[0]);
        self::assertCount(1,$this->svc()->listActive());
    }
    public function test_duplicate_suggests_only_own_existing_record(): void
    {
        $original=$this->svc()->create($this->person());
        try {$this->svc()->create($this->person()); self::fail('Duplicate should fail.');}
        catch (DuplicateRecipient $e) {self::assertSame($original->id,$e->existingId);}
        $this->asRole('agency','agency-b');
        self::assertNotSame($original->id,$this->svc()->create($this->person())->id);
    }
    public function test_other_agency_cannot_fetch_or_modify_profile(): void
    {
        $r=$this->svc()->create($this->person());
        $this->asRole('agency','agency-b');
        $this->expectException(AuthorizationDenied::class);
        $this->svc()->get($r->id);
    }
    public function test_change_normal_profile_syncs_drafts_but_identity_requires_approval(): void
    {
        $r=$this->svc()->create($this->person());
        $updated=$this->svc()->updateProfile($r->id,['full_name'=>'New Person'],1);
        self::assertSame(2,$updated->revision);
        self::assertCount(1,$this->requests->syncs);
        self::assertSame('ID123',$this->svc()->get($r->id)->identity->normalized);
        $pending=$this->svc()->requestIdentityChange($r->id,'national_id','ID999');
        self::assertSame('ID123',$this->svc()->get($r->id)->identity->normalized);
        $this->asRole('platform_admin','platform');
        $this->svc()->decideIdentityChange($pending,true);
        $this->asRole('agency','agency-a');
        self::assertSame('ID999',$this->svc()->get($r->id)->identity->normalized);
        self::assertCount(2,$this->requests->syncs);
    }
    public function test_stale_revision_does_not_update_or_sync(): void
    {
        $r=$this->svc()->create($this->person());
        $this->svc()->updateProfile($r->id,['full_name'=>'A'],1);
        $this->expectException(RecipientConflict::class);
        $this->svc()->updateProfile($r->id,['full_name'=>'B'],1);
    }
    public function test_archiving_rejected_if_open_submitted_request_exists(): void
    {
        $r=$this->svc()->create($this->person());
        $this->requests->open=true;
        $this->asRole('platform_admin','platform');
        $this->expectException(RecipientConflict::class);
        $this->svc()->archive($r->id);
    }
    public function test_archive_freezes_drafts_and_restore_does_not_unfreeze(): void
    {
        $r=$this->svc()->create($this->person());
        $this->asRole('platform_admin','platform');
        $this->svc()->archive($r->id);
        self::assertSame(1,count($this->requests->freezes));
        self::assertSame('archived',\DB::table('recipients')->where('id',$r->id)->value('status'));
        $this->svc()->restore($r->id);
        self::assertSame(1,count($this->requests->freezes));
        self::assertSame('active',\DB::table('recipients')->where('id',$r->id)->value('status'));
    }
    public function test_representatives_require_legal_kind_and_obey_limit(): void
    {
        $legal=$this->svc()->create(new RecipientInput(RecipientKind::Legal,'registration','C-11',['name'=>'Fixture Corp']));
        $one=$this->svc()->addRepresentative($legal->id,['name'=>'Rep One']);
        $this->svc()->addRepresentative($legal->id,['name'=>'Rep Two']);
        self::assertCount(2,$this->svc()->activeRepresentatives($legal->id));
        try {$this->svc()->addRepresentative($legal->id,['name'=>'Rep Three']);self::fail('Limit not checked.');}
        catch (RecipientConflict) {self::assertCount(2,$this->svc()->activeRepresentatives($legal->id));}
        $this->svc()->deactivateRepresentative($legal->id,$one);
        self::assertCount(1,$this->svc()->activeRepresentatives($legal->id));
    }
    public function test_missing_bridge_fails_closed_before_writes(): void
    {
        $this->app->forgetInstance(RecipientRequestBridge::class);
        $this->app->offsetUnset(RecipientRequestBridge::class);
        $this->expectException(\Illuminate\Contracts\Container\BindingResolutionException::class);
        $this->svc()->create($this->person());
    }
}

final class RecipientTestUser extends BaseUser
{
    use HasRoles;
    protected $table='users';
    protected $guard_name='web';
    protected $guarded=[];
}
final class RecipientTestPrincipal implements PrincipalProvider
{
    public Principal $value;
    public function current(): Principal { return $this->value; }
}
final class RecipientTestScopes implements ScopeChecker
{
    public function allows(ActorContext $actor, AccessRequest $request): bool { return true; }
}
final class RecipientTestRules implements RecipientRules
{
    public function assertAllowedKind(RecipientKind $kind): void {}
    public function normalize(RecipientKind $kind, string $identityType, string $raw): Identity
    { return new Identity($identityType, strtoupper(trim($raw))); }
    public function validateProfile(RecipientKind $kind,array $profile): void
    { if (!isset($profile[$kind===RecipientKind::Person?'full_name':'name'])) {throw new \InvalidArgumentException('Host profile rule.');} }
    public function validateRepresentative(array $profile): void
    { if (!isset($profile['name'])) {throw new \InvalidArgumentException('Host rep rule.');} }
    public function maxActiveRepresentatives(): int {return 2;}
}
final class RecipientTestRequests implements RecipientRequestBridge
{
    public bool $open=false;
    public array $syncs=[];
    public array $freezes=[];
    public function __construct(private readonly ?string $connection) {}
    public function connectionName(): ?string {return $this->connection;}
    public function hasOpenSubmittedRequests(string $agencyId,string $recipientId): bool {return $this->open;}
    public function syncEditableDrafts(string $agencyId,string $recipientId,array $profile,string $identityType,string $identityValue): void
    {$this->syncs[] = [$agencyId,$recipientId,$profile,$identityType,$identityValue];}
    public function freezeDrafts(string $agencyId,string $recipientId): void
    {$this->freezes[] = [$agencyId,$recipientId];}
}
final class RecipientTestAudit implements RecipientAudit
{
    public array $events=[];
    public function __construct(private readonly ?string $connection) {}
    public function connectionName(): ?string{return $this->connection;}
    public function record(string $eventCode,string $recipientId,array $metadata=[]): void {$this->events[]=$eventCode;}
}
