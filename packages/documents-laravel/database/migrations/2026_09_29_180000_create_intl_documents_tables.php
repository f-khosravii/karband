<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $schema = Schema::connection(config('intl-documents.connection'));
        $schema->create('intl_document_drafts', static function (Blueprint $table): void {
            $table->string('service_code', 120)->primary();
            $table->unsignedInteger('version');
            $table->longText('definition');
            $table->unsignedInteger('revision')->default(0);
            $table->string('updated_by', 191);
            $table->timestamps();
        });
        $schema->create('intl_document_definitions', static function (Blueprint $table): void {
            $table->string('service_code', 120);
            $table->unsignedInteger('version');
            $table->longText('definition');
            $table->string('published_by', 191);
            $table->timestamp('published_at');
            $table->primary(['service_code', 'version']);
        });
        $schema->create('intl_document_sets', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('service_code', 120);
            $table->string('resource_type', 120);
            $table->string('resource_id', 191);
            $table->unsignedInteger('definition_version');
            $table->string('organization_id', 191)->nullable();
            $table->string('created_by', 191);
            $table->unsignedInteger('revision')->default(0);
            $table->timestamps();
            $table->unique(['service_code', 'resource_type', 'resource_id'], 'intl_document_sets_resource_unique');
        });
        $schema->create('intl_document_files', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('set_id');
            $table->string('requirement_code', 120);
            $table->unsignedInteger('slot_version');
            $table->uuid('supersedes_id')->nullable();
            $table->string('storage_disk', 120);
            $table->string('storage_key', 255);
            $table->string('original_name', 255);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->string('uploaded_by', 191);
            $table->string('actor_role', 120);
            $table->timestamp('created_at');
            $table->unique(['set_id', 'requirement_code', 'slot_version'], 'intl_document_files_slot_version');
            $table->unique('storage_key', 'intl_document_files_storage_key');
            $table->index(['set_id', 'requirement_code']);
            $table->foreign('set_id')->references('id')->on('intl_document_sets')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(config('intl-documents.connection'));
        $schema->dropIfExists('intl_document_files');
        $schema->dropIfExists('intl_document_sets');
        $schema->dropIfExists('intl_document_definitions');
        $schema->dropIfExists('intl_document_drafts');
    }
};
