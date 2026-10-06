<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $schema = Schema::connection(config('intl-dynamic-forms.connection'));
        $schema->create('intl_form_drafts', function (Blueprint $table): void {
            $table->string('service_code', 120)->primary();
            $table->unsignedInteger('version');
            $table->longText('definition');
            $table->unsignedInteger('revision')->default(0);
            $table->string('updated_by', 191);
            $table->timestamps();
        });
        $schema->create('intl_form_definitions', function (Blueprint $table): void {
            $table->string('service_code', 120);
            $table->unsignedInteger('version');
            $table->longText('definition');
            $table->string('published_by', 191);
            $table->timestamp('published_at');
            $table->primary(['service_code', 'version']);
        });
        $schema->create('intl_form_answers', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('service_code', 120);
            $table->string('resource_type', 120);
            $table->string('resource_id', 191);
            $table->unsignedInteger('form_version');
            $table->longText('answers');
            $table->string('created_by', 191);
            $table->string('updated_by', 191);
            $table->string('organization_id', 191)->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->timestamps();
            $table->unique(['service_code', 'resource_type', 'resource_id'], 'intl_form_answers_resource_unique');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(config('intl-dynamic-forms.connection'));
        $schema->dropIfExists('intl_form_answers');
        $schema->dropIfExists('intl_form_definitions');
        $schema->dropIfExists('intl_form_drafts');
    }
};
