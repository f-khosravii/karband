<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $schema = Schema::connection(config('billing.connection'));

        $schema->create('payment_intents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('service_code', 120);
            $table->string('resource_type', 120);
            $table->string('resource_id', 191);
            $table->string('agency_id', 191);
            $table->string('created_by', 191);
            $table->string('client_namespace', 64);
            $table->string('local_idempotency_key', 120);
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->string('status', 20);
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->unsignedInteger('revision')->default(0);
            $table->string('central_reference', 191)->nullable();
            $table->string('central_payment_id', 191)->nullable();
            $table->string('pricing_source', 30)->default('fixed_catalog');
            $table->unsignedInteger('pricing_revision')->default(0);
            $table->unsignedInteger('refund_policy_revision')->default(0);
            $table->unsignedBigInteger('captured_amount')->nullable();
            $table->unsignedBigInteger('central_fee_amount')->nullable();
            $table->unsignedBigInteger('central_tax_amount')->nullable();
            $table->unsignedBigInteger('central_total_amount')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamps();
            $table->unique(['resource_type', 'resource_id'], 'billing_payment_resource_unique');
            $table->unique(['agency_id', 'local_idempotency_key'], 'billing_payment_request_unique');
        });

        $schema->create('payment_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('payment_id')->constrained('payment_intents')->restrictOnDelete();
            $table->string('agency_id', 191);
            $table->unsignedSmallInteger('attempt');
            $table->string('local_key', 120);
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->longText('refund_policy_json');
            $table->boolean('policy_verified')->default(false);
            $table->string('status', 20);
            $table->string('central_reference', 191)->nullable();
            $table->string('central_payment_id', 191)->nullable();
            $table->string('prepare_operation_key', 120)->nullable();
            $table->string('confirm_operation_key', 120)->nullable();
            $table->string('cancel_operation_key', 120)->nullable();
            $table->string('pricing_source', 30)->default('fixed_catalog');
            $table->unsignedInteger('pricing_revision')->default(0);
            $table->unsignedInteger('refund_policy_revision')->default(0);
            $table->unsignedBigInteger('base_amount')->nullable();
            $table->unsignedBigInteger('central_fee_amount')->nullable();
            $table->unsignedBigInteger('central_tax_amount')->nullable();
            $table->unsignedBigInteger('central_total_amount')->nullable();
            $table->unsignedBigInteger('captured_amount')->nullable();
            $table->timestamps();
            $table->unique(['payment_id', 'attempt'], 'billing_payment_attempt_number_unique');
            $table->unique(['agency_id', 'local_key'], 'billing_payment_all_local_keys_unique');
        });

        $schema->create('payment_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('payment_id')->constrained('payment_intents')->restrictOnDelete();
            $table->unsignedSmallInteger('attempt');
            $table->string('event', 40);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->string('central_reference', 191)->nullable();
            $table->dateTime('created_at');
            $table->index(['payment_id', 'id']);
        });

        $schema->create('full_refunds', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('payment_id')->unique()->constrained('payment_intents')->restrictOnDelete();
            $table->string('service_code', 120);
            $table->string('resource_type', 120);
            $table->string('resource_id', 191);
            $table->string('agency_id', 191);
            $table->string('client_namespace', 64);
            $table->string('operation_key', 120)->nullable();
            $table->string('event_code', 80);
            $table->string('event_id', 191);
            $table->string('mode', 30);
            $table->string('status', 24);
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->string('original_payment_reference', 191);
            $table->string('central_refund_reference', 191)->nullable();
            $table->string('central_refund_id', 191)->nullable();
            $table->string('requested_by', 191);
            $table->string('approved_by', 191)->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamps();
        });

        $schema->create('refund_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('refund_id')->constrained('full_refunds')->restrictOnDelete();
            $table->string('event', 40);
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->string('actor_id', 191)->nullable();
            $table->string('central_reference', 191)->nullable();
            $table->dateTime('created_at');
            $table->index(['refund_id', 'id']);
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(config('billing.connection'));
        $schema->dropIfExists('refund_events');
        $schema->dropIfExists('full_refunds');
        $schema->dropIfExists('payment_events');
        $schema->dropIfExists('payment_attempts');
        $schema->dropIfExists('payment_intents');
    }
};
