<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Item;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shop;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class MobileSaleController extends Controller
{
    use ApiResponse;

    /** List sales belonging to the authenticated rep. */
    public function index(Request $request)
    {
        try {
            $rep = $request->user();
            $query = Sale::with(['shop', 'salesItems.item'])
                ->where('rep_id', $rep->id);

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

    /** Store a new sale created by mobile rep. */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'shop_id' => 'nullable|integer|exists:shops,id',
            'dsr_trip_id' => 'nullable|integer|exists:dsr_trips,id',
            'sale_date' => 'nullable|date',
            'payment_type' => 'nullable|string|in:CASH,CREDIT,CHEQUE,ONLINE',
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
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors()->first());
        }

        try {
            $rep = $request->user();
            $rep->loadMissing('dsrProfile.branch');
            $branchId = $request->input('branch_id') ?? ($rep->dsrProfile?->branch_id ?? null);

            $sale = DB::transaction(function () use ($request, $rep, $branchId) {
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

                $paymentType = strtoupper($request->input('payment_type', 'CASH'));
                $paidAmount = (float) ($request->input('paid_amount') ?? ($paymentType === 'CREDIT' ? 0.00 : $totalAmount));
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

                // If credit sale and shop_id is set, increment shop credit balance
                if ($request->filled('shop_id') && $dueAmount > 0) {
                    $shop = Shop::find($request->input('shop_id'));
                    if ($shop) {
                        $shop->increment('current_credit_balance', $dueAmount);
                    }
                }

                return $saleRecord->load(['shop', 'salesItems.item']);
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
            $sale = Sale::with(['shop', 'salesItems.item'])
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
}
