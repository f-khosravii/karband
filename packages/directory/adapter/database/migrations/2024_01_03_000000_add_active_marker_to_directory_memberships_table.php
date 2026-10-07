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
            if (!Schema::connection($connection)->hasColumn('directory_memberships', 'active_marker')) {
                Schema::connection($connection)->table('directory_memberships', static function (Blueprint $table): void {
                    $table->unsignedTinyInteger('active_marker')->nullable()->after('status');
                });

                // Backfill existing active records
                DB::connection($connection)->table('directory_memberships')
                    ->where('status', 'active')
                    ->update(['active_marker' => 1]);

                DB::connection($connection)->table('directory_memberships')
                    ->where('status', '!=', 'active')
                    ->update(['active_marker' => null]);
            }

            Schema::connection($connection)->table('directory_memberships', static function (Blueprint $table): void {
                $table->unique(['user_id', 'organization_id', 'active_marker'], 'dir_mem_user_org_active_unique');
            });
        }
    }

    public function down(): void
    {
        $connection = config('directory.connection');

        if (Schema::connection($connection)->hasTable('directory_memberships')) {
            Schema::connection($connection)->table('directory_memberships', static function (Blueprint $table): void {
                $table->dropUnique('dir_mem_user_org_active_unique');
                if (Schema::hasColumn('directory_memberships', 'active_marker')) {
                    $table->dropColumn('active_marker');
                }
            });
        }
    }
};
