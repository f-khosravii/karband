<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $conn = config('intl-service-requests.connection');
        Schema::connection($conn)->table('intl_service_requests', static function (Blueprint $table): void {
            $table->longText('policy_json')->nullable();           // pinned per-request rules
            $table->longText('recipient_snapshot_cipher')->nullable(); // local APP_KEY encryption
            $table->unsignedInteger('recipient_revision')->nullable();
            $table->timestamp('first_submitted_at')->nullable();
            $table->timestamp('draft_frozen_at')->nullable();       // permanent after recipient archival
            $table->timestamp('deleted_at')->nullable();
            $table->string('deleted_by', 255)->nullable();
            $table->index(['agency_id','recipient_id','first_submitted_at'], 'intl_req_recipient_progress_idx');
        });
        Schema::connection($conn)->create('intl_request_cancellations', static function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('request_id')->index();
            $table->string('original_workflow_state',120);
            $table->unsignedInteger('original_workflow_revision');
            $table->string('status',20); // pending, approved, denied, withdrawn; separate review, NOT a second request state
            $table->string('requested_by',255);
            $table->text('request_reason')->nullable();
            $table->string('decided_by',255)->nullable();
            $table->text('decision_reason')->nullable();
            $table->unsignedBigInteger('workflow_history_id')->nullable();
            $table->timestamps();
            $table->index(['request_id','status'], 'intl_req_cancellation_lookup_idx');
        });
        Schema::connection($conn)->create('intl_request_representatives', static function (Blueprint $table): void {
            $table->ulid('request_id')->primary(); // at most ONE representative per legal request
            $table->string('source', 12); // profile or request
            $table->ulid('profile_representative_id')->nullable();
            $table->longText('snapshot_cipher'); // independent request-local encrypted snapshot
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
        });
        Schema::connection($conn)->create('intl_request_corrections', static function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('request_id');
            $table->unsignedInteger('round');
            $table->boolean('active');
            $table->string('mode',12);
            $table->longText('allowed_fields');
            $table->longText('allowed_slots');
            $table->text('reason');  // confidential: never put in Audit metadata
            $table->string('requested_by',255);
            $table->unsignedBigInteger('workflow_history_id');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['request_id','round'], 'intl_req_correction_round_idx');
        });
    }

    public function down(): void
    {
        $conn = config('intl-service-requests.connection');
        Schema::connection($conn)->dropIfExists('intl_request_representatives');
        Schema::connection($conn)->dropIfExists('intl_request_corrections');
        Schema::connection($conn)->dropIfExists('intl_request_cancellations');
        Schema::connection($conn)->table('intl_service_requests', static function (Blueprint $table): void {
            $table->dropIndex('intl_req_recipient_progress_idx');
            $table->dropColumn(['policy_json','recipient_snapshot_cipher','recipient_revision',
                'first_submitted_at','draft_frozen_at','deleted_at','deleted_by']);
        });
    }
};
