<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $connection = config('service-catalog.connection');

        if (!Schema::connection($connection)->hasTable('catalog_service_revisions')) {
            Schema::connection($connection)
                ->create('catalog_service_revisions', function (Blueprint $table): void {
                    $table->id();
                    $table->string('service_code', 120);
                    $table->unsignedInteger('revision');
                    $table->string('name', 191);
                    $table->text('description')->nullable();
                    $table->json('settings');
                    $table->string('status', 20);
                    $table->string('workflow_id', 120)->nullable();
                    $table->unsignedInteger('workflow_version')->nullable();
                    $table->timestamps();

                    $table->unique(['service_code', 'revision']);
                    $table->index('service_code');
                });
        }

        // Upgrade baseline backfill:
        // For existing services from a pre-history schema, capture a truthful
        // baseline snapshot for their current revision N only.
        // Do NOT synthesize fake revisions 1..(N-1).
        if (Schema::connection($connection)->hasTable('catalog_services')) {
            $existing = DB::connection($connection)->table('catalog_services')->get();
            foreach ($existing as $service) {
                $revision = (int) $service->revision;
                if ($revision > 0) {
                    $exists = DB::connection($connection)->table('catalog_service_revisions')
                        ->where('service_code', $service->code)
                        ->where('revision', $revision)
                        ->exists();

                    if (!$exists) {
                        DB::connection($connection)->table('catalog_service_revisions')->insert([
                            'service_code' => $service->code,
                            'revision' => $revision,
                            'name' => $service->name,
                            'description' => $service->description,
                            'settings' => $service->settings,
                            'status' => $service->status,
                            'workflow_id' => $service->workflow_id,
                            'workflow_version' => $service->workflow_version,
                            'created_at' => $service->updated_at ?? $service->created_at ?? now(),
                            'updated_at' => $service->updated_at ?? $service->created_at ?? now(),
                        ]);
                    }
                }
            }
        }
    }

    public function down(): void
    {
        Schema::connection(config('service-catalog.connection'))
            ->dropIfExists('catalog_service_revisions');
    }
};
