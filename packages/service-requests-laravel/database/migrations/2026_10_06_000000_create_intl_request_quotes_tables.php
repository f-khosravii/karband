<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intl_request_quotes', function (Blueprint $table) {
            $table->string('request_id', 191)->primary();
            $table->integer('current_revision')->default(0);
            $table->integer('accepted_revision')->nullable();
            $table->string('status', 64)->default('none');
            $table->timestamps();
        });

        Schema::create('intl_request_quote_proposals', function (Blueprint $table) {
            $table->string('id', 191)->primary();
            $table->string('request_id', 191);
            $table->integer('revision');
            $table->bigInteger('amount');
            $table->string('currency', 3);
            $table->string('status', 64);
            $table->string('proposed_by', 191);
            $table->timestamp('proposed_at');
            $table->timestamp('accepted_at')->nullable();
            $table->string('accepted_by', 191)->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejected_by', 191)->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['request_id', 'revision']);
            $table->index('request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intl_request_quote_proposals');
        Schema::dropIfExists('intl_request_quotes');
    }
};
