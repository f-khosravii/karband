<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $connection = config('intl-external-auth.connection');
        Schema::connection($connection)->create('intl_external_sessions', static function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('provider', 64);
            $table->string('external_session_id', 191);
            $table->string('subject_id', 191);
            $table->string('organization_id', 191)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'external_session_id'], 'intl_ext_sess_provider_id_unique');
            $table->index(['provider', 'subject_id'], 'intl_ext_sess_subject_idx');
        });
    }

    public function down(): void
    {
        $connection = config('intl-external-auth.connection');
        Schema::connection($connection)->dropIfExists('intl_external_sessions');
    }
};
