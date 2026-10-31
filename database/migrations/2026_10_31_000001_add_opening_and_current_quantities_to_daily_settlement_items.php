<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_settlement_items', function (Blueprint $table) {
            $table->integer('opening_day_quantity')->default(0)->after('sold_quantity');
            $table->integer('current_quantity')->default(0)->after('opening_day_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('daily_settlement_items', function (Blueprint $table) {
            $table->dropColumn(['opening_day_quantity', 'current_quantity']);
        });
    }
};
