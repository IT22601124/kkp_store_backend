<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\BankAccount;
use App\Models\DailySettlement;
use App\Models\DailySettlementItem;
use App\Models\DsrBankDeposit;
use App\Models\Item;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Shop;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Throwable;

class MobileSaleController extends Controller
{
    use ApiResponse;

    /**
     * Resolve slip image path from file upload, base64 data, or pre-uploaded path.
     */
    protected function resolveSlipPath(Request $request, ?array $paymentItem = null): ?string
    {
        // 1. Check payment-specific file
        if ($paymentItem && !empty($paymentItem['slip_file'])) {
            $file = $paymentItem['slip_file'];
            if ($file instanceof \Illuminate\Http\UploadedFile && $file->isValid()) {
                return $file->store('bank_slips', 'public');
            }
        }

        // 2. Check top-level files across common field names
        $file = $request->file('bank_slip')
            ?? $request->file('slip_image')
            ?? $request->file('slip')
            ?? $request->file('image');

        if ($file && $file->isValid()) {
            return $file->store('bank_slips', 'public');
        }

        // 3. Check payment-specific string (base64 or path)
        if ($paymentItem) {
            $pSlip = $paymentItem['slip_image'] ?? $paymentItem['bank_slip'] ?? $paymentItem['slip'] ?? null;
            if (is_string($pSlip) && trim($pSlip) !== '') {
                $pSlip = trim($pSlip);
                if (preg_match('/^data:image\/(\w+);base64,/', $pSlip, $type)) {
                    $raw = substr($pSlip, strpos($pSlip, ',') + 1);
                    $decoded = base64_decode($raw);
                    if ($decoded !== false) {
                        $ext = strtolower($type[1]) === 'jpeg' ? 'jpg' : strtolower($type[1]);
                        $path = 'bank_slips/' . uniqid('slip_', true) . '.' . $ext;
                        Storage::disk('public')->put($path, $decoded);
                        return $path;
                    }
                } elseif (strlen($pSlip) > 200 && base64_decode($pSlip, true)) {
                    $decoded = base64_decode($pSlip);
                    $path = 'bank_slips/' . uniqid('slip_', true) . '.jpg';
                    Storage::disk('public')->put($path, $decoded);
                    return $path;
                }
                return $pSlip;
            }
        }

        // 4. Check top-level string input
        $input = $request->input('bank_slip')
            ?? $request->input('slip_image')
            ?? $request->input('slip');

        if (is_string($input) && trim($input) !== '') {
            $input = trim($input);
            if (preg_match('/^data:image\/(\w+);base64,/', $input, $type)) {
                $raw = substr($input, strpos($input, ',') + 1);
                $decoded = base64_decode($raw);
                if ($decoded !== false) {
                    $ext = strtolower($type[1]) === 'jpeg' ? 'jpg' : strtolower($type[1]);
                    $path = 'bank_slips/' . uniqid('slip_', true) . '.' . $ext;
                    Storage::disk('public')->put($path, $decoded);
                    return $path;
                }
            } elseif (strlen($input) > 200 && base64_decode($input, true)) {
                $decoded = base64_decode($input);
                $path = 'bank_slips/' . uniqid('slip_', true) . '.jpg';
                Storage::disk('public')->put($path, $decoded);
                return $path;
            }
            return $input;
        }

        return null;
    }

    /** List sales belonging to the authenticated rep. */
    public function index(Request $request)
    {
        try {
            $rep = $request->user();
            $query = Sale::with(['shop', 'salesItems.item', 'payments.bankAccount'])
                ->where('rep_id', $rep->id);

            if ($request->has('date')) {
                $dateVal = $request->query('date');
                if ($dateVal === 'today') {
                    $dateVal = now()->format('Y-m-d');
                }
                $query->whereDate('sale_date', $dateVal);
            }

            if ($request->has('shop_id')) {
                $query->where('shop_id', $request->query('shop_id'));
            }

            if ($request->has('payment_type')) {
                $query->where('payment_type', $request->query('payment_type'));
            }

            if ($request->has('status')) {
                $query->where('status', $request->query('status'));
            }

            $sales = $query->orderBy('id', 'desc')->get();

            return $this->successResponse($sales, 'Mobile sales retrieved successfully');
        } catch (Throwable $th) {
            return $this->errorResponse('Failed to retrieve mobile sales', 500, $th->getMessage());
        }
    }

    /** Get today's sales for the authenticated rep with summary metrics. */
    public function getTodaySales(Request $request)
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

            if ($request->has('payment_type')) {
                $query->where('payment_type', $request->query('payment_type'));
            }

            if ($request->has('status')) {
                $query->where('status', $request->query('status'));
            }

            $sales = $query->orderBy('id', 'desc')->get();

            $summary = [
                'total_sales_count' => $sales->count(),
                'totalSalesCount' => $sales->count(),
                'total_sales_amount' => (float) $sales->sum('total_amount'),
                'totalSalesAmount' => (float) $sales->sum('total_amount'),
                'total_paid_amount' => (float) $sales->sum('paid_amount'),
                'totalPaidAmount' => (float) $sales->sum('paid_amount'),
                'total_due_amount' => (float) $sales->sum('due_amount'),
                'totalDueAmount' => (float) $sales->sum('due_amount'),
                'unique_shops_count' => $sales->pluck('shop_id')->filter()->unique()->count(),
                'uniqueShopsCount' => $sales->pluck('shop_id')->filter()->unique()->count(),
            ];

            return $this->successResponse([
                'date' => $date,
                'summary' => $summary,
                'sales' => $sales,
            ], 'Today sales retrieved successfully', 200);
        } catch (Throwable $th) {
            return $this->errorResponse('Failed to retrieve today sales', 500, $th->getMessage());
        }
    }

    /** Store a new sale created by mobile rep with optional split payments. */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'shop_id' => 'nullable|integer|exists:shops,id',
            'dsr_trip_id' => 'nullable|integer|exists:dsr_trips,id',
            'sale_date' => 'nullable|date',
            'payment_type' => 'nullable|string|in:CASH,CREDIT,CHEQUE,ONLINE,CARD,SPLIT,BANK_TRANSFER,TRANSFER',
            'status' => 'nullable|string|in:PENDING,COMPLETED,CANCELLED',
            'discount' => 'nullable|numeric|min:0',
            'tax' => 'nullable|numeric|min:0',
            'paid_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|integer|exists:items,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'nullable|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0',
            'items.*.batch_number' => 'nullable|string',
            'bank_account_id' => 'nullable|integer|exists:bank_accounts,id',
            'reference_number' => 'nullable|string',
            'bank_slip' => 'nullable',
            'slip_image' => 'nullable',
            'payments' => 'nullable|array',
            'payments.*.bank_account_id' => 'nullable|integer|exists:bank_accounts,id',
            'payments.*.payment_type' => 'required_with:payments|string|in:CASH,CREDIT,CHEQUE,ONLINE,CARD,BANK_TRANSFER,TRANSFER',
            'payments.*.amount' => 'required_with:payments|numeric|min:0',
            'payments.*.reference_number' => 'nullable|string',
            'payments.*.payment_date' => 'nullable|date',
            'payments.*.notes' => 'nullable|string',
            'payments.*.slip_image' => 'nullable',
            'payments.*.bank_slip' => 'nullable',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors()->first());
        }

        try {
            $rep = $request->user();
            $rep->loadMissing('dsrProfile.branch');
            $branchId = $request->input('branch_id') ?? ($rep->dsrProfile?->branch_id ?? null);
            $globalSlipPath = $this->resolveSlipPath($request);

            $sale = DB::transaction(function () use ($request, $rep, $branchId, $globalSlipPath) {
                $lineDetails = [];
                $calculatedSubtotal = 0;

                foreach ($request->items as $itemData) {
                    $item = Item::findOrFail($itemData['item_id']);
                    $qty = (int) $itemData['quantity'];
                    $unitPrice = isset($itemData['unit_price']) ? (float) $itemData['unit_price'] : (float) $item->selling_price;
                    $itemDiscount = isset($itemData['discount']) ? (float) $itemData['discount'] : 0.00;
                    $lineSubtotal = ($qty * $unitPrice) - $itemDiscount;

                    $calculatedSubtotal += $lineSubtotal;

                    $lineDetails[] = [
                        'item_id' => $item->id,
                        'quantity' => $qty,
                        'unit_price' => $unitPrice,
                        'discount' => $itemDiscount,
                        'subtotal' => $lineSubtotal,
                        'batch_number' => $itemData['batch_number'] ?? null,
                    ];
                }

                $overallDiscount = (float) ($request->input('discount') ?? 0.00);
                $tax = (float) ($request->input('tax') ?? 0.00);
                $totalAmount = max(0, $calculatedSubtotal - $overallDiscount + $tax);

                $hasPaymentsArray = $request->filled('payments') && is_array($request->input('payments')) && count($request->input('payments')) > 0;
                $paymentType = strtoupper($request->input('payment_type', $hasPaymentsArray ? 'SPLIT' : 'CASH'));

                $paidAmount = 0.00;
                $paymentRecords = [];

                if ($hasPaymentsArray) {
                    foreach ($request->input('payments') as $p) {
                        $pType = strtoupper($p['payment_type']);
                        $pAmount = (float) $p['amount'];
                        $pBankId = $p['bank_account_id'] ?? $request->input('bank_account_id') ?? null;
                        $pRef = $p['reference_number'] ?? $request->input('reference_number') ?? null;
                        $pDate = $p['payment_date'] ?? $request->input('sale_date') ?? now();
                        $pNotes = $p['notes'] ?? null;
                        $pSlip = $this->resolveSlipPath($request, $p) ?? $globalSlipPath;

                        if ($pType !== 'CREDIT') {
                            $paidAmount += $pAmount;
                        }

                        $isBank = in_array($pType, ['ONLINE', 'BANK_TRANSFER', 'TRANSFER']) || !empty($pBankId) || !empty($pSlip);

                        $paymentRecords[] = [
                            'payment_type' => $pType,
                            'amount' => $pAmount,
                            'bank_account_id' => $pBankId,
                            'reference_number' => $pRef,
                            'payment_date' => $pDate,
                            'notes' => $pNotes,
                            'slip_image' => $pSlip,
                            'status' => $isBank ? 'PENDING' : 'CONFIRMED',
                        ];
                    }

                    if ($request->filled('paid_amount')) {
                        $paidAmount = (float) $request->input('paid_amount');
                    }
                } else {
                    $paidAmount = (float) ($request->input('paid_amount') ?? ($paymentType === 'CREDIT' ? 0.00 : $totalAmount));
                    if ($paidAmount > 0) {
                        $isBank = in_array($paymentType, ['ONLINE', 'BANK_TRANSFER', 'TRANSFER']) || $request->filled('bank_account_id') || !empty($globalSlipPath);
                        $paymentRecords[] = [
                            'payment_type' => $paymentType,
                            'amount' => $paidAmount,
                            'bank_account_id' => $request->input('bank_account_id'),
                            'reference_number' => $request->input('reference_number'),
                            'payment_date' => $request->input('sale_date') ?? now(),
                            'notes' => $request->input('notes'),
                            'slip_image' => $globalSlipPath,
                            'status' => $isBank ? 'PENDING' : 'CONFIRMED',
                        ];
                    }
                }

                $dueAmount = max(0, $totalAmount - $paidAmount);
                $saleCode = 'SALE-' . date('Ymd') . '-' . str_pad(Sale::count() + 1, 5, '0', STR_PAD_LEFT);

                $saleRecord = Sale::create([
                    'sale_code' => $saleCode,
                    'rep_id' => $rep->id,
                    'user_id' => $rep->id,
                    'shop_id' => $request->input('shop_id'),
                    'branch_id' => $branchId,
                    'dsr_trip_id' => $request->input('dsr_trip_id'),
                    'sale_date' => $request->input('sale_date') ?? now(),
                    'payment_type' => $paymentType,
                    'status' => $request->input('status', 'COMPLETED'),
                    'subtotal' => $calculatedSubtotal,
                    'discount' => $overallDiscount,
                    'tax' => $tax,
                    'total_amount' => $totalAmount,
                    'paid_amount' => $paidAmount,
                    'due_amount' => $dueAmount,
                    'notes' => $request->input('notes'),
                ]);

                foreach ($lineDetails as $line) {
                    SaleItem::create([
                        'sale_id' => $saleRecord->id,
                        'item_id' => $line['item_id'],
                        'quantity' => $line['quantity'],
                        'unit_price' => $line['unit_price'],
                        'discount' => $line['discount'],
                        'subtotal' => $line['subtotal'],
                        'batch_number' => $line['batch_number'],
                    ]);
                }

                foreach ($paymentRecords as $pRecord) {
                    SalePayment::create([
                        'sale_id' => $saleRecord->id,
                        'bank_account_id' => $pRecord['bank_account_id'] ?? null,
                        'payment_type' => $pRecord['payment_type'],
                        'amount' => $pRecord['amount'],
                        'reference_number' => $pRecord['reference_number'],
                        'payment_date' => $pRecord['payment_date'],
                        'notes' => $pRecord['notes'],
                        'slip_image' => $pRecord['slip_image'] ?? null,
                        'status' => $pRecord['status'] ?? 'CONFIRMED',
                    ]);

                    if (!empty($pRecord['bank_account_id']) && $pRecord['amount'] > 0) {
                        BankAccount::find($pRecord['bank_account_id'])?->increment('current_balance', $pRecord['amount']);
                    }
                }

                // If credit sale and shop_id is set, increment shop credit balance
                if ($request->filled('shop_id') && $dueAmount > 0) {
                    $shop = Shop::find($request->input('shop_id'));
                    if ($shop) {
                        $shop->increment('current_credit_balance', $dueAmount);
                    }
                }

                return $saleRecord->load(['shop', 'salesItems.item', 'payments.bankAccount', 'payments.verifiedBy']);
            });

            return $this->successResponse($sale, 'Mobile sale recorded successfully', 201);
        } catch (Throwable $th) {
            return $this->errorResponse('Failed to record mobile sale: ' . $th->getMessage(), 500);
        }
    }

    /** Show single mobile sale details. */
    public function show(Request $request, int $id)
    {
        try {
            $rep = $request->user();
            $sale = Sale::with(['shop', 'salesItems.item', 'payments.bankAccount', 'payments.verifiedBy'])
                ->where('rep_id', $rep->id)
                ->find($id);

            if (!$sale) {
                return $this->errorResponse('Sale not found', 404);
            }

            return $this->successResponse($sale, 'Mobile sale retrieved successfully');
        } catch (Throwable $th) {
            return $this->errorResponse('Failed to retrieve mobile sale', 500, $th->getMessage());
        }
    }

    /** Get credit sales with due amounts for a specific shop. */
    public function getShopCreditSales(Request $request, $shopId)
    {
        try {
            $sales = Sale::with(['salesItems.item', 'payments'])
                ->where('shop_id', $shopId)
                ->where('due_amount', '>', 0)
                ->orderBy('id', 'desc')
                ->get();

            return $this->successResponse($sales, 'Shop credit sales retrieved successfully');
        } catch (Throwable $th) {
            return $this->errorResponse('Failed to retrieve shop credit sales', 500, $th->getMessage());
        }
    }

    /** Get daily settlement sheet calculation or stored sheet for sales & collections. */
    public function getDailySettlementSheet(Request $request)
    {
        try {
            $user = $request->user();
            $repId = $user ? $user->id : $request->query('rep_id');
            $date = $request->query('date', now()->format('Y-m-d'));

            // Check if settlement sheet is already submitted & stored in database for this date and rep
            if ($repId) {
                $savedSettlement = DailySettlement::with(['items', 'rep', 'dsrTrip', 'bankDeposits.bankAccount'])
                    ->where('rep_id', $repId)
                    ->whereDate('settlement_date', $date)
                    ->first();

                if ($savedSettlement) {
                    $itemsMatrix = $savedSettlement->items->map(function ($it) {
                        return [
                            'item_id' => $it->item_id,
                            'name' => $it->item_name,
                            'code' => $it->item_code,
                            'sold_qty' => (int) $it->sold_quantity,
                            'total_revenue' => (float) $it->total_revenue,
                        ];
                    })->toArray();

                    return $this->successResponse([
                        'id' => $savedSettlement->id,
                        'settlement_code' => $savedSettlement->settlement_code,
                        'date' => $savedSettlement->settlement_date->format('Y-m-d'),
                        'status' => $savedSettlement->status,
                        'submitted_at' => $savedSettlement->submitted_at ? $savedSettlement->submitted_at->toIso8601String() : null,
                        'shops_visited_count' => (int) $savedSettlement->shops_visited_count,
                        'total_sales_value' => (float) $savedSettlement->total_sales_value,
                        'total_cash_collected' => (float) $savedSettlement->total_cash_collected,
                        'total_cheques_collected' => (float) $savedSettlement->total_cheques_collected,
                        'total_online_collected' => (float) $savedSettlement->total_online_collected,
                        'total_credit_issued' => (float) $savedSettlement->total_credit_issued,
                        'ending_shop_credit' => (float) $savedSettlement->ending_shop_credit,
                        'items_matrix' => $itemsMatrix,
                        'bank_deposits' => $savedSettlement->bankDeposits,
                        'notes' => $savedSettlement->notes,
                        'is_saved' => true,
                    ], 'Stored daily settlement sheet retrieved successfully');
                }
            }

            // Otherwise, compute live calculation from today's sales transactions
            $salesQuery = Sale::with(['salesItems.item', 'payments'])
                ->whereDate('sale_date', $date);

            if ($repId) {
                $salesQuery->where('rep_id', $repId);
            }

            $sales = $salesQuery->get();

            $totalSalesValue = (float) $sales->sum('total_amount');
            $totalCreditIssued = (float) $sales->sum('due_amount');

            // Payment breakdowns
            $totalCashCollected = 0.00;
            $totalChequesCollected = 0.00;
            $totalOnlineCollected = 0.00;

            foreach ($sales as $sale) {
                if ($sale->payments && $sale->payments->count() > 0) {
                    foreach ($sale->payments as $p) {
                        $amt = (float) $p->amount;
                        switch (strtoupper($p->payment_type)) {
                            case 'CASH':
                                $totalCashCollected += $amt;
                                break;
                            case 'CHEQUE':
                                $totalChequesCollected += $amt;
                                break;
                            case 'ONLINE':
                            case 'CARD':
                            case 'BANK_TRANSFER':
                            case 'TRANSFER':
                                $totalOnlineCollected += $amt;
                                break;
                        }
                    }
                } else {
                    $paid = (float) $sale->paid_amount;
                    switch (strtoupper($sale->payment_type)) {
                        case 'CASH':
                            $totalCashCollected += $paid;
                            break;
                        case 'CHEQUE':
                            $totalChequesCollected += $paid;
                            break;
                        case 'ONLINE':
                        case 'CARD':
                        case 'BANK_TRANSFER':
                        case 'TRANSFER':
                            $totalOnlineCollected += $paid;
                            break;
                    }
                }
            }

            // Grouped items sales matrix
            $itemsMatrix = [];
            foreach ($sales as $sale) {
                foreach ($sale->salesItems as $itemLine) {
                    $itemId = $itemLine->item_id;
                    $itemObj = $itemLine->item;
                    $itemName = $itemObj ? ($itemObj->name ?? $itemObj->item_name) : "Item #{$itemId}";
                    $itemCode = $itemObj ? ($itemObj->code ?? $itemObj->item_code) : "ITM-{$itemId}";

                    if (!isset($itemsMatrix[$itemId])) {
                        $itemsMatrix[$itemId] = [
                            'item_id' => $itemId,
                            'name' => $itemName,
                            'code' => $itemCode,
                            'sold_qty' => 0,
                            'total_revenue' => 0.00,
                        ];
                    }

                    $itemsMatrix[$itemId]['sold_qty'] += (int) $itemLine->quantity;
                    $itemsMatrix[$itemId]['total_revenue'] += (float) $itemLine->subtotal;
                }
            }

            $shopsVisitedCount = $sales->pluck('shop_id')->filter()->unique()->count();
            $endingShopCredit = (float) Shop::sum('current_credit_balance');

            return $this->successResponse([
                'date' => $date,
                'status' => 'DRAFT',
                'shops_visited_count' => $shopsVisitedCount,
                'total_sales_value' => $totalSalesValue,
                'total_cash_collected' => $totalCashCollected,
                'total_cheques_collected' => $totalChequesCollected,
                'total_online_collected' => $totalOnlineCollected,
                'total_credit_issued' => $totalCreditIssued,
                'ending_shop_credit' => $endingShopCredit,
                'items_matrix' => array_values($itemsMatrix),
                'is_saved' => false,
            ], 'Live daily settlement preview retrieved successfully');
        } catch (Throwable $th) {
            return $this->errorResponse('Failed to retrieve daily settlement sheet', 500, $th->getMessage());
        }
    }

    /** Store & Submit daily settlement sheet into database. */
    public function storeDailySettlementSheet(Request $request)
    {
        try {
            $user = $request->user();
            $repId = $user ? $user->id : $request->input('rep_id');

            if (!$repId) {
                return $this->errorResponse('Rep ID is required to submit settlement sheet', 422);
            }

            $validator = Validator::make($request->all(), [
                'date' => 'required|date',
                'shops_visited_count' => 'nullable|integer',
                'total_sales_value' => 'nullable|numeric',
                'total_cash_collected' => 'nullable|numeric',
                'total_cheques_collected' => 'nullable|numeric',
                'total_online_collected' => 'nullable|numeric',
                'total_credit_issued' => 'nullable|numeric',
                'ending_shop_credit' => 'nullable|numeric',
                'dsr_trip_id' => 'nullable|integer',
                'notes' => 'nullable|string',
                'items' => 'nullable|array',
                'bank_deposits' => 'nullable|array',
                'bank_deposits.*.bank_account_id' => 'required_with:bank_deposits|integer|exists:bank_accounts,id',
                'bank_deposits.*.amount' => 'required_with:bank_deposits|numeric|min:0.01',
                'bank_deposits.*.slip_reference' => 'nullable|string',
                'bank_deposits.*.notes' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return $this->errorResponse('Validation failed', 422, $validator->errors());
            }

            $date = $request->input('date', now()->format('Y-m-d'));

            $settlement = DB::transaction(function () use ($request, $repId, $date) {
                $settlementRecord = DailySettlement::where('rep_id', $repId)
                    ->whereDate('settlement_date', $date)
                    ->first();

                $settlementCode = $settlementRecord
                    ? $settlementRecord->settlement_code
                    : ('SETTLE-' . date('Ymd') . '-' . str_pad(DailySettlement::count() + 1, 5, '0', STR_PAD_LEFT));

                if ($settlementRecord) {
                    $settlementRecord->update([
                        'shops_visited_count' => $request->input('shops_visited_count', 0),
                        'total_sales_value' => $request->input('total_sales_value', 0),
                        'total_cash_collected' => $request->input('total_cash_collected', 0),
                        'total_cheques_collected' => $request->input('total_cheques_collected', 0),
                        'total_online_collected' => $request->input('total_online_collected', 0),
                        'total_credit_issued' => $request->input('total_credit_issued', 0),
                        'ending_shop_credit' => $request->input('ending_shop_credit', 0),
                        'dsr_trip_id' => $request->input('dsr_trip_id'),
                        'notes' => $request->input('notes'),
                        'status' => 'SUBMITTED',
                        'submitted_at' => now(),
                    ]);
                } else {
                    $settlementRecord = DailySettlement::create([
                        'settlement_code' => $settlementCode,
                        'rep_id' => $repId,
                        'dsr_trip_id' => $request->input('dsr_trip_id'),
                        'settlement_date' => $date,
                        'shops_visited_count' => $request->input('shops_visited_count', 0),
                        'total_sales_value' => $request->input('total_sales_value', 0),
                        'total_cash_collected' => $request->input('total_cash_collected', 0),
                        'total_cheques_collected' => $request->input('total_cheques_collected', 0),
                        'total_online_collected' => $request->input('total_online_collected', 0),
                        'total_credit_issued' => $request->input('total_credit_issued', 0),
                        'ending_shop_credit' => $request->input('ending_shop_credit', 0),
                        'status' => 'SUBMITTED',
                        'notes' => $request->input('notes'),
                        'submitted_at' => now(),
                    ]);
                }

                // Sync items
                DailySettlementItem::where('daily_settlement_id', $settlementRecord->id)->delete();

                $itemsInput = $request->input('items', []);
                foreach ($itemsInput as $it) {
                    DailySettlementItem::create([
                        'daily_settlement_id' => $settlementRecord->id,
                        'item_id' => $it['item_id'] ?? null,
                        'item_name' => $it['name'] ?? ($it['item_name'] ?? 'Item'),
                        'item_code' => $it['code'] ?? ($it['item_code'] ?? null),
                        'sold_quantity' => $it['sold_qty'] ?? ($it['sold_quantity'] ?? 0),
                        'total_revenue' => $it['total_revenue'] ?? 0,
                    ]);
                }

                // Sync bank deposits if provided
                if ($request->filled('bank_deposits') && is_array($request->input('bank_deposits'))) {
                    foreach ($request->input('bank_deposits') as $bd) {
                        DsrBankDeposit::create([
                            'bank_account_id'     => $bd['bank_account_id'],
                            'rep_id'              => $repId,
                            'daily_settlement_id' => $settlementRecord->id,
                            'dsr_trip_id'         => $request->input('dsr_trip_id'),
                            'deposit_date'        => $date,
                            'amount'              => (float) $bd['amount'],
                            'slip_reference'      => $bd['slip_reference'] ?? null,
                            'deposit_slip_image'  => $bd['deposit_slip_image'] ?? $bd['slip_image'] ?? null,
                            'status'              => 'CONFIRMED',
                            'notes'               => $bd['notes'] ?? null,
                        ]);

                        BankAccount::find($bd['bank_account_id'])?->increment('current_balance', (float) $bd['amount']);
                    }
                }

                return $settlementRecord->load(['items', 'rep', 'dsrTrip', 'bankDeposits.bankAccount']);
            });

            return $this->successResponse($settlement, 'Daily settlement sheet submitted and saved to database successfully', 201);
        } catch (Throwable $th) {
            return $this->errorResponse('Failed to store daily settlement sheet: ' . $th->getMessage(), 500);
        }
    }

    /** List all daily settlement sheets for admin/supervisor audit. */
    public function getAllDailySettlements(Request $request)
    {
        try {
            $query = DailySettlement::with(['items.item', 'rep', 'dsrTrip', 'bankDeposits.bankAccount']);

            if ($request->has('rep_id')) {
                $query->where('rep_id', $request->query('rep_id'));
            }

            if ($request->has('status')) {
                $query->where('status', $request->query('status'));
            }

            if ($request->has('date')) {
                $query->whereDate('settlement_date', $request->query('date'));
            }

            $settlements = $query->orderBy('settlement_date', 'desc')->orderBy('id', 'desc')->get();

            return $this->successResponse($settlements, 'Daily settlements list retrieved successfully');
        } catch (Throwable $th) {
            return $this->errorResponse('Failed to retrieve daily settlements: ' . $th->getMessage(), 500);
        }
    }

    /** Update daily settlement approval status. */
    public function updateDailySettlementStatus(Request $request, $id)
    {
        try {
            $settlement = DailySettlement::with(['items.item', 'rep', 'dsrTrip', 'bankDeposits.bankAccount'])->find($id);

            if (!$settlement) {
                return $this->errorResponse('Daily settlement record not found', 404);
            }

            $validator = Validator::make($request->all(), [
                'status' => 'required|string|in:SUBMITTED,APPROVED,REJECTED,PENDING',
                'notes' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return $this->errorResponse('Validation failed', 422, $validator->errors()->first());
            }

            $settlement->status = strtoupper($request->input('status'));
            if ($request->filled('notes')) {
                $settlement->notes = $request->input('notes');
            }
            $settlement->save();

            return $this->successResponse($settlement, 'Daily settlement status updated successfully');
        } catch (Throwable $th) {
            return $this->errorResponse('Failed to update settlement status: ' . $th->getMessage(), 500);
        }
    }
}
