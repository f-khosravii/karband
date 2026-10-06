<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $connection = config('service-requests.connection');
        $schema = Schema::connection($connection);

        $schema->create('service_requests', static function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('service_code', 120);
            $table->string('agency_id', 191);
            $table->string('recipient_type', 120);
            $table->string('recipient_id', 191);
            $table->string('idempotency_key', 120);
            $table->string('created_by', 255);
            $table->string('workflow_instance_id', 191)->nullable()->unique();
            $table->unsignedInteger('form_version');
            $table->unsignedInteger('document_version');
            $table->longText('policy_json')->nullable();
            $table->longText('recipient_snapshot_cipher')->nullable();
            $table->unsignedInteger('recipient_revision')->nullable();
            $table->timestamp('first_submitted_at')->nullable();
            $table->timestamp('draft_frozen_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->string('deleted_by', 255)->nullable();
            $table->timestamps();
            $table->unique(['agency_id', 'idempotency_key'], 'service_requests_agency_idem_unique');
            $table->index(['agency_id', 'service_code'], 'service_requests_agency_service_idx');
            $table->index(['agency_id', 'recipient_id', 'first_submitted_at'], 'service_requests_recipient_progress_idx');
        });

        $schema->create('request_cancellations', static function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('request_id')->index();
            $table->string('original_workflow_state', 120);
            $table->unsignedInteger('original_workflow_revision');
            $table->string('status', 20);
            $table->string('requested_by', 255);
            $table->text('request_reason')->nullable();
            $table->string('decided_by', 255)->nullable();
            $table->text('decision_reason')->nullable();
            $table->unsignedBigInteger('workflow_history_id')->nullable();
            $table->timestamps();
            $table->index(['request_id', 'status'], 'request_cancellations_lookup_idx');
        });

        $schema->create('request_representatives', static function (Blueprint $table): void {
            $table->ulid('request_id')->primary();
            $table->string('source', 12);
            $table->ulid('profile_representative_id')->nullable();
            $table->longText('snapshot_cipher');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
        });

        $schema->create('request_corrections', static function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('request_id');
            $table->unsignedInteger('round');
            $table->boolean('active');
            $table->string('mode', 12);
            $table->longText('allowed_fields');
            $table->longText('allowed_slots');
            $table->text('reason')->nullable();
            $table->string('requested_by', 255);
            $table->unsignedBigInteger('workflow_history_id');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['request_id', 'round'], 'request_corrections_round_idx');
        });

        $schema->create('request_quotes', static function (Blueprint $table): void {
            $table->string('request_id', 191)->primary();
            $table->integer('current_revision')->default(0);
            $table->integer('accepted_revision')->nullable();
            $table->string('status', 64)->default('none');
            $table->timestamps();
        });

        $schema->create('request_quote_proposals', static function (Blueprint $table): void {
            $table->string('id', 191)->primary();
            $table->string('request_id', 191);
            $table->integer('revision');
            $table->bigInteger('amount');
            $table->string('currency', 3);
            $table->string('status', 64);
            $table->string('proposed_by', 191);
            $table->dateTime('proposed_at');
            $table->dateTime('accepted_at')->nullable();
            $table->string('accepted_by', 191)->nullable();
            $table->dateTime('rejected_at')->nullable();
            $table->string('rejected_by', 191)->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['request_id', 'revision'], 'request_quote_proposals_revision_unique');
            $table->index('request_id', 'request_quote_proposals_request_id_idx');
        });
    }

    public function down(): void
    {
        $connection = config('service-requests.connection');
        $schema = Schema::connection($connection);
        $schema->dropIfExists('request_quote_proposals');
        $schema->dropIfExists('request_quotes');
        $schema->dropIfExists('request_corrections');
        $schema->dropIfExists('request_representatives');
        $schema->dropIfExists('request_cancellations');
        $schema->dropIfExists('service_requests');
    }
};
