<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('daily_settlements', function (Blueprint $table) {
            $table->id();
            $table->string('settlement_code')->unique();
            $table->foreignId('rep_id')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('dsr_trip_id')->nullable()->constrained('dsr_trips')->onDelete('set null');
            $table->date('settlement_date');
            $table->integer('shops_visited_count')->default(0);
            $table->decimal('total_sales_value', 12, 2)->default(0);
            $table->decimal('total_cash_collected', 12, 2)->default(0);
            $table->decimal('total_cheques_collected', 12, 2)->default(0);
            $table->decimal('total_online_collected', 12, 2)->default(0);
            $table->decimal('total_credit_issued', 12, 2)->default(0);
            $table->decimal('ending_shop_credit', 12, 2)->default(0);
            $table->string('status')->default('SUBMITTED'); // PENDING, SUBMITTED, APPROVED, REJECTED
            $table->text('notes')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('daily_settlement_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_settlement_id')->constrained('daily_settlements')->onDelete('cascade');
            $table->foreignId('item_id')->nullable()->constrained('items')->onDelete('set null');
            $table->string('item_name');
            $table->string('item_code')->nullable();
            $table->integer('sold_quantity')->default(0);
            $table->decimal('total_revenue', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_settlement_items');
        Schema::dropIfExists('daily_settlements');
    }
};
