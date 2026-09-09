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
        if (Schema::hasTable('branches') && !Schema::hasColumn('branches', 'total_warehouse_stock_value')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->decimal('total_warehouse_stock_value', 15, 2)->default(0.00)->after('address');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('branches') && Schema::hasColumn('branches', 'total_warehouse_stock_value')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->dropColumn('total_warehouse_stock_value');
            });
        }
    }
};
