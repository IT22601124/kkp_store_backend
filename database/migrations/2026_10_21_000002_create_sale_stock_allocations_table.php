<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_stock_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->foreignId('accepted_request_item_id')->constrained('accepted_request_items')->restrictOnDelete();
            $table->unsignedInteger('quantity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_stock_allocations');
    }
};
