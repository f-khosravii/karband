<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $schema = Schema::connection(config('intl-documents.connection'));
        $schema->table('intl_document_files', static function (Blueprint $table): void {
            $table->boolean('is_withdrawn')->default(false)->after('actor_role');
            $table->timestamp('withdrawn_at')->nullable()->after('is_withdrawn');
            $table->string('withdrawn_by', 191)->nullable()->after('withdrawn_at');
            $table->text('withdrawal_reason')->nullable()->after('withdrawn_by');
            $table->index(['set_id', 'requirement_code', 'is_withdrawn'], 'intl_doc_files_active_idx');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(config('intl-documents.connection'));
        $schema->table('intl_document_files', static function (Blueprint $table): void {
            $table->dropIndex('intl_doc_files_active_idx');
            $table->dropColumn(['is_withdrawn', 'withdrawn_at', 'withdrawn_by', 'withdrawal_reason']);
        });
    }
};
