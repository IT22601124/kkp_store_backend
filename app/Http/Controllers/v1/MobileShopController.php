<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Shop;
use App\Models\ShopGpsLocation;
use App\Models\Route;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;
use App\Services\RepAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MobileShopController extends Controller
{
    use ApiResponse;

    /**
     * Format shop for mobile API response (providing both snake_case and camelCase keys).
     */
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

    /**
     * Get list of shops for mobile application.
     */
    public function index(Request $request)
    {
        try {
            $query = RepAccess::shops(Shop::query(), $request->user())->orderBy('id', 'desc');

            if ($request->has('route_id') || $request->has('routeId')) {
                $routeId = $request->input('route_id') ?? $request->input('routeId');
                $query->where('route_id', $routeId);
            }

            if ($request->has('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('shop_name', 'like', "%{$search}%")
                      ->orWhere('shop_code', 'like', "%{$search}%")
                      ->orWhere('owner_name', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%");
                });
            }

            $shops = $query->get();
            $formatted = $shops->map(fn($s) => $this->formatShop($s));

            return $this->successResponse(
                $formatted,
                'Shops retrieved successfully',
                200
            );
        } catch (Throwable $th) {
            Log::error('MobileShopController index error: ' . $th->getMessage());
            return $this->errorResponse(
                'Failed to retrieve shops',
                500,
                $th->getMessage()
            );
        }
    }

    /**
     * Create a new shop for mobile application.
     */
    private function shopInput(Request $request, ?Shop $shop = null): array
    {
        foreach (['shop_name' => 'shopName', 'owner_name' => 'ownerName', 'route_id' => 'routeId',
            'shop_code' => 'shopCode', 'credit_limit' => 'creditLimit', 'current_credit_balance' => 'currentCreditBalance'] as $key => $alias) {
            if (!$request->has($key) && $request->has($alias)) {
                $request->merge([$key => $request->input($alias)]);
            }
        }
        $data = $request->validate([
            'shop_name' => ($shop ? 'sometimes' : 'required').'|string|max:255',
            'owner_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:30', 'address' => 'nullable|string|max:2000',
            'route_id' => 'nullable|integer|exists:routes,id',
            'latitude' => 'nullable|numeric|between:-90,90', 'longitude' => 'nullable|numeric|between:-180,180',
            'credit_limit' => 'nullable|numeric|min:0',
            'current_credit_balance' => 'nullable|numeric|min:0',
            'shop_code' => 'nullable|string|max:100|unique:shops,shop_code'.($shop ? ','.$shop->id : ''),
            'accuracy' => 'nullable|numeric|min:0',
            'status' => 'nullable|string|in:GOOD,OVERDUE,HOLD',
        ]);
        $user = $request->user();
        $routeId = $data['route_id'] ?? $shop?->route_id ?? $user->dsrProfile?->assigned_route_id;
        if (!$routeId) {
            throw ValidationException::withMessages(['route_id' => 'A valid route is required.']);
        }
        if ($user->role === 'DSR_REP' && (int) $routeId !== (int) $user->dsrProfile?->assigned_route_id
            && (!$shop || (int) $routeId !== (int) $shop->route_id)) {
            abort(403, 'Use your assigned route.');
        }
        if (isset($data['current_credit_balance']) && (float) $data['current_credit_balance'] !== (float) ($shop?->current_credit_balance ?? 0)) {
            throw ValidationException::withMessages(['current_credit_balance' => 'Record debt through invoices and collections.']);
        }
        unset($data['current_credit_balance']);
        $data['route_id'] = $routeId;
        return $data;
    }

    public function store(Request $request)
    {
        $data = $this->shopInput($request);
        $shop = DB::transaction(function () use ($data, $request) {
            $data['shop_code'] = $data['shop_code'] ?? 'SHP-'.strtoupper(bin2hex(random_bytes(8)));
            $data['created_by'] = $request->user()->id;
            $shop = Shop::create($data);
            $this->saveGps($shop, $request);
            return $shop;
        });
        return $this->successResponse($this->formatShop($shop), 'Shop created successfully', 201);
    }

    public function update(Request $request, int $id)
    {
        $shop = RepAccess::shop($request->user(), $id);
        $data = $this->shopInput($request, $shop);
        DB::transaction(function () use ($data, $shop, $request) {
            $shop->update($data);
            $this->saveGps($shop, $request);
        });
        return $this->successResponse($this->formatShop($shop->fresh()), 'Shop updated successfully');
    }

    private function saveGps(Shop $shop, Request $request): void
    {
        if ($shop->latitude !== null && $shop->longitude !== null) {
            ShopGpsLocation::create([
                'shop_id' => $shop->id, 'latitude' => $shop->latitude, 'longitude' => $shop->longitude,
                'accuracy' => $request->input('accuracy'), 'captured_by_user_id' => $request->user()->id,
                'address_text' => $shop->address ?? '', 'is_verified' => false,
            ]);
        }
    }

    public function show(Request $request, int $id)
    {
        return $this->successResponse($this->formatShop(RepAccess::shop($request->user(), $id)), 'Shop retrieved successfully');
    }

    public function destroy(Request $request, int $id)
    {
        $shop = RepAccess::shop($request->user(), $id);
        DB::transaction(function () use ($shop) {
            $shop = Shop::whereKey($shop->id)->lockForUpdate()->firstOrFail();
            if ((float) $shop->current_credit_balance > 0 || Sale::where('shop_id', $shop->id)->exists()) {
                throw ValidationException::withMessages(['shop' => 'Shops with invoice history or debt cannot be deleted.']);
            }
            ShopGpsLocation::where('shop_id', $shop->id)->delete();
            $shop->delete();
        });
        return $this->successResponse(['id' => $id], 'Shop deleted successfully');
    }

    /**
     * Fetch shops assigned to a specific route.
     * Uses query/input route_id or routeId, or falls back to the authenticated DSR rep's assigned_route_id.
     */
    public function getShopsByRoute(Request $request)
    {
        try {
            $user = $request->user();
            $routeId = $request->input('route_id') ?? $request->input('routeId');

            // Fallback to rep's assigned route from DSR profile if not explicitly passed
            if (!$routeId && $user && $user->dsrProfile) {
                $routeId = $user->dsrProfile->assigned_route_id;
            }

            if (!$routeId) {
                return $this->errorResponse('Route ID (route_id / routeId) is required or user has no assigned route', 422);
            }

            $shops = RepAccess::shops(Shop::query(), $request->user())->where('route_id', $routeId)
                ->orderBy('id', 'desc')
                ->get();

            $formatted = $shops->map(fn($s) => $this->formatShop($s));

            return $this->successResponse(
                $formatted,
                "Shops for route ID {$routeId} retrieved successfully",
                200
            );
        } catch (Throwable $th) {
            Log::error('MobileShopController getShopsByRoute error: ' . $th->getMessage());
            return $this->errorResponse(
                'Failed to retrieve shops for route',
                500,
                $th->getMessage()
            );
        }
    }

    /**
     * Format sale item for mobile response.
     */
    private function formatSaleItem($item): array
    {
        $itemObj = $item->item;
        return [
            'id' => (int)$item->id,
            'sale_id' => (int)$item->sale_id,
            'saleId' => (int)$item->sale_id,
            'item_id' => (int)$item->item_id,
            'itemId' => (int)$item->item_id,
            'item_name' => $itemObj ? ($itemObj->name ?? $itemObj->item_name) : "Item #{$item->item_id}",
            'itemName' => $itemObj ? ($itemObj->name ?? $itemObj->item_name) : "Item #{$item->item_id}",
            'item_code' => $itemObj ? ($itemObj->code ?? $itemObj->item_code) : "ITM-{$item->item_id}",
            'itemCode' => $itemObj ? ($itemObj->code ?? $itemObj->item_code) : "ITM-{$item->item_id}",
            'quantity' => (int)$item->quantity,
            'unit_price' => (float)$item->unit_price,
            'unitPrice' => (float)$item->unit_price,
            'discount' => (float)$item->discount,
            'subtotal' => (float)$item->subtotal,
            'batch_number' => $item->batch_number,
            'batchNumber' => $item->batch_number,
        ];
    }

    /**
     * Format sale payment for mobile response.
     */
    private function formatSalePayment($payment): array
    {
        return [
            'id' => (int)$payment->id,
            'sale_id' => (int)$payment->sale_id,
            'saleId' => (int)$payment->sale_id,
            'payment_type' => $payment->payment_type,
            'paymentType' => $payment->payment_type,
            'amount' => (float)$payment->amount,
            'bank_account_id' => $payment->bank_account_id ? (int)$payment->bank_account_id : null,
            'bankAccountId' => $payment->bank_account_id ? (int)$payment->bank_account_id : null,
            'reference_number' => $payment->reference_number,
            'referenceNumber' => $payment->reference_number,
            'payment_date' => $payment->payment_date ? (is_string($payment->payment_date) ? $payment->payment_date : $payment->payment_date->toIso8601String()) : null,
            'notes' => $payment->notes,
            'slip_image' => $payment->slip_image,
            'slip_url' => $payment->slip_url,
            'bank_slip_url' => $payment->bank_slip_url,
            'status' => $payment->status ?? 'CONFIRMED',
            'verified_at' => $payment->verified_at ? (is_string($payment->verified_at) ? $payment->verified_at : $payment->verified_at->toIso8601String()) : null,
            'verified_by' => $payment->verifiedBy ? [
                'id' => (int) $payment->verifiedBy->id,
                'name' => $payment->verifiedBy->name,
                'role' => $payment->verifiedBy->role,
            ] : null,
            'bank_account' => $payment->bankAccount ? [
                'id' => (int)$payment->bankAccount->id,
                'bank_name' => $payment->bankAccount->bank_name,
                'account_name' => $payment->bankAccount->account_name,
                'account_number' => $payment->bankAccount->account_number,
            ] : null,
        ];
    }

    /**
     * Format sale for mobile response.
     */
    private function formatSale(Sale $sale): array
    {
        $items = $sale->salesItems ?? collect();
        $payments = $sale->payments ?? collect();

        return [
            'id' => (int)$sale->id,
            'sale_code' => $sale->sale_code,
            'saleCode' => $sale->sale_code,
            'rep_id' => $sale->rep_id ? (int)$sale->rep_id : null,
            'repId' => $sale->rep_id ? (int)$sale->rep_id : null,
            'shop_id' => $sale->shop_id ? (int)$sale->shop_id : null,
            'shopId' => $sale->shop_id ? (int)$sale->shop_id : null,
            'branch_id' => $sale->branch_id ? (int)$sale->branch_id : null,
            'dsr_trip_id' => $sale->dsr_trip_id ? (int)$sale->dsr_trip_id : null,
            'sale_date' => $sale->sale_date ? (is_string($sale->sale_date) ? $sale->sale_date : $sale->sale_date->toIso8601String()) : null,
            'saleDate' => $sale->sale_date ? (is_string($sale->sale_date) ? $sale->sale_date : $sale->sale_date->toIso8601String()) : null,
            'payment_type' => $sale->payment_type,
            'paymentType' => $sale->payment_type,
            'status' => $sale->status,
            'subtotal' => (float)$sale->subtotal,
            'discount' => (float)$sale->discount,
            'tax' => (float)$sale->tax,
            'total_amount' => (float)$sale->total_amount,
            'totalAmount' => (float)$sale->total_amount,
            'paid_amount' => (float)$sale->paid_amount,
            'paidAmount' => (float)$sale->paid_amount,
            'due_amount' => (float)$sale->due_amount,
            'dueAmount' => (float)$sale->due_amount,
            'notes' => $sale->notes,
            'created_at' => $sale->created_at ? $sale->created_at->toIso8601String() : null,
            'items_count' => $items->count(),
            'itemsCount' => $items->count(),
            'items' => $items->map(fn($it) => $this->formatSaleItem($it))->values()->toArray(),
            'sales_items' => $items->map(fn($it) => $this->formatSaleItem($it))->values()->toArray(),
            'payments' => $payments->map(fn($p) => $this->formatSalePayment($p))->values()->toArray(),
        ];
    }

    /**
     * Get today's sales grouped with shop details for mobile app 'Today Shop' section.
     */
    public function getTodaySalesWithShops(Request $request)
    {
        try {
            $user = $request->user();
            $repId = $user ? $user->id : $request->query('rep_id');
            $date = $request->query('date', now()->format('Y-m-d'));
            if ($date === 'today') {
                $date = now()->format('Y-m-d');
            }

            $query = Sale::with(['shop', 'salesItems.item', 'payments.bankAccount', 'payments.verifiedBy'])
                ->whereDate('sale_date', $date);

            if ($repId) {
                $query->where('rep_id', $repId);
            }

            if ($request->has('shop_id')) {
                $query->where('shop_id', $request->query('shop_id'));
            }

            $allSales = $query->orderBy('id', 'desc')->get();

            // Group sales by shop
            $salesByShop = $allSales->groupBy('shop_id');
            $shopIds = $salesByShop->keys()->filter()->toArray();

            // Load shops
            $shopsQuery = Shop::whereIn('id', $shopIds)->orderBy('id', 'desc');

            if ($request->has('search')) {
                $search = $request->input('search');
                $shopsQuery->where(function ($q) use ($search) {
                    $q->where('shop_name', 'like', "%{$search}%")
                      ->orWhere('shop_code', 'like', "%{$search}%")
                      ->orWhere('owner_name', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%");
                });
            }

            $shops = $shopsQuery->get();

            $formattedShops = $shops->map(function (Shop $shop) use ($salesByShop) {
                $shopSales = $salesByShop->get($shop->id, collect());
                $formattedSales = $shopSales->map(fn($s) => $this->formatSale($s))->values()->toArray();

                $totalAmount = (float)$shopSales->sum('total_amount');
                $paidAmount = (float)$shopSales->sum('paid_amount');
                $dueAmount = (float)$shopSales->sum('due_amount');

                // Breakdown collections
                $cashCollected = 0.00;
                $chequeCollected = 0.00;
                $onlineCollected = 0.00;

                foreach ($shopSales as $sale) {
                    if ($sale->payments && $sale->payments->count() > 0) {
                        foreach ($sale->payments as $p) {
                            $amt = (float)$p->amount;
                            match (strtoupper($p->payment_type)) {
                                'CASH' => $cashCollected += $amt,
                                'CHEQUE' => $chequeCollected += $amt,
                                'ONLINE', 'CARD', 'BANK_TRANSFER', 'TRANSFER' => $onlineCollected += $amt,
                                default => null,
                            };
                        }
                    } else {
                        $pAmt = (float)$sale->paid_amount;
                        match (strtoupper($sale->payment_type)) {
                            'CASH' => $cashCollected += $pAmt,
                            'CHEQUE' => $chequeCollected += $pAmt,
                            'ONLINE', 'CARD', 'BANK_TRANSFER', 'TRANSFER' => $onlineCollected += $pAmt,
                            default => null,
                        };
                    }
                }

                $latestSale = $shopSales->first();
                $lastSaleTime = $latestSale ? ($latestSale->created_at ? $latestSale->created_at->toIso8601String() : ($latestSale->sale_date ? (is_string($latestSale->sale_date) ? $latestSale->sale_date : $latestSale->sale_date->toIso8601String()) : null)) : null;

                $shopSummary = [
                    'sales_count' => $shopSales->count(),
                    'salesCount' => $shopSales->count(),
                    'total_amount' => $totalAmount,
                    'totalAmount' => $totalAmount,
                    'paid_amount' => $paidAmount,
                    'paidAmount' => $paidAmount,
                    'due_amount' => $dueAmount,
                    'dueAmount' => $dueAmount,
                    'cash_collected' => $cashCollected,
                    'cashCollected' => $cashCollected,
                    'cheque_collected' => $chequeCollected,
                    'chequeCollected' => $chequeCollected,
                    'online_collected' => $onlineCollected,
                    'onlineCollected' => $onlineCollected,
                    'credit_issued' => $dueAmount,
                    'creditIssued' => $dueAmount,
                    'last_sale_time' => $lastSaleTime,
                    'lastSaleTime' => $lastSaleTime,
                ];

                $shopData = $this->formatShop($shop);
                $shopData['today_summary'] = $shopSummary;
                $shopData['todaySummary'] = $shopSummary;
                $shopData['today_sales_count'] = $shopSummary['sales_count'];
                $shopData['todaySalesCount'] = $shopSummary['sales_count'];
                $shopData['today_total_sales'] = $shopSummary['total_amount'];
                $shopData['todayTotalSales'] = $shopSummary['total_amount'];
                $shopData['today_paid_amount'] = $shopSummary['paid_amount'];
                $shopData['todayPaidAmount'] = $shopSummary['paid_amount'];
                $shopData['today_due_amount'] = $shopSummary['due_amount'];
                $shopData['todayDueAmount'] = $shopSummary['due_amount'];
                $shopData['sales'] = $formattedSales;
                $shopData['today_sales'] = $formattedSales;
                $shopData['todaySales'] = $formattedSales;

                return $shopData;
            })->values()->toArray();

            // Calculate overall summary metrics
            $totalSalesAmount = (float)$allSales->sum('total_amount');
            $totalPaidAmount = (float)$allSales->sum('paid_amount');
            $totalDueAmount = (float)$allSales->sum('due_amount');

            $overallCash = 0.00;
            $overallCheque = 0.00;
            $overallOnline = 0.00;
            $totalItemsSold = 0;

            foreach ($allSales as $s) {
                if ($s->payments && $s->payments->count() > 0) {
                    foreach ($s->payments as $p) {
                        $amt = (float)$p->amount;
                        match (strtoupper($p->payment_type)) {
                            'CASH' => $overallCash += $amt,
                            'CHEQUE' => $overallCheque += $amt,
                            'ONLINE', 'CARD', 'BANK_TRANSFER', 'TRANSFER' => $overallOnline += $amt,
                            default => null,
                        };
                    }
                } else {
                    $pAmt = (float)$s->paid_amount;
                    match (strtoupper($s->payment_type)) {
                        'CASH' => $overallCash += $pAmt,
                        'CHEQUE' => $overallCheque += $pAmt,
                        'ONLINE', 'CARD', 'BANK_TRANSFER', 'TRANSFER' => $overallOnline += $pAmt,
                        default => null,
                    };
                }

                if ($s->salesItems) {
                    $totalItemsSold += (int)$s->salesItems->sum('quantity');
                }
            }

            $overallSummary = [
                'total_shops_visited' => count($formattedShops),
                'totalShopsVisited' => count($formattedShops),
                'total_sales_count' => $allSales->count(),
                'totalSalesCount' => $allSales->count(),
                'total_sales_amount' => $totalSalesAmount,
                'totalSalesAmount' => $totalSalesAmount,
                'total_paid_amount' => $totalPaidAmount,
                'totalPaidAmount' => $totalPaidAmount,
                'total_due_amount' => $totalDueAmount,
                'totalDueAmount' => $totalDueAmount,
                'total_cash_collected' => $overallCash,
                'totalCashCollected' => $overallCash,
                'total_cheque_collected' => $overallCheque,
                'totalChequeCollected' => $overallCheque,
                'total_online_collected' => $overallOnline,
                'totalOnlineCollected' => $overallOnline,
                'total_credit_issued' => $totalDueAmount,
                'totalCreditIssued' => $totalDueAmount,
                'total_items_sold' => $totalItemsSold,
                'totalItemsSold' => $totalItemsSold,
            ];

            // Flat sales list with shop details attached
            $allFormattedSales = $allSales->map(function ($s) {
                $saleData = $this->formatSale($s);
                $saleData['shop'] = $s->shop ? $this->formatShop($s->shop) : null;
                return $saleData;
            })->values()->toArray();

            return $this->successResponse([
                'date' => $date,
                'rep_id' => $repId ? (int)$repId : null,
                'summary' => $overallSummary,
                'shops' => $formattedShops,
                'sales' => $allFormattedSales,
            ], 'Today sales with shops retrieved successfully', 200);

        } catch (Throwable $th) {
            Log::error('MobileShopController getTodaySalesWithShops error: ' . $th->getMessage());
            return $this->errorResponse('Failed to retrieve today sales with shops', 500, $th->getMessage());
        }
    }

    /**
     * Get today's sales for a specific shop.
     */
    public function getShopTodaySales(Request $request, $id)
    {
        try {
            $shop = RepAccess::shops(Shop::query(), $request->user())->find($id);
            if (!$shop) {
                return $this->errorResponse('Shop not found', 404);
            }

            $user = $request->user();
            $repId = $user ? $user->id : $request->query('rep_id');
            $date = $request->query('date', now()->format('Y-m-d'));
            if ($date === 'today') {
                $date = now()->format('Y-m-d');
            }

            $query = Sale::with(['salesItems.item', 'payments.bankAccount', 'payments.verifiedBy', 'rep'])
                ->where('shop_id', $id)
                ->whereDate('sale_date', $date);

            if ($repId) {
                $query->where('rep_id', $repId);
            }

            $sales = $query->orderBy('id', 'desc')->get();
            $formattedSales = $sales->map(fn($s) => $this->formatSale($s))->values()->toArray();

            $totalSales = (float)$sales->sum('total_amount');
            $totalPaid = (float)$sales->sum('paid_amount');
            $totalDue = (float)$sales->sum('due_amount');

            return $this->successResponse([
                'date' => $date,
                'shop' => $this->formatShop($shop),
                'today_summary' => [
                    'sales_count' => $sales->count(),
                    'salesCount' => $sales->count(),
                    'total_amount' => $totalSales,
                    'totalAmount' => $totalSales,
                    'paid_amount' => $totalPaid,
                    'paidAmount' => $totalPaid,
                    'due_amount' => $totalDue,
                    'dueAmount' => $totalDue,
                ],
                'sales' => $formattedSales,
            ], 'Shop today sales retrieved successfully', 200);
        } catch (Throwable $th) {
            Log::error('MobileShopController getShopTodaySales error: ' . $th->getMessage());
            return $this->errorResponse('Failed to retrieve shop today sales', 500, $th->getMessage());
        }
    }

    /**
     * Get shops visited today (where a sale was created today for this rep).
     */
    public function getTodayVisitedShops(Request $request)
    {
        try {
            $user = $request->user();
            $repId = $user ? $user->id : $request->query('rep_id');
            $date = $request->query('date', now()->format('Y-m-d'));
            if ($date === 'today') {
                $date = now()->format('Y-m-d');
            }

            $query = Sale::with(['salesItems.item', 'payments.bankAccount', 'payments.verifiedBy'])
                ->whereDate('sale_date', $date);

            if ($repId) {
                $query->where('rep_id', $repId);
            }

            $sales = $query->get();
            $salesByShop = $sales->groupBy('shop_id');
            $shopIds = $salesByShop->keys()->filter()->toArray();

            $shops = Shop::whereIn('id', $shopIds)->orderBy('id', 'desc')->get();
            $formatted = $shops->map(function (Shop $s) use ($salesByShop) {
                $shopSales = $salesByShop->get($s->id, collect());
                $formattedSales = $shopSales->map(fn($sale) => $this->formatSale($sale))->values()->toArray();

                $shopData = $this->formatShop($s);
                $shopData['today_summary'] = [
                    'sales_count' => $shopSales->count(),
                    'salesCount' => $shopSales->count(),
                    'total_amount' => (float)$shopSales->sum('total_amount'),
                    'totalAmount' => (float)$shopSales->sum('total_amount'),
                    'paid_amount' => (float)$shopSales->sum('paid_amount'),
                    'paidAmount' => (float)$shopSales->sum('paid_amount'),
                    'due_amount' => (float)$shopSales->sum('due_amount'),
                    'dueAmount' => (float)$shopSales->sum('due_amount'),
                ];
                $shopData['todaySummary'] = $shopData['today_summary'];
                $shopData['today_sales_count'] = $shopSales->count();
                $shopData['todaySalesCount'] = $shopSales->count();
                $shopData['today_total_sales'] = (float)$shopSales->sum('total_amount');
                $shopData['todayTotalSales'] = (float)$shopSales->sum('total_amount');
                $shopData['today_paid_amount'] = (float)$shopSales->sum('paid_amount');
                $shopData['todayPaidAmount'] = (float)$shopSales->sum('paid_amount');
                $shopData['today_due_amount'] = (float)$shopSales->sum('due_amount');
                $shopData['todayDueAmount'] = (float)$shopSales->sum('due_amount');
                $shopData['sales'] = $formattedSales;
                $shopData['today_sales'] = $formattedSales;
                $shopData['todaySales'] = $formattedSales;

                return $shopData;
            });

            return $this->successResponse(
                $formatted,
                'Today visited shops retrieved successfully',
                200
            );
        } catch (Throwable $th) {
            Log::error('MobileShopController getTodayVisitedShops error: ' . $th->getMessage());
            return $this->errorResponse(
                'Failed to retrieve today visited shops',
                500,
                $th->getMessage()
            );
        }
    }

    /**
     * Get shops with outstanding credit balance for mobile application.
     */
    public function getCreditShops(Request $request)
    {
        try {
            $shops = RepAccess::shops(Shop::query(), $request->user())->where('current_credit_balance', '>', 0)
                ->orderBy('current_credit_balance', 'desc')
                ->get();

            $formatted = $shops->map(fn($s) => $this->formatShop($s));

            return $this->successResponse(
                $formatted,
                'Credit shops retrieved successfully',
                200
            );
        } catch (Throwable $th) {
            Log::error('MobileShopController getCreditShops error: ' . $th->getMessage());
            return $this->errorResponse(
                'Failed to retrieve credit shops',
                500,
                $th->getMessage()
            );
        }
    }
}
