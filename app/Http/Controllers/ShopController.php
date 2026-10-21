<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Models\Route;
use App\Models\ShopGpsLocation;
use Illuminate\Http\Request;
use App\Services\RepAccess;
use App\Models\Sale;
use App\Models\SalePayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShopController extends Controller
{
    private function formatShop(Shop $shop): array
    {
        return [
            'id' => (int)$shop->id,
            'route_id' => $shop->route_id ? (int)$shop->route_id : 1,
            'routeId' => $shop->route_id ? (int)$shop->route_id : 1,
            'shop_code' => $shop->shop_code,
            'shopCode' => $shop->shop_code,
            'shop_name' => $shop->shop_name,
            'shopName' => $shop->shop_name,
            'owner_name' => $shop->owner_name ?? '',
            'ownerName' => $shop->owner_name ?? '',
            'phone' => $shop->phone ?? '',
            'address' => $shop->address ?? '',
            'latitude' => $shop->latitude !== null ? (float)$shop->latitude : null,
            'longitude' => $shop->longitude !== null ? (float)$shop->longitude : null,
            'credit_limit' => (float)$shop->credit_limit,
            'creditLimit' => (float)$shop->credit_limit,
            'current_credit_balance' => (float)$shop->current_credit_balance,
            'currentCreditBalance' => (float)$shop->current_credit_balance,
            'status' => strtoupper($shop->status ?? 'GOOD'),
            'created_by' => $shop->created_by ? (int)$shop->created_by : null,
            'createdBy' => $shop->created_by ? (int)$shop->created_by : null,
            'created_at' => $shop->created_at,
            'updated_at' => $shop->updated_at
        ];
    }

    public function index(Request $request)
    {
        return app(\App\Http\Controllers\v1\MobileShopController::class)->index($request);
    }

    public function store(Request $request)
    {
        return app(\App\Http\Controllers\v1\MobileShopController::class)->store($request);
    }

    public function show(Request $request, $id)
    {
        return app(\App\Http\Controllers\v1\MobileShopController::class)->show($request, (int) $id);
    }

    public function update(Request $request, $id)
    {
        return app(\App\Http\Controllers\v1\MobileShopController::class)->update($request, (int) $id);
    }

    public function destroy(Request $request, $id)
    {
        return app(\App\Http\Controllers\v1\MobileShopController::class)->destroy($request, (int) $id);
    }

    public function settleCredit(Request $request, $id)
    {
        $data = $request->validate(['amount' => 'required|numeric|min:0.01|decimal:0,2']);
        $shop = RepAccess::shop($request->user(), (int) $id);
        DB::transaction(function () use ($shop, $request, $data) {
            $shop = Shop::whereKey($shop->id)->lockForUpdate()->firstOrFail();
            $invoices = Sale::where('shop_id', $shop->id)->where('status', 'COMPLETED')->where('due_amount', '>', 0)
                ->when($request->user()->role === 'DSR_REP', fn ($q) => $q->where('rep_id', $request->user()->id))
                ->orderBy('sale_date')->orderBy('id')->lockForUpdate()->get();
            $remaining = (int) round((float) $data['amount'] * 100);
            if ($remaining > (int) round((float) $invoices->sum('due_amount') * 100)
                || $remaining > (int) round((float) $shop->current_credit_balance * 100)) {
                throw ValidationException::withMessages(['amount' => 'Amount exceeds your collectible invoice balance. Legacy shop debt needs reconciliation before collection.']);
            }
            foreach ($invoices as $sale) {
                $taken = min($remaining, (int) round((float) $sale->due_amount * 100));
                SalePayment::create([
                    'sale_id' => $sale->id, 'collected_by' => $request->user()->id,
                    'payment_type' => 'CASH', 'amount' => $taken / 100,
                    'payment_date' => now(), 'status' => 'CONFIRMED', 'notes' => 'Credit collection',
                ]);
                $sale->update(['paid_amount' => round((float) $sale->paid_amount + $taken / 100, 2),
                    'due_amount' => round((float) $sale->due_amount - $taken / 100, 2)]);
                $remaining -= $taken;
                if ($remaining === 0) {
                    break;
                }
            }
            $shop->decrement('current_credit_balance', (float) $data['amount']);
        });
        return response()->json(['success' => true, 'message' => 'Collection applied to oldest unpaid invoices', 'data' => $this->formatShop($shop->fresh())]);
    }
}
