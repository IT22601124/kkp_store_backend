<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('targets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('referrer_id');
            $table->string('target_month');
            $table->decimal('target_amount', 12, 2)->default(0.00);
            $table->decimal('achieved_amount', 12, 2)->default(0.00);
            $table->decimal('target_units', 12, 2)->default(0.00);
            $table->decimal('achieved_units', 12, 2)->default(0.00);
            $table->decimal('commission_rate_percent', 5, 2)->default(2.50);
            $table->string('status')->default('ACTIVE');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('targets');
    }
};
