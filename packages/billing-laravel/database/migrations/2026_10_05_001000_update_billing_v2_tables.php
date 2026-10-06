<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $connectionName = config('intl-billing.connection');
        $schema = Schema::connection($connectionName);

        $schema->table('intl_payment_intents', function (Blueprint $table) use ($schema): void {
            if (!$schema->hasColumn('intl_payment_intents', 'central_payment_id')) {
                $table->string('central_payment_id', 191)->nullable()->after('central_reference');
            }
            if (!$schema->hasColumn('intl_payment_intents', 'pricing_source')) {
                $table->string('pricing_source', 30)->default('fixed_catalog')->after('central_payment_id');
            }
            if (!$schema->hasColumn('intl_payment_intents', 'pricing_revision')) {
                $table->unsignedInteger('pricing_revision')->default(0)->after('pricing_source');
            }
            if (!$schema->hasColumn('intl_payment_intents', 'refund_policy_revision')) {
                $table->unsignedInteger('refund_policy_revision')->default(0)->after('pricing_revision');
            }
            if (!$schema->hasColumn('intl_payment_intents', 'captured_amount')) {
                $table->unsignedBigInteger('captured_amount')->nullable()->after('refund_policy_revision');
            }
            if (!$schema->hasColumn('intl_payment_intents', 'central_fee_amount')) {
                $table->unsignedBigInteger('central_fee_amount')->nullable()->after('captured_amount');
            }
            if (!$schema->hasColumn('intl_payment_intents', 'central_tax_amount')) {
                $table->unsignedBigInteger('central_tax_amount')->nullable()->after('central_fee_amount');
            }
            if (!$schema->hasColumn('intl_payment_intents', 'central_total_amount')) {
                $table->unsignedBigInteger('central_total_amount')->nullable()->after('central_tax_amount');
            }
        });

        $schema->table('intl_payment_attempts', function (Blueprint $table) use ($schema): void {
            if (!$schema->hasColumn('intl_payment_attempts', 'central_payment_id')) {
                $table->string('central_payment_id', 191)->nullable()->after('central_reference');
            }
            if (!$schema->hasColumn('intl_payment_attempts', 'prepare_operation_key')) {
                $table->string('prepare_operation_key', 120)->nullable()->after('central_payment_id');
            }
            if (!$schema->hasColumn('intl_payment_attempts', 'confirm_operation_key')) {
                $table->string('confirm_operation_key', 120)->nullable()->after('prepare_operation_key');
            }
            if (!$schema->hasColumn('intl_payment_attempts', 'cancel_operation_key')) {
                $table->string('cancel_operation_key', 120)->nullable()->after('confirm_operation_key');
            }
            if (!$schema->hasColumn('intl_payment_attempts', 'pricing_source')) {
                $table->string('pricing_source', 30)->default('fixed_catalog')->after('cancel_operation_key');
            }
            if (!$schema->hasColumn('intl_payment_attempts', 'pricing_revision')) {
                $table->unsignedInteger('pricing_revision')->default(0)->after('pricing_source');
            }
            if (!$schema->hasColumn('intl_payment_attempts', 'refund_policy_revision')) {
                $table->unsignedInteger('refund_policy_revision')->default(0)->after('pricing_revision');
            }
            if (!$schema->hasColumn('intl_payment_attempts', 'base_amount')) {
                $table->unsignedBigInteger('base_amount')->nullable()->after('refund_policy_revision');
            }
            if (!$schema->hasColumn('intl_payment_attempts', 'central_fee_amount')) {
                $table->unsignedBigInteger('central_fee_amount')->nullable()->after('base_amount');
            }
            if (!$schema->hasColumn('intl_payment_attempts', 'central_tax_amount')) {
                $table->unsignedBigInteger('central_tax_amount')->nullable()->after('central_fee_amount');
            }
            if (!$schema->hasColumn('intl_payment_attempts', 'central_total_amount')) {
                $table->unsignedBigInteger('central_total_amount')->nullable()->after('central_tax_amount');
            }
            if (!$schema->hasColumn('intl_payment_attempts', 'captured_amount')) {
                $table->unsignedBigInteger('captured_amount')->nullable()->after('central_total_amount');
            }
        });

        $schema->table('intl_full_refunds', function (Blueprint $table) use ($schema): void {
            if (!$schema->hasColumn('intl_full_refunds', 'operation_key')) {
                $table->string('operation_key', 120)->nullable()->after('client_namespace');
            }
            if (!$schema->hasColumn('intl_full_refunds', 'central_refund_id')) {
                $table->string('central_refund_id', 191)->nullable()->after('central_refund_reference');
            }
        });
    }

    public function down(): void
    {
        $connectionName = config('intl-billing.connection');
        $schema = Schema::connection($connectionName);

        $schema->table('intl_payment_intents', function (Blueprint $table): void {
            $table->dropColumn([
                'central_payment_id', 'pricing_source', 'pricing_revision',
                'refund_policy_revision', 'captured_amount',
            ]);
        });

        $schema->table('intl_payment_attempts', function (Blueprint $table): void {
            $table->dropColumn([
                'central_payment_id', 'prepare_operation_key', 'confirm_operation_key',
                'cancel_operation_key', 'pricing_source', 'pricing_revision',
                'refund_policy_revision', 'base_amount', 'central_fee_amount',
                'central_tax_amount', 'central_total_amount', 'captured_amount',
            ]);
        });

        $schema->table('intl_full_refunds', function (Blueprint $table): void {
            $table->dropColumn(['operation_key', 'central_refund_id']);
        });
    }
};
