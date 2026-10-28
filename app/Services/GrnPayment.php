<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\GoodsReceivedNote;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GrnPayment
{
    // Called inside the stock transaction, with the GRN locked for edits/deletion.
    public function sync(GoodsReceivedNote $grn, bool $delete = false): void
    {
        $previous = DB::table('grn_payment_postings')->where('goods_received_note_id', $grn->id)->lockForUpdate()->first();
        $method = $delete ? null : $grn->payment_method;
        $bankId = in_array($method, ['BANK', 'CHEQUE'], true) ? $grn->bank_account_id : null;
        $amount = (int) round((float) $grn->total_value * 100);

        $deltas = [];
        if ($previous?->bank_account_id) {
            $deltas[$previous->bank_account_id] = (int) round((float) $previous->amount * 100);
        }
        if ($bankId) {
            $deltas[$bankId] = ($deltas[$bankId] ?? 0) - $amount;
        }
        $accounts = BankAccount::whereIn('id', array_keys($deltas))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($deltas as $id => $delta) {
            if ($delta === 0) continue;
            $account = $accounts->get($id);
            if (!$account) {
                throw ValidationException::withMessages(['bank_account_id' => 'The payment bank account no longer exists.']);
            }
            if ($delta < 0 && (!$account->is_active || $account->currency !== 'LKR')) {
                throw ValidationException::withMessages(['bank_account_id' => 'Use an active LKR bank account for GRN payments.']);
            }
            $balance = (int) round((float) $account->current_balance * 100) + $delta;
            if ($delta < 0 && $balance < 0) {
                throw ValidationException::withMessages(['bank_account_id' => "Insufficient balance in {$account->bank_name} for this GRN payment."]);
            }
            $account->update(['current_balance' => number_format($balance / 100, 2, '.', '')]);
        }

        if (in_array($method, ['BANK', 'CHEQUE', 'CASH'], true)) {
            DB::table('grn_payment_postings')->updateOrInsert(['goods_received_note_id' => $grn->id], [
                'payment_method' => $method, 'bank_account_id' => $bankId,
                'amount' => number_format($amount / 100, 2, '.', ''),
                'created_at' => $previous?->created_at ?? now(), 'updated_at' => now(),
            ]);
        } else {
            DB::table('grn_payment_postings')->where('goods_received_note_id', $grn->id)->delete();
        }
    }
}
