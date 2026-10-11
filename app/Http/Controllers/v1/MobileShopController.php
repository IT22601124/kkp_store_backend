<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Shop;
use App\Models\ShopGpsLocation;
use App\Models\DistributionRoute;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

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
            $query = Shop::orderBy('id', 'desc');

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
    public function store(Request $request)
    {
        try {
            // Flexible input mapping for both snake_case and camelCase
            $shopName = $request->input('shop_name') ?? $request->input('shopName') ?? $request->input('name');
            $ownerName = $request->input('owner_name') ?? $request->input('ownerName');
            $phone = $request->input('phone') ?? $request->input('contact_number') ?? $request->input('mobile');
            $address = $request->input('address');
            $routeId = $request->input('route_id') ?? $request->input('routeId') ?? 1;
            $shopCode = $request->input('shop_code') ?? $request->input('shopCode');
            $lat = $request->input('latitude') ?? $request->input('lat');
            $lng = $request->input('longitude') ?? $request->input('lng');
            $accuracy = $request->input('accuracy');
            $creditLimit = $request->input('credit_limit') ?? $request->input('creditLimit') ?? 100000.00;
            $currentCreditBalance = $request->input('current_credit_balance') ?? $request->input('currentCreditBalance') ?? 0.00;
            $status = $request->input('status') ?? 'GOOD';
            $createdBy = $request->user() ? $request->user()->id : ($request->input('created_by') ?? $request->input('createdBy'));

            // Validate mandatory fields
            if (empty($shopName)) {
                return $this->errorResponse('Shop name (shop_name / shopName) is required', 422);
            }

            // Auto-generate shop code if missing
            if (empty($shopCode)) {
                $count = Shop::count() + 1;
                $prefix = 'SHP-' . date('Ymd') . '-';
                $shopCode = $prefix . str_pad($count, 4, '0', STR_PAD_LEFT);
                
                // Ensure uniqueness
                while (Shop::where('shop_code', $shopCode)->exists()) {
                    $count++;
                    $shopCode = $prefix . str_pad($count, 4, '0', STR_PAD_LEFT);
                }
            } else {
                $shopCode = strtoupper($shopCode);
                // Check if shop_code is already taken
                if (Shop::where('shop_code', $shopCode)->exists()) {
                    return $this->errorResponse("Shop code '{$shopCode}' already exists", 422);
                }
            }

            // Create shop model
            $shop = Shop::create([
                'route_id' => $routeId,
                'shop_code' => $shopCode,
                'shop_name' => $shopName,
                'owner_name' => $ownerName ?? '',
                'phone' => $phone ?? '',
                'address' => $address ?? '',
                'latitude' => $lat !== null ? (float)$lat : null,
                'longitude' => $lng !== null ? (float)$lng : null,
                'credit_limit' => (float)$creditLimit,
                'current_credit_balance' => (float)$currentCreditBalance,
                'status' => strtoupper($status),
                'created_by' => $createdBy
            ]);

            // If GPS coordinates provided, record in shop_gps_locations table as well
            if ($lat !== null && $lng !== null) {
                $userId = null;
                if ($request->user()) {
                    $userId = $request->user()->id;
                }

                ShopGpsLocation::create([
                    'shop_id' => $shop->id,
                    'latitude' => (float)$lat,
                    'longitude' => (float)$lng,
                    'accuracy' => $accuracy !== null ? (float)$accuracy : null,
                    'captured_by_user_id' => $userId,
                    'address_text' => $address ?? '',
                    'is_verified' => true
                ]);
            }

            // Increment route's shop count if route exists
            if ($routeId) {
                $route = DistributionRoute::find($routeId);
                if ($route) {
                    $route->increment('total_shops_count');
                }
            }

            return $this->successResponse(
                $this->formatShop($shop),
                'Shop created successfully via mobile API',
                201
            );

        } catch (Throwable $th) {
            Log::error('MobileShopController store error: ' . $th->getMessage());
            return $this->errorResponse(
                'Failed to create shop',
                500,
                $th->getMessage()
            );
        }
    }

    /**
     * Get details of a single shop for mobile.
     */
    public function show($id)
    {
        try {
            $shop = Shop::find($id);
            if (!$shop) {
                return $this->errorResponse('Shop not found', 404);
            }

            return $this->successResponse(
                $this->formatShop($shop),
                'Shop details retrieved successfully',
                200
            );
        } catch (Throwable $th) {
            return $this->errorResponse(
                'Failed to retrieve shop details',
                500,
                $th->getMessage()
            );
        }
    }

    /**
     * Delete a shop record for mobile application.
     */
    public function destroy($id)
    {
        try {
            $shop = Shop::find($id);
            if (!$shop) {
                return $this->errorResponse('Shop not found', 404);
            }

            $routeId = $shop->route_id;

            // Delete associated GPS location records if any
            ShopGpsLocation::where('shop_id', $id)->delete();

            // Delete shop
            $shop->delete();

            // Decrement total_shops_count on route if applicable
            if ($routeId) {
                $route = DistributionRoute::find($routeId);
                if ($route && $route->total_shops_count > 0) {
                    $route->decrement('total_shops_count');
                }
            }

            return $this->successResponse(
                ['id' => (int)$id],
                'Shop deleted successfully',
                200
            );

        } catch (Throwable $th) {
            Log::error('MobileShopController destroy error: ' . $th->getMessage());
            return $this->errorResponse(
                'Failed to delete shop',
                500,
                $th->getMessage()
            );
        }
    }

    public function getShopsByRoute(Request $request){
        try{
            $allShop = Shop::where('route_id', $request->route_id)->get();
            if (!$allShop) {
                return $this->errorResponse('Shop not found', 404);
            }
            return $this->successResponse(
                $allShop,
                'Shop details retrieved successfully',
                200
            );
        }
        catch(Throwable $th){
            return $this->errorResponse('',500);
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

            $query = Sale::with(['shop', 'salesItems.item', 'payments.bankAccount'])
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
                                'ONLINE', 'CARD' => $onlineCollected += $amt,
                                default => null,
                            };
                        }
                    } else {
                        $pAmt = (float)$sale->paid_amount;
                        match (strtoupper($sale->payment_type)) {
                            'CASH' => $cashCollected += $pAmt,
                            'CHEQUE' => $chequeCollected += $pAmt,
                            'ONLINE', 'CARD' => $onlineCollected += $pAmt,
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
                            'ONLINE', 'CARD' => $overallOnline += $amt,
                            default => null,
                        };
                    }
                } else {
                    $pAmt = (float)$s->paid_amount;
                    match (strtoupper($s->payment_type)) {
                        'CASH' => $overallCash += $pAmt,
                        'CHEQUE' => $overallCheque += $pAmt,
                        'ONLINE', 'CARD' => $overallOnline += $pAmt,
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
            $shop = Shop::find($id);
            if (!$shop) {
                return $this->errorResponse('Shop not found', 404);
            }

            $user = $request->user();
            $repId = $user ? $user->id : $request->query('rep_id');
            $date = $request->query('date', now()->format('Y-m-d'));
            if ($date === 'today') {
                $date = now()->format('Y-m-d');
            }

            $query = Sale::with(['salesItems.item', 'payments.bankAccount', 'rep'])
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

            $query = Sale::with(['salesItems.item', 'payments.bankAccount'])
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
            $shops = Shop::where('current_credit_balance', '>', 0)
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
