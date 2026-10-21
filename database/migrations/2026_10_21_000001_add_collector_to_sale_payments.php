<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->foreignId('collected_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('collected_by');
        });
    }
};
