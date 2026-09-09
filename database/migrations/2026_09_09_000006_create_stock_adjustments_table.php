<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('adjustment_code')->unique();
            $table->enum('target_type', ['BRANCH_WAREHOUSE', 'DSR_REP']);
            $table->foreignId('branch_id')->constrained('branches')->onDelete('cascade');
            $table->foreignId('referrer_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('item_id')->constrained('items')->onDelete('cascade');
            $table->enum('adjustment_type', [
                'DEDUCT_DAMAGE',
                'RECONCILIATION_LOSS',
                'RETURN_TO_WAREHOUSE',
                'ADD_STOCK'
            ]);
            $table->integer('quantity_delta');
            $table->text('reason');
            $table->string('adjusted_by');
            $table->timestamp('adjustment_date');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustments');
    }
};
