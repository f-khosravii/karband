<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection(config('intl-service-catalog.connection'))
            ->create('intl_catalog_services', function (Blueprint $table): void {
                $table->string('code', 120)->primary();
                $table->string('name', 191);
                $table->text('description')->nullable();
                $table->json('settings');
                $table->string('status', 20)->default('inactive');
                $table->string('workflow_id', 120)->nullable();
                $table->unsignedInteger('workflow_version')->nullable();
                $table->unsignedInteger('revision')->default(0);
                $table->timestamps();
            });
    }

    public function down(): void
    {
        Schema::connection(config('intl-service-catalog.connection'))
            ->dropIfExists('intl_catalog_services');
    }
};
