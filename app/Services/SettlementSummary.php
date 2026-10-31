<?php

namespace App\Services;

use App\Models\AcceptedRequestItem;
use App\Models\Sale;
use App\Models\SalePayment;
use Illuminate\Support\Facades\DB;

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
        $openingProducts = $this->openingProducts($repId, $date);

        return ['date' => $date, 'status' => 'DRAFT', 'shops_visited_count' => $sales->pluck('shop_id')->filter()->unique()->count(),
            'total_sales_value' => (float) $sales->sum('total_amount'), 'total_cash_collected' => $cash,
            'total_cheques_collected' => $cheques, 'total_online_collected' => $online,
            'total_credit_issued' => (float) $creditIssued,
            'total_credit_collected' => (float) $payments->filter(fn ($payment) => $payment->notes === 'Credit collection')->sum('amount'),
            'ending_shop_credit' => (float) Sale::where('rep_id', $repId)->where('status', 'COMPLETED')->sum('due_amount'),
            'items_matrix' => array_values($matrix), 'opening_products' => $openingProducts,
            'opening_inventory_value' => round(collect($openingProducts)->sum('opening_value'), 2),
            'day_started' => null, 'day_start_status' => 'Not recorded',
            'is_saved' => false];
    }

    /**
     * Reconstruct the stock available to the rep at the start of a day.
     * Current stock is rolled back by adding allocations made on/after that day.
     * This also works for seeded opening stock, which has no stock-transfer row.
     */
    public function openingProducts(int $repId, string $date): array
    {
        $allStockRows = AcceptedRequestItem::query()
            ->with('item')
            ->whereHas('repStock', fn ($query) => $query->where('rep_id', $repId))
            ->get();

        if ($allStockRows->isEmpty()) {
            return [];
        }

        $stockRows = $allStockRows->filter(fn ($row) => $row->created_at?->toDateString() <= $date);

        if ($stockRows->isEmpty()) {
            return [];
        }

        $allocationTotals = DB::table('sale_stock_allocations')
            ->join('sales', 'sales.id', '=', 'sale_stock_allocations.sale_id')
            ->whereIn('sale_stock_allocations.accepted_request_item_id', $stockRows->modelKeys())
            ->whereDate('sales.sale_date', '>=', $date)
            ->where('sales.status', 'COMPLETED')
            ->selectRaw('sale_stock_allocations.accepted_request_item_id as allocation_item_id, SUM(sale_stock_allocations.quantity) as allocated_quantity')
            ->groupBy('sale_stock_allocations.accepted_request_item_id')
            ->pluck('allocated_quantity', 'allocation_item_id');

        return $stockRows
            ->groupBy('item_id')
            ->map(function ($rows, $itemId) use ($allocationTotals, $allStockRows) {
                $first = $rows->first();
                $openingQuantity = $rows->sum(fn ($row) => (int) $row->quantity
                    + (int) ($allocationTotals[$row->id] ?? 0));
                $currentQuantity = $allStockRows->where('item_id', $itemId)->sum('quantity');
                $unitPrice = (float) ($first->item?->selling_price ?? 0);

                return [
                    'item_id' => (int) $itemId,
                    'item_name' => $first->item?->name ?? 'Item',
                    'item_code' => $first->item?->code,
                    'before_quantity' => $openingQuantity,
                    'yesterday_final_quantity' => $openingQuantity,
                    'opening_quantity' => $openingQuantity,
                    'current_quantity' => (int) $currentQuantity,
                    'unit_price' => $unitPrice,
                    'opening_value' => round($openingQuantity * $unitPrice, 2),
                    'unit_value' => $unitPrice,
                    'today_total_value' => round($currentQuantity * $unitPrice, 2),
                ];
            })
            ->values()
            ->all();
    }
}
