<?php

namespace App\Http\Controllers;

use App\Http\Traits\ApiResponse;
use App\Models\BankAccount;
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
use App\Services\RepAccess;
use App\Services\RepInventory;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class SaleController extends Controller
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

    /**
     * Display a listing of sales.
     */
    public function index(Request $request)
    {
        $request->validate(['start_date' => 'nullable|date', 'end_date' => 'nullable|date', 'date_from' => 'nullable|date', 'date_to' => 'nullable|date']);
        try {
            $query = Sale::with(['rep', 'user', 'shop', 'branch', 'dsrTrip', 'salesItems.item', 'payments.bankAccount', 'payments.verifiedBy']);

            if ($request->user()->role === 'DSR_REP') {
                $query->where('rep_id', $request->user()->id);
            } elseif ($request->has('rep_id')) {
                $query->where('rep_id', $request->query('rep_id'));
            }

            if ($request->has('user_id')) {
                $query->where('user_id', $request->query('user_id'));
            }

            if ($request->has('shop_id')) {
                $query->where('shop_id', $request->query('shop_id'));
            }

            if ($request->has('branch_id')) {
                $query->where('branch_id', $request->query('branch_id'));
            }

            if ($request->has('payment_type')) {
                $query->where('payment_type', $request->query('payment_type'));
            }

            if ($request->has('status')) {
                $query->where('status', $request->query('status'));
            }

            if ($request->filled('date_from') || $request->filled('start_date')) {
                $query->whereDate('sale_date', '>=', $request->query('date_from') ?? $request->query('start_date'));
            }

            if ($request->filled('date_to') || $request->filled('end_date')) {
                $query->whereDate('sale_date', '<=', $request->query('date_to') ?? $request->query('end_date'));
            }

            if ($request->has('has_bank_slip')) {
                $hasSlip = filter_var($request->query('has_bank_slip'), FILTER_VALIDATE_BOOLEAN);
                if ($hasSlip) {
                    $query->whereHas('payments', function ($pq) {
                        $pq->whereNotNull('slip_image');
                    });
                } else {
                    $query->whereDoesntHave('payments', function ($pq) {
                        $pq->whereNotNull('slip_image');
                    });
                }
            }

            if ($request->filled('bank_account_id')) {
                $bankId = $request->query('bank_account_id');
                $query->whereHas('payments', function ($pq) use ($bankId) {
                    $pq->where('bank_account_id', $bankId);
                });
            }

            if ($request->filled('search')) {
                $search = $request->query('search');
                $query->where(function ($q) use ($search) {
                    $q->where('sale_code', 'like', "%{$search}%")
                      ->orWhere('notes', 'like', "%{$search}%")
                      ->orWhereHas('shop', function ($sq) use ($search) {
                          $sq->where('shop_name', 'like', "%{$search}%")
                            ->orWhere('owner_name', 'like', "%{$search}%");
                      })
                      ->orWhereHas('payments', function ($pq) use ($search) {
                          $pq->where('reference_number', 'like', "%{$search}%");
                      });
                });
            }

            $sales = $query->orderBy('id', 'desc')->get();

            return $this->successResponse(
                $sales,
                'Sales retrieved successfully',
                200
            );
        } catch (\Illuminate\Validation\ValidationException | \Illuminate\Database\Eloquent\ModelNotFoundException | \Symfony\Component\HttpKernel\Exception\HttpException $th) {
            throw $th;
        } catch (Throwable $th) {
            return $this->errorResponse(
                'Failed to retrieve sales',
                500,
                $th->getMessage()
            );
        }
    }

    /**
     * Store a newly created sale with sale items and payment breakdowns.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'rep_id' => 'nullable|integer|exists:users,id',
            'user_id' => 'nullable|integer|exists:users,id',
            'shop_id' => 'nullable|integer|exists:shops,id',
            'branch_id' => 'nullable|integer|exists:branches,id',
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
            $user = $request->user();
            if ($user->role === 'DSR_REP') {
                $branchId = $user->dsrProfile?->branch_id;
                if (!$branchId) {
                    throw ValidationException::withMessages(['branch_id' => 'The rep needs an assigned branch.']);
                }
                $request->merge(['rep_id' => $user->id, 'user_id' => $user->id, 'branch_id' => $branchId]);
                if ($request->filled('shop_id')) {
                    RepAccess::shop($user, (int) $request->shop_id);
                }
                if ($request->input('status', 'COMPLETED') !== 'COMPLETED') {
                    throw ValidationException::withMessages(['status' => 'Rep sales must be completed.']);
                }
                if ($request->filled('dsr_trip_id')) {
                    abort_unless(\App\Models\DsrTrip::whereKey($request->dsr_trip_id)->where('referrer_id', $user->id)->exists(), 403);
                }
            }
            $repId = $request->input('rep_id') ?? ($user ? $user->id : null);
            $userId = $request->input('user_id') ?? ($user ? $user->id : null);
            $globalSlipPath = $this->resolveSlipPath($request);

            $sale = DB::transaction(function () use ($request, $repId, $userId, $globalSlipPath) {
                $lineDetails = [];
                $calculatedSubtotal = 0;

                foreach ($request->items as $itemData) {
                    $item = Item::findOrFail($itemData['item_id']);
                    $qty = (int) $itemData['quantity'];
                    $unitPrice = isset($itemData['unit_price']) ? (float) $itemData['unit_price'] : (float) $item->selling_price;
                    $itemDiscount = isset($itemData['discount']) ? (float) $itemData['discount'] : 0.00;
                    $unitPrice = round($unitPrice, 2);
                    $itemDiscount = round($itemDiscount, 2);
                    $lineSubtotal = round(($qty * $unitPrice) - $itemDiscount, 2);
                    if ($lineSubtotal < 0 || $item->status !== 'ACTIVE') {
                        throw ValidationException::withMessages(['items' => 'Use active items and discounts no greater than line value.']);
                    }

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
                if ($overallDiscount > $calculatedSubtotal) {
                    throw ValidationException::withMessages(['discount' => 'Discount exceeds subtotal.']);
                }
                $totalAmount = round($calculatedSubtotal - $overallDiscount + $tax, 2);

                $hasPaymentsArray = $request->filled('payments') && is_array($request->input('payments')) && count($request->input('payments')) > 0;
                $paymentType = strtoupper($request->input('payment_type', $hasPaymentsArray ? 'SPLIT' : 'CASH'));

                $paidAmount = 0.00;
                $paymentRecords = [];

                if ($hasPaymentsArray) {
                    foreach ($request->input('payments') as $p) {
                        $pType = strtoupper($p['payment_type']);
                        $pAmount = round((float) $p['amount'], 2);
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

                    $paidAmount = round($paidAmount, 2);
                    if ($request->filled('paid_amount') && (int) round($paidAmount * 100) !== (int) round((float) $request->paid_amount * 100)) {
                        throw ValidationException::withMessages(['paid_amount' => 'Paid amount must equal the non-credit payment total.']);
                    }
                    $credit = collect($paymentRecords)->where('payment_type', 'CREDIT')->sum('amount');
                    if ($credit > 0 && (int) round(($credit + $paidAmount) * 100) !== (int) round($totalAmount * 100)) {
                        throw ValidationException::withMessages(['payments' => 'Split credit and payments must equal the invoice total.']);
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

                $paidAmount = round($paidAmount, 2);
                if ($paidAmount > $totalAmount || ($paymentType === 'CREDIT' && $paidAmount > 0) || ($paymentType === 'SPLIT' && !$hasPaymentsArray)) {
                    throw ValidationException::withMessages(['payments' => 'Invalid payment total or payment type.']);
                }
                $dueAmount = round($totalAmount - $paidAmount, 2);
                $saleCode = 'SALE-' . now()->format('Ymd') . '-' . strtoupper(bin2hex(random_bytes(8)));

                $saleRecord = Sale::create([
                    'sale_code' => $saleCode,
                    'rep_id' => $repId,
                    'user_id' => $userId,
                    'shop_id' => $request->input('shop_id'),
                    'branch_id' => $request->input('branch_id'),
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

                if ($saleRecord->status === 'COMPLETED' && $repId && User::find($repId)?->role === 'DSR_REP') {
                    app(RepInventory::class)->consume((int) $repId, $lineDetails, $saleRecord->id);
                }

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
                    if ($pRecord['payment_type'] === 'CREDIT') {
                        continue;
                    }
                    SalePayment::create([
                        // The settlement belongs to the selected DSR, even
                        // when an agent is entering the sale on their behalf.
                        'collected_by' => $repId ?? $userId,
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

                // If due_amount > 0 and shop_id is set, increment shop's current credit balance
                if ($request->filled('shop_id') && $dueAmount > 0) {
                    $shop = Shop::find($request->input('shop_id'));
                    if ($shop) {
                        $shop->increment('current_credit_balance', $dueAmount);
                    }
                }

                return $saleRecord->load(['rep', 'user', 'shop', 'branch', 'dsrTrip', 'salesItems.item', 'payments.bankAccount', 'payments.verifiedBy']);
            });

            return $this->successResponse($sale, 'Sale recorded successfully', 201);
        } catch (\Illuminate\Validation\ValidationException | \Illuminate\Database\Eloquent\ModelNotFoundException | \Symfony\Component\HttpKernel\Exception\HttpException $th) {
            throw $th;
        } catch (Throwable $th) {
            return $this->errorResponse('Failed to record sale: ' . $th->getMessage(), 500);
        }
    }

    /** Store all manually entered route POS invoices atomically. */
    public function storeManualRoute(Request $request)
    {
        abort_unless($request->user()?->role === 'AGENT', 403);
        $request->validate([
            'sales' => 'required|array|min:1',
            'sales.*' => 'required|array',
        ]);

        try {
            $sales = DB::transaction(function () use ($request) {
                return collect($request->input('sales'))->map(function (array $salePayload) use ($request) {
                    $child = Request::create('/api/v1/sales', 'POST', $salePayload);
                    $child->setUserResolver(fn () => $request->user());
                    $response = $this->store($child);

                    if ($response->getStatusCode() >= 400) {
                        $body = $response->getData(true);
                        throw ValidationException::withMessages([
                            'sales' => $body['message'] ?? 'One of the route POS sales could not be saved.',
                        ]);
                    }

                    return $response->getData(true)['data'] ?? null;
                })->filter()->values()->all();
            });

            return $this->successResponse($sales, 'Manual route POS sales saved successfully', 201);
        } catch (ValidationException $th) {
            throw $th;
        } catch (Throwable $th) {
            return $this->errorResponse('Failed to save manual route POS sales: ' . $th->getMessage(), 500);
        }
    }

    /**
     * Display the specified sale.
     */
    public function show(Request $request, $id)
    {
        try {
            $sale = Sale::with(['rep', 'user', 'shop', 'branch', 'dsrTrip', 'salesItems.item', 'payments.bankAccount', 'payments.verifiedBy'])
                ->when($request->user()->role === 'DSR_REP', fn ($q) => $q->where('rep_id', $request->user()->id))->find($id);

            if (!$sale) {
                return $this->errorResponse('Sale not found', 404);
            }

            return $this->successResponse($sale, 'Sale details retrieved successfully', 200);
        } catch (\Illuminate\Validation\ValidationException | \Illuminate\Database\Eloquent\ModelNotFoundException | \Symfony\Component\HttpKernel\Exception\HttpException $th) {
            throw $th;
        } catch (Throwable $th) {
            return $this->errorResponse('Failed to retrieve sale details', 500, $th->getMessage());
        }
    }

    /**
     * Update the specified sale.
     */
    public function update(Request $request, $id)
    {
        abort_unless(in_array($request->user()->role, ['AGENT', 'SYSTEM_ADMIN']), 403);
        try {
            $sale = Sale::find($id);

            if (!$sale) {
                return $this->errorResponse('Sale not found', 404);
            }

            $validator = Validator::make($request->all(), [
                'status' => 'nullable|string|in:PENDING,COMPLETED,CANCELLED',
                'payment_type' => 'nullable|string|in:CASH,CREDIT,CHEQUE,ONLINE,CARD,SPLIT,BANK_TRANSFER,TRANSFER',
                'paid_amount' => 'nullable|numeric|min:0',
                'notes' => 'nullable|string',
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

            $globalSlipPath = $this->resolveSlipPath($request);

            DB::transaction(function () use ($request, $sale, $globalSlipPath) {
                if ($sale->rep_id) User::whereKey($sale->rep_id)->lockForUpdate()->first();
                if ($sale->shop_id) Shop::whereKey($sale->shop_id)->lockForUpdate()->first();
                $sale = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();
                // Completed invoices are accounting records. Cancel using the dedicated status transition.
                $nextStatus = $request->input('status', $sale->status);
                if ($nextStatus !== $sale->status && $nextStatus !== 'CANCELLED') {
                    throw ValidationException::withMessages(['status' => 'Create a new invoice instead of reopening a sale.']);
                }
                if ($nextStatus === 'CANCELLED' && $sale->status !== 'CANCELLED') {
                    $sale->refresh();
                    if ($sale->rep_id) app(RepInventory::class)->restore((int) $sale->rep_id, $sale->id);
                    if ($sale->shop_id && (float) $sale->due_amount > 0) {
                        Shop::whereKey($sale->shop_id)->decrement('current_credit_balance', (float) $sale->due_amount);
                    }
                    foreach ($sale->payments as $payment) {
                        if ($payment->bank_account_id && $payment->payment_type !== 'CREDIT' && $payment->status !== 'REJECTED') {
                            BankAccount::whereKey($payment->bank_account_id)->decrement('current_balance', (float) $payment->amount);
                        }
                        $payment->update(['status' => 'REJECTED']);
                    }
                    $sale->paid_amount = 0;
                    $sale->due_amount = 0;
                    $sale->status = 'CANCELLED';
                }

                if ($request->has('payment_type')) {
                    $sale->payment_type = strtoupper($request->input('payment_type'));
                }

                if ($request->has('notes')) {
                    $sale->notes = $request->input('notes');
                }

                if ($request->hasAny(['payments', 'paid_amount', 'payment_type', 'bank_account_id'])) {
                    throw ValidationException::withMessages(['payments' => 'Use the collection endpoint to record payments; issued invoice payment records cannot be replaced.']);
                }

                $sale->save();
            });

            return $this->successResponse(
                $sale->fresh(['rep', 'user', 'shop', 'branch', 'dsrTrip', 'salesItems.item', 'payments.bankAccount', 'payments.verifiedBy']),
                'Sale updated successfully',
                200
            );
        } catch (\Illuminate\Validation\ValidationException | \Illuminate\Database\Eloquent\ModelNotFoundException | \Symfony\Component\HttpKernel\Exception\HttpException $th) {
            throw $th;
        } catch (Throwable $th) {
            return $this->errorResponse('Failed to update sale', 500, $th->getMessage());
        }
    }

    /**
     * Remove the specified sale.
     */
    public function destroy(Request $request, $id)
    {
        abort_unless(in_array($request->user()->role, ['AGENT', 'SYSTEM_ADMIN']), 403);
        try {
            $sale = Sale::find($id);

            if (!$sale) {
                return $this->errorResponse('Sale not found', 404);
            }

            if ($sale->status === 'COMPLETED') {
                throw ValidationException::withMessages(['sale' => 'Cancel a completed sale before deleting it.']);
            }
            $sale->delete();

            return $this->successResponse(null, 'Sale deleted successfully', 200);
        } catch (\Illuminate\Validation\ValidationException | \Illuminate\Database\Eloquent\ModelNotFoundException | \Symfony\Component\HttpKernel\Exception\HttpException $th) {
            throw $th;
        } catch (Throwable $th) {
            return $this->errorResponse('Failed to delete sale', 500, $th->getMessage());
        }
    }
}
