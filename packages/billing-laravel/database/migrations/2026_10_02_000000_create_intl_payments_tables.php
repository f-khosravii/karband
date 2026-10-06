<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $schema = Schema::connection(config('intl-billing.connection'));
        $schema->create('intl_payment_intents', function (Blueprint $table): void {
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
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamps();
            $table->unique(['resource_type', 'resource_id'], 'intl_payment_resource_unique');
            $table->unique(['agency_id', 'local_idempotency_key'], 'intl_payment_request_unique');
        });
        $schema->create('intl_payment_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('payment_id')->constrained('intl_payment_intents')->restrictOnDelete();
            $table->unsignedSmallInteger('attempt');
            $table->string('event', 40);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->string('central_reference', 191)->nullable(); // retain old refs after a definitive failure retry
            $table->timestamp('created_at');
            $table->index(['payment_id', 'id']);
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(config('intl-billing.connection'));
        $schema->dropIfExists('intl_payment_events');
        $schema->dropIfExists('intl_payment_intents');
    }
};
