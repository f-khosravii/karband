<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $connection = config('intl-service-requests.connection');
        Schema::connection($connection)->create('intl_service_requests', static function (Blueprint $table): void {
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
            $table->timestamps();
            $table->unique(['agency_id', 'idempotency_key'], 'intl_req_agency_idem_unique');
            $table->index(['agency_id', 'service_code'], 'intl_req_agency_service_idx');
        });
    }

    public function down(): void
    {
        Schema::connection(config('intl-service-requests.connection'))->dropIfExists('intl_service_requests');
    }
};
