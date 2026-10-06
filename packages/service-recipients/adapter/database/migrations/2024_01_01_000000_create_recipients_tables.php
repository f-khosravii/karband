<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection(config('service-recipients.connection'))->create('recipients', static function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('agency_id', 191); $t->string('kind', 16);
            $t->string('identity_type', 64); $t->char('identity_lookup', 64);
            $t->text('identity_cipher'); $t->longText('profile_cipher');
            $t->string('status', 16)->default('active'); $t->unsignedInteger('revision')->default(1);
            $t->string('created_by', 191); $t->string('archived_by', 191)->nullable();
            $t->timestamp('archived_at')->nullable(); $t->timestamps();
            $t->unique(['agency_id', 'identity_lookup'], 'recipient_agency_identity_uniq');
            $t->index(['agency_id', 'status'], 'recipient_agency_status_idx');
        });
        Schema::connection(config('service-recipients.connection'))->create('recipient_identity_changes', static function (Blueprint $t): void {
            $t->ulid('id')->primary(); $t->ulid('recipient_id');
            $t->string('proposed_type', 64); $t->char('proposed_lookup', 64);
            $t->text('proposed_cipher'); $t->string('status', 16);
            $t->string('requested_by', 191); $t->string('decided_by', 191)->nullable();
            $t->timestamp('decided_at')->nullable(); $t->timestamps();
            $t->index(['recipient_id','status'], 'recipient_identity_pending_idx');
        });
        Schema::connection(config('service-recipients.connection'))->create('recipient_representatives', static function (Blueprint $t): void {
            $t->ulid('id')->primary(); $t->ulid('recipient_id');
            $t->longText('profile_cipher'); $t->boolean('active')->default(true);
            $t->unsignedInteger('revision')->default(1); $t->string('created_by',191);
            $t->timestamps(); $t->index(['recipient_id','active'], 'recipient_reps_idx');
        });
    }
    public function down(): void
    {
        $s = Schema::connection(config('service-recipients.connection'));
        $s->dropIfExists('recipient_representatives');
        $s->dropIfExists('recipient_identity_changes');
        $s->dropIfExists('recipients');
    }
};
