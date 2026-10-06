<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $connectionName = config('intl-billing.connection');
        $schema = Schema::connection($connectionName);
        $schema->create('intl_payment_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('payment_id')->constrained('intl_payment_intents')->restrictOnDelete();
            $table->string('agency_id', 191);
            $table->unsignedSmallInteger('attempt');
            $table->string('local_key', 120);
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->longText('refund_policy_json');
            $table->boolean('policy_verified')->default(false);
            $table->string('status', 20);
            $table->string('central_reference', 191)->nullable();
            $table->timestamps();
            $table->unique(['payment_id', 'attempt'], 'intl_payment_attempt_number_unique');
            $table->unique(['agency_id', 'local_key'], 'intl_payment_all_local_keys_unique');
        });
        $schema->create('intl_full_refunds', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('payment_id')->unique()->constrained('intl_payment_intents')->restrictOnDelete();
            $table->string('service_code', 120);
            $table->string('resource_type', 120);
            $table->string('resource_id', 191);
            $table->string('agency_id', 191);
            $table->string('client_namespace', 64);
            $table->string('event_code', 80);
            $table->string('event_id', 191);
            $table->string('mode', 30);
            $table->string('status', 24);
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->string('original_payment_reference', 191);
            $table->string('central_refund_reference', 191)->nullable();
            $table->string('requested_by', 191);
            $table->string('approved_by', 191)->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamps();
        });
        $schema->create('intl_refund_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('refund_id')->constrained('intl_full_refunds')->restrictOnDelete();
            $table->string('event', 40);
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->string('actor_id', 191)->nullable();
            $table->string('central_reference', 191)->nullable();
            $table->timestamp('created_at');
            $table->index(['refund_id', 'id']);
        });

        // Safe for deferred installations that already ran Phase 8: historic attempts
        // are retained, but their previously unrecorded refund policies CANNOT be trusted.
        $db = DB::connection($connectionName);
        $db->table('intl_payment_intents')->chunkById(100, static function ($intents) use ($db): void {
            foreach ($intents as $intent) {
                $db->table('intl_payment_attempts')->insert([
                    'payment_id' => $intent->id, 'agency_id' => $intent->agency_id,
                    'attempt' => $intent->attempt, 'local_key' => $intent->local_idempotency_key,
                    'amount' => $intent->amount, 'currency' => $intent->currency,
                    'refund_policy_json' => '{}', 'policy_verified' => false,
                    'status' => $intent->status, 'central_reference' => $intent->central_reference,
                    'created_at' => $intent->created_at, 'updated_at' => $intent->updated_at,
                ]);
            }
        }, 'id');
    }

    public function down(): void
    {
        $schema = Schema::connection(config('intl-billing.connection'));
        $schema->dropIfExists('intl_refund_events');
        $schema->dropIfExists('intl_full_refunds');
        $schema->dropIfExists('intl_payment_attempts');
    }
};
