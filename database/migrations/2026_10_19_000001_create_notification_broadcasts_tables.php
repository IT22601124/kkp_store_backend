<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_broadcasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->constrained('users');
            $table->string('title', 100);
            $table->text('body');
            $table->string('status')->default('queued');
            $table->unsignedInteger('total_reps');
            $table->unsignedInteger('skipped_reps');
            $table->unsignedInteger('targeted_devices');
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestamps();
        });

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('broadcast_id')->constrained('notification_broadcasts')->cascadeOnDelete();
            $table->text('device_token')->nullable();
            $table->string('status')->default('queued');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notification_broadcasts');
    }
};
