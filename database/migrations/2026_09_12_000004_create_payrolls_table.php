<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payrolls', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('referrer_id');
            $table->string('pay_period');
            $table->decimal('basic_salary', 12, 2)->default(45000.00);
            $table->decimal('bike_allowance', 12, 2)->default(15000.00);
            $table->decimal('fuel_allowance', 12, 2)->default(10000.00);
            $table->decimal('earned_commission', 12, 2)->default(0.00);
            $table->decimal('total_deductions', 12, 2)->default(0.00);
            $table->decimal('net_payable', 12, 2)->default(70000.00);
            $table->string('payment_status')->default('DRAFT');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payrolls');
    }
};
