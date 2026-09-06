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
        // 1. Core Users Table
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('phone')->nullable();
            $table->enum('role', ['AGENT', 'DSR_REP', 'SYSTEM_ADMIN'])->default('DSR_REP');
            $table->string('status')->default('ACTIVE');
            $table->rememberToken();
            $table->timestamps();
        });

        // 2. Agent Specific Profile
        Schema::create('agent_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('company_name');
            $table->string('reg_no')->nullable();
            $table->string('currency', 10)->default('LKR');
            $table->timestamps();
        });

        // 3. DSR Sales Rep Specific Profile (Foreign keys to branches/routes decoupled from order)
        Schema::create('dsr_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('rep_code')->unique();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('assigned_route_id')->nullable();
            $table->decimal('basic_salary', 12, 2)->default(0.00);
            $table->decimal('bike_allowance', 12, 2)->default(0.00);
            $table->decimal('fuel_allowance', 12, 2)->default(0.00);
            $table->enum('dsr_status', ['ACTIVE', 'INACTIVE', 'ON_TRIP'])->default('ACTIVE');
            $table->timestamps();
        });

        // 4. Password Reset Tokens
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        // 5. Session Storage Table
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index()->constrained('users')->onDelete('cascade');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('dsr_profiles');
        Schema::dropIfExists('agent_profiles');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
