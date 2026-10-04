<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(AgentSeeder::class);
        $this->call(ItemStockSeeder::class);

        // User::factory(10)->create();

        $testRep = User::firstOrCreate(
            ['email' => 'test@example.com'],
            User::factory()->raw([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ])
        );

        $branch = Branch::where('code', 'WH-001')->firstOrFail();
        $profile = $testRep->dsrProfile()->firstOrCreate([], [
            'rep_code' => 'TEST-REP-001',
            'branch_id' => $branch->id,
            'dsr_status' => 'ACTIVE',
        ]);

        if (!$profile->branch_id) {
            $profile->update(['branch_id' => $branch->id]);
        }

        $this->call(RepStockSeeder::class);
    }
}
