<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $connection = config('directory.connection');

        if (Schema::connection($connection)->hasTable('directory_memberships')) {
            $driver = DB::connection($connection)->getDriverName();

            if ($driver === 'sqlite') {
                DB::connection($connection)->statement('DROP INDEX IF EXISTS dir_mem_user_org_unique');
                DB::connection($connection)->statement('DROP INDEX IF EXISTS directory_memberships_user_id_organization_id_unique');
            }

            Schema::connection($connection)->table('directory_memberships', function (Blueprint $table) use ($driver): void {
                if (!Schema::hasColumn('directory_memberships', 'role')) {
                    $table->string('role', 64)->default('member')->after('organization_id');
                }
                if (!Schema::hasColumn('directory_memberships', 'ended_at')) {
                    $table->timestamp('ended_at')->nullable()->after('status');
                }

                if ($driver !== 'sqlite') {
                    // MariaDB/MySQL requires an index on user_id to satisfy foreign key before dropping the composite unique index
                    $table->index('user_id', 'dir_mem_user_idx');
                    $table->dropUnique('dir_mem_user_org_unique');
                }

                $table->index(['user_id', 'organization_id', 'status'], 'dir_mem_user_org_status_idx');
            });
        }
    }

    public function down(): void
    {
        $connection = config('directory.connection');
        if (Schema::connection($connection)->hasTable('directory_memberships')) {
            Schema::connection($connection)->table('directory_memberships', function (Blueprint $table): void {
                $table->dropIndex('dir_mem_user_org_status_idx');
                if (Schema::hasColumn('directory_memberships', 'ended_at')) {
                    $table->dropColumn('ended_at');
                }
                if (Schema::hasColumn('directory_memberships', 'role')) {
                    $table->dropColumn('role');
                }
            });
        }
    }
};
