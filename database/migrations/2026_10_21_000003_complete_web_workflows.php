<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->foreignId('request_id')->nullable()->unique()->constrained('stock_requests')->restrictOnDelete();
            $table->foreignId('rep_stock_id')->nullable()->constrained('rep_stocks')->restrictOnDelete();
            $table->uuid('request_key')->nullable()->unique();
            $table->string('request_hash', 64)->nullable();
        });
        Schema::create('system_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value');
            $table->timestamps();
        });
        Schema::table('daily_settlements', function (Blueprint $table) {
            $table->decimal('cash_counted', 12, 2)->nullable();
            $table->decimal('total_credit_collected', 12, 2)->default(0);
            $table->foreignId('audited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('audited_at')->nullable();
        });
        Schema::create('bank_fund_transfers', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_key')->unique();
            $table->foreignId('from_bank_account_id')->constrained('bank_accounts')->restrictOnDelete();
            $table->foreignId('to_bank_account_id')->constrained('bank_accounts')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::table('dsr_bank_deposits', function (Blueprint $table) {
            $table->uuid('request_key')->nullable()->unique();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
        });
        Schema::table('payrolls', function (Blueprint $table) {
            $table->decimal('fuel_advances', 12, 2)->default(0);
            $table->decimal('epf_etf_deduction', 12, 2)->default(0);
            $table->json('commission_breakdown')->nullable();
            $table->date('payment_date')->nullable();
        });
        Schema::create('rep_stock_adjustment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_adjustment_id')->constrained('stock_adjustments')->restrictOnDelete();
            $table->foreignId('accepted_request_item_id')->constrained('accepted_request_items')->restrictOnDelete();
            $table->integer('quantity_before');
            $table->integer('quantity_change');
            $table->integer('quantity_after');
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('request_id');
            $table->dropConstrainedForeignId('rep_stock_id');
            $table->dropUnique(['request_key']);
            $table->dropColumn(['request_key', 'request_hash']);
        });
        Schema::dropIfExists('system_settings');
        Schema::table('daily_settlements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('audited_by');
            $table->dropColumn(['cash_counted', 'total_credit_collected', 'audited_at']);
        });
        Schema::dropIfExists('rep_stock_adjustment_lines');
        Schema::table('payrolls', fn (Blueprint $table) => $table->dropColumn(['fuel_advances', 'epf_etf_deduction', 'commission_breakdown', 'payment_date']));
        Schema::table('dsr_bank_deposits', function (Blueprint $table) {
            $table->dropUnique(['request_key']);
            $table->dropColumn('request_key');
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn('verified_at');
        });
        Schema::dropIfExists('bank_fund_transfers');
    }
};
