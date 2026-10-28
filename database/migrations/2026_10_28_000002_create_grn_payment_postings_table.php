<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grn_payment_postings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_received_note_id')->unique()->constrained('goods_received_notes')->cascadeOnDelete();
            $table->string('payment_method', 20);
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->timestamps();
        });
        Schema::table('goods_received_notes', function (Blueprint $table) {
            $table->uuid('request_key')->nullable()->unique();
            $table->string('request_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grn_payment_postings');
        Schema::table('goods_received_notes', function (Blueprint $table) {
            $table->dropUnique(['request_key']);
            $table->dropColumn(['request_key', 'request_hash']);
        });
    }
};
