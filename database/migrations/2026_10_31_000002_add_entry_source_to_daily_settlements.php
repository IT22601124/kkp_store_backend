<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_settlements', function (Blueprint $table) {
            $table->string('entry_source')->default('MOBILE')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('daily_settlements', function (Blueprint $table) {
            $table->dropColumn('entry_source');
        });
    }
};
