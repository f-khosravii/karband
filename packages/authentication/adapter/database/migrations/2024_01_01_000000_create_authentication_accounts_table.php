<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $connection = config('authentication.connection');

        if (!Schema::connection($connection)->hasTable('authentication_accounts')) {
            Schema::connection($connection)->create('authentication_accounts', function (Blueprint $table): void {
                $table->string('id', 36)->primary();
                $table->string('login_identifier', 255)->unique();
                $table->string('password_hash', 255);
                $table->string('status', 32)->default('active')->index();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        $connection = config('authentication.connection');
        Schema::connection($connection)->dropIfExists('authentication_accounts');
    }
};
