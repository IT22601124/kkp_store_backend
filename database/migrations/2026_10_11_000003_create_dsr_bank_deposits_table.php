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
        Schema::create('dsr_bank_deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained('bank_accounts')->onDelete('cascade');
            $table->foreignId('rep_id')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('dsr_trip_id')->nullable()->constrained('dsr_trips')->onDelete('cascade');
            $table->foreignId('daily_settlement_id')->nullable()->constrained('daily_settlements')->onDelete('cascade');
            $table->date('deposit_date');
            $table->decimal('amount', 14, 2)->default(0.00);
            $table->string('slip_reference')->nullable();
            $table->string('deposit_slip_image')->nullable();
            $table->string('status')->default('CONFIRMED'); // PENDING, CONFIRMED, VERIFIED
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dsr_bank_deposits');
    }
};
