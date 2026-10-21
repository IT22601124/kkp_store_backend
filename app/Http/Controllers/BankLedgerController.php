<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\BankFundTransfer;
use App\Models\DsrBankDeposit;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BankLedgerController extends Controller
{
    public function deposits()
    {
        return response()->json(['success' => true, 'data' => DsrBankDeposit::with(['bankAccount', 'rep'])->latest('id')->get()]);
    }

    public function verifyDeposit(Request $request, int $id)
    {
        $data = $request->validate(['status' => 'required|in:VERIFIED,REJECTED', 'notes' => 'nullable|string|max:2000']);
        $deposit = DB::transaction(function () use ($request, $id, $data) {
            $deposit = DsrBankDeposit::findOrFail($id);
            // Serialize with settlement replacement, which also locks this owner first.
            if ($deposit->rep_id) {
                User::whereKey($deposit->rep_id)->lockForUpdate()->first();
            }
            $deposit = DsrBankDeposit::whereKey($id)->lockForUpdate()->firstOrFail();
            $account = BankAccount::whereKey($deposit->bank_account_id)->lockForUpdate()->firstOrFail();
            // Existing deposits were credited when recorded. Verification must never credit twice.
            $wasCredited = $deposit->status !== 'REJECTED';
            $willBeCredited = $data['status'] !== 'REJECTED';
            if ($wasCredited !== $willBeCredited) {
                $account->increment('current_balance', ($willBeCredited ? 1 : -1) * (float) $deposit->amount);
            }
            $deposit->update(['status' => $data['status'], 'verified_by' => $request->user()->id,
                'verified_at' => now(), 'notes' => $data['notes'] ?? $deposit->notes]);

            return $deposit->load(['bankAccount', 'rep']);
        }, 3);

        return response()->json(['success' => true, 'data' => $deposit]);
    }

    public function transfers()
    {
        return response()->json(['success' => true, 'data' => BankFundTransfer::with(['fromAccount', 'toAccount'])->latest('id')->get()]);
    }

    public function transfer(Request $request)
    {
        $data = $request->validate([
            'request_key' => 'required|uuid',
            'from_bank_account_id' => 'required|integer|exists:bank_accounts,id',
            'to_bank_account_id' => 'required|integer|different:from_bank_account_id|exists:bank_accounts,id',
            'amount' => 'required|numeric|min:0.01|decimal:0,2',
            'reference' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:2000',
        ]);
        $transfer = DB::transaction(function () use ($data, $request) {
            $accounts = BankAccount::whereIn('id', [$data['from_bank_account_id'], $data['to_bank_account_id']])
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $existing = BankFundTransfer::where('request_key', $data['request_key'])->first();
            if ($existing) {
                if ($existing->created_by !== $request->user()->id
                    || (int) $existing->from_bank_account_id !== (int) $data['from_bank_account_id']
                    || (int) $existing->to_bank_account_id !== (int) $data['to_bank_account_id']
                    || (int) round((float) $existing->amount * 100) !== (int) round((float) $data['amount'] * 100)
                    || $existing->reference !== ($data['reference'] ?? null)
                    || $existing->notes !== ($data['notes'] ?? null)) {
                    throw ValidationException::withMessages(['request_key' => 'This request key belongs to a different transfer.']);
                }

                return $existing;
            }
            $from = $accounts[$data['from_bank_account_id']];
            $to = $accounts[$data['to_bank_account_id']];
            if (!$from->is_active || !$to->is_active || $from->currency !== $to->currency) {
                throw ValidationException::withMessages(['accounts' => 'Use active accounts in the same currency.']);
            }
            if ((int) round((float) $from->current_balance * 100) < (int) round((float) $data['amount'] * 100)) {
                throw ValidationException::withMessages(['amount' => 'Insufficient source account balance.']);
            }
            $from->decrement('current_balance', (float) $data['amount']);
            $to->increment('current_balance', (float) $data['amount']);

            return BankFundTransfer::create($data + ['created_by' => $request->user()->id]);
        }, 3);

        return response()->json(['success' => true, 'data' => $transfer->load(['fromAccount', 'toAccount'])], 201);
    }
}
