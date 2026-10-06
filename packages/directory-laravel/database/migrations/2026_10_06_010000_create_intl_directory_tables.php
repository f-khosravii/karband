<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $connection = config('intl-directory.connection');

        Schema::connection($connection)->create('intl_directory_organizations', static function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name', 255);
            $table->string('status', 16)->default('active');
            $table->string('email', 255)->nullable();
            $table->string('phone', 64)->nullable();
            $table->timestamps();

            $table->index(['status'], 'intl_dir_orgs_status_idx');
        });

        Schema::connection($connection)->create('intl_directory_organization_roles', static function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->string('role', 32);
            $table->timestamps();

            $table->foreign('organization_id', 'intl_dir_org_roles_org_fk')
                ->references('id')
                ->on('intl_directory_organizations')
                ->onDelete('cascade');

            $table->unique(['organization_id', 'role'], 'intl_dir_org_roles_unique');
            $table->index(['role'], 'intl_dir_org_roles_role_idx');
        });

        Schema::connection($connection)->create('intl_directory_users', static function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('display_name', 255);
            $table->string('status', 16)->default('active');
            $table->string('email', 255)->nullable();
            $table->string('phone', 64)->nullable();
            $table->timestamps();

            $table->index(['status'], 'intl_dir_users_status_idx');
        });

        Schema::connection($connection)->create('intl_directory_memberships', static function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('user_id');
            $table->ulid('organization_id');
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->foreign('user_id', 'intl_dir_mem_user_fk')
                ->references('id')
                ->on('intl_directory_users')
                ->onDelete('cascade');

            $table->foreign('organization_id', 'intl_dir_mem_org_fk')
                ->references('id')
                ->on('intl_directory_organizations')
                ->onDelete('cascade');

            $table->unique(['user_id', 'organization_id'], 'intl_dir_mem_user_org_unique');
            $table->index(['organization_id', 'status'], 'intl_dir_mem_org_status_idx');
        });

        Schema::connection($connection)->create('intl_directory_external_references', static function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('source', 64);
            $table->string('reference_kind', 64);
            $table->string('external_id', 191);
            $table->string('target_type', 32);
            $table->ulid('target_organization_id')->nullable();
            $table->ulid('target_user_id')->nullable();
            $table->timestamps();

            $table->foreign('target_organization_id', 'intl_dir_ext_refs_org_fk')
                ->references('id')
                ->on('intl_directory_organizations')
                ->onDelete('cascade');

            $table->foreign('target_user_id', 'intl_dir_ext_refs_user_fk')
                ->references('id')
                ->on('intl_directory_users')
                ->onDelete('cascade');

            $table->unique(['source', 'reference_kind', 'external_id'], 'intl_dir_ext_refs_unique');
            $table->index(['target_type', 'target_organization_id'], 'intl_dir_ext_refs_org_idx');
            $table->index(['target_type', 'target_user_id'], 'intl_dir_ext_refs_user_idx');
        });
    }

    public function down(): void
    {
        $connection = config('intl-directory.connection');
        $s = Schema::connection($connection);
        $s->dropIfExists('intl_directory_external_references');
        $s->dropIfExists('intl_directory_memberships');
        $s->dropIfExists('intl_directory_users');
        $s->dropIfExists('intl_directory_organization_roles');
        $s->dropIfExists('intl_directory_organizations');
    }
};
