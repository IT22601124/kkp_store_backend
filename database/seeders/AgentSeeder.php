<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AgentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Create the Agent User Account
        $user = User::create([
            'name'              => 'Lanka Telecom Agency Admin',
            'email'             => 'agent@hutch.lk',
            'password'          => Hash::make('password'),
            'phone'             => '+94761234567',
            'role'              => 'AGENT',
            'status'            => 'ACTIVE',
            'email_verified_at' => now(),
        ]);

        // 2. Create the Associated Agent Profile
        DB::table('agent_profiles')->insert([
            'user_id'      => $user->id,
            'company_name' => 'Hutchison Telecommunications Lanka Agency',
            'reg_no'       => 'PV-98765-AGENT',
            'currency'     => 'LKR',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $this->command->info('Agent user & profile seeded successfully!');
    }
}
