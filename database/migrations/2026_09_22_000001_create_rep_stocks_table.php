<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rep_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rep_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('branch_id')->constrained('branches')->onDelete('cascade');
            $table->foreignId('item_id')->constrained('items')->onDelete('cascade');
            $table->integer('quantity')->default(0);
            $table->integer('reserved_quantity')->default(0);
            $table->decimal('unit_cost', 12, 2)->default(0.00);
            $table->decimal('unit_price', 12, 2)->default(0.00);
            $table->decimal('total_value', 15, 2)->default(0.00);
            $table->string('batch_number')->nullable();
            $table->enum('status', ['IN_STOCK', 'LOW_STOCK', 'OUT_OF_STOCK'])->default('IN_STOCK');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_audited_at')->nullable();
            $table->timestamps();

            $table->unique(['rep_id', 'item_id', 'batch_number'], 'unique_rep_item_batch');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rep_stocks');
    }
};
