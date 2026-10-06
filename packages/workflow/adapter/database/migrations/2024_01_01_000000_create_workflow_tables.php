<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $schema = Schema::connection(config('workflow.connection'));
        $schema->create('workflow_definitions', function (Blueprint $table): void {
            $table->id();
            $table->string('workflow_id', 120);
            $table->unsignedInteger('version');
            $table->json('definition');
            $table->dateTime('published_at');
            $table->unique(['workflow_id', 'version'], 'wf_definition_version_unique');
        });

        $schema->create('workflow_instances', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('definition_id')
                ->constrained('workflow_definitions')
                ->restrictOnDelete();
            $table->string('resource_type', 120);
            $table->string('resource_id', 191);
            $table->string('current_state', 120);
            $table->unsignedInteger('revision')->default(0);
            $table->timestamps();
            $table->unique(['resource_type', 'resource_id'], 'wf_instance_resource_unique');
        });

        $schema->create('workflow_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('instance_id')
                ->constrained('workflow_instances')
                ->restrictOnDelete();
            $table->string('from_state', 120);
            $table->string('to_state', 120);
            $table->string('action', 120);
            $table->string('actor_id', 255);
            $table->string('actor_role', 120);
            $table->string('actor_organization_id', 255)->nullable();
            $table->longText('comment')->nullable();
            $table->string('idempotency_key', 120)->nullable();
            $table->dateTime('created_at');
            $table->unique(['instance_id', 'idempotency_key'], 'wf_history_idempotency_unique');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(config('workflow.connection'));
        $schema->dropIfExists('workflow_history');
        $schema->dropIfExists('workflow_instances');
        $schema->dropIfExists('workflow_definitions');
    }
};
