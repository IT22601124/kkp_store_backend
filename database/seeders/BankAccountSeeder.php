<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\BankAccount;
use App\Models\User;

class BankAccountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Find agent user if exists
        $agentUser = User::where('role', 'AGENT')->first()
            ?? User::where('email', 'agent@hutch.lk')->first();
        $agentId = $agentUser ? $agentUser->id : null;

        $accounts = [
            [
                'bank_name'       => 'Commercial Bank of Ceylon',
                'account_number'  => '1000293847',
                'account_name'    => 'KKP Hutchison Telecommunications Agency - Main',
                'branch_name'     => 'Colombo Fort',
                'account_type'    => 'CURRENT',
                'currency'        => 'LKR',
                'opening_balance' => 150000.00,
                'current_balance' => 150000.00,
                'is_default'      => true,
                'is_active'       => true,
                'notes'           => 'Primary agency operating account for Hutch reload, SIM collections and main deposits.',
            ],
            [
                'bank_name'       => 'Sampath Bank',
                'account_number'  => '002910004567',
                'account_name'    => 'KKP Hutchison Agency - Collections',
                'branch_name'     => 'City Office Branch',
                'account_type'    => 'CURRENT',
                'currency'        => 'LKR',
                'opening_balance' => 100000.00,
                'current_balance' => 100000.00,
                'is_default'      => false,
                'is_active'       => true,
                'notes'           => 'Secondary agency collections account for DSR bank deposits and electronic transfers.',
            ],
            [
                'bank_name'       => 'Bank of Ceylon (BOC)',
                'account_number'  => '000876543201',
                'account_name'    => 'KKP Hutchison Agency - Revenue',
                'branch_name'     => 'Corporate Branch',
                'account_type'    => 'CURRENT',
                'currency'        => 'LKR',
                'opening_balance' => 75000.00,
                'current_balance' => 75000.00,
                'is_default'      => false,
                'is_active'       => true,
                'notes'           => 'BOC corporate account for state banking, regional transfers and bill collections.',
            ],
            [
                'bank_name'       => 'Hatton National Bank (HNB)',
                'account_number'  => '009823456789',
                'account_name'    => 'KKP Hutchison Agency - Field Settlement',
                'branch_name'     => 'Head Office Branch',
                'account_type'    => 'CURRENT',
                'currency'        => 'LKR',
                'opening_balance' => 50000.00,
                'current_balance' => 50000.00,
                'is_default'      => false,
                'is_active'       => true,
                'notes'           => 'Field rep mobile banking, card collections & cheque clearing account.',
            ],
        ];

        foreach ($accounts as $data) {
            BankAccount::updateOrCreate(
                ['account_number' => $data['account_number']],
                array_merge($data, ['user_id' => $agentId])
            );
        }

        $this->command->info('4 Agent bank accounts seeded successfully!');
    }
}
