<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_received_notes', function (Blueprint $table) {
            $table->string('payment_method', 20)->nullable();
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('goods_received_notes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bank_account_id');
            $table->dropColumn('payment_method');
        });
    }
};
