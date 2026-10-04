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
            $table->decimal('total_value', 15, 2)->default(0.00);
            $table->string('status')->default('ACCEPTED');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rep_stocks');
    }
};
