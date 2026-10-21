<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\SalePayment;

class SettlementSummary
{
    public function forRep(int $repId, string $date): array
    {
        $sales = Sale::with(['salesItems.item', 'payments'])->where('rep_id', $repId)
            ->where('status', 'COMPLETED')->whereDate('sale_date', $date)->get();
        $payments = SalePayment::with('sale')->whereDate('payment_date', $date)
            ->where('status', '!=', 'REJECTED')->where('payment_type', '!=', 'CREDIT')
            ->whereHas('sale', fn ($query) => $query->where('status', 'COMPLETED'))
            ->where(fn ($query) => $query->where('collected_by', $repId)->orWhere(fn ($legacy) =>
                $legacy->whereNull('collected_by')->whereHas('sale', fn ($query) => $query->where('rep_id', $repId))))->get();
        $cash = (float) $payments->where('payment_type', 'CASH')->sum('amount');
        $cheques = (float) $payments->where('payment_type', 'CHEQUE')->sum('amount');
        $online = (float) $payments->whereIn('payment_type', ['ONLINE', 'CARD', 'BANK_TRANSFER', 'TRANSFER'])->sum('amount');
        foreach ($sales->filter(fn ($sale) => $sale->payments->isEmpty()) as $sale) {
            match ($sale->payment_type) {
                'CASH' => $cash += (float) $sale->paid_amount,
                'CHEQUE' => $cheques += (float) $sale->paid_amount,
                'ONLINE', 'CARD', 'BANK_TRANSFER', 'TRANSFER' => $online += (float) $sale->paid_amount,
                default => null,
            };
        }
        $matrix = [];
        foreach ($sales as $sale) {
            foreach ($sale->salesItems as $line) {
                $matrix[$line->item_id] ??= ['item_id' => $line->item_id, 'name' => $line->item?->name ?? 'Item',
                    'code' => $line->item?->code, 'sold_qty' => 0, 'total_revenue' => 0];
                $matrix[$line->item_id]['sold_qty'] += $line->quantity;
                $matrix[$line->item_id]['total_revenue'] += (float) $line->subtotal;
            }
        }
        $creditIssued = $sales->sum(function ($sale) use ($date) {
            if ($sale->payments->isEmpty()) return (float) $sale->due_amount;
            $paid = $sale->payments->filter(fn ($payment) => $payment->status !== 'REJECTED'
                && $payment->payment_type !== 'CREDIT' && $payment->payment_date?->format('Y-m-d') === $date)->sum('amount');
            return max(0, (float) $sale->total_amount - $paid);
        });
        return ['date' => $date, 'status' => 'DRAFT', 'shops_visited_count' => $sales->pluck('shop_id')->filter()->unique()->count(),
            'total_sales_value' => (float) $sales->sum('total_amount'), 'total_cash_collected' => $cash,
            'total_cheques_collected' => $cheques, 'total_online_collected' => $online,
            'total_credit_issued' => (float) $creditIssued,
            'total_credit_collected' => (float) $payments->filter(fn ($payment) => $payment->notes === 'Credit collection')->sum('amount'),
            'ending_shop_credit' => (float) Sale::where('rep_id', $repId)->where('status', 'COMPLETED')->sum('due_amount'),
            'items_matrix' => array_values($matrix), 'is_saved' => false];
    }
}
