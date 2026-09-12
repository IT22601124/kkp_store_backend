<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dsr_trips', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('referrer_id');
            $table->unsignedBigInteger('route_id');
            $table->date('trip_date');
            $table->decimal('starting_inventory_value', 12, 2)->default(0.00);
            $table->decimal('issued_stock_value', 12, 2)->default(0.00);
            $table->decimal('total_sales_value', 12, 2)->default(0.00);
            $table->decimal('total_cash_collected', 12, 2)->default(0.00);
            $table->decimal('total_credit_sales', 12, 2)->default(0.00);
            $table->decimal('total_cheques_collected', 12, 2)->default(0.00);
            $table->decimal('total_bank_deposits', 12, 2)->default(0.00);
            $table->decimal('closing_inventory_value', 12, 2)->default(0.00);
            $table->decimal('variance_amount', 12, 2)->default(0.00);
            $table->string('status')->default('SUBMITTED');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dsr_trips');
    }
};
