<?php

namespace App\Http\Controllers;

use App\Http\Traits\ApiResponse;
use App\Models\BankAccount;
use App\Models\Sale;
use App\Models\SalePayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Throwable;

class BankTransferController extends Controller
{
    use ApiResponse;

    /**
     * Resolve slip image path from file upload, base64 data, or pre-uploaded path.
     */
    protected function resolveSlipPath(Request $request, string $field = 'bank_slip'): ?string
    {
        // 1. Check for file upload across common parameter keys
        $file = $request->file('bank_slip')
            ?? $request->file('slip_image')
            ?? $request->file('slip')
            ?? $request->file('image')
            ?? $request->file($field);

        if ($file && $file->isValid()) {
            return $file->store('bank_slips', 'public');
        }

        // 2. Check for string input (base64 or pre-uploaded path)
        $input = $request->input('bank_slip')
            ?? $request->input('slip_image')
            ?? $request->input('slip')
            ?? $request->input($field);

        if (is_string($input) && trim($input) !== '') {
            $input = trim($input);

            // Data URI scheme (e.g. data:image/png;base64,...)
            if (preg_match('/^data:image\/(\w+);base64,/', $input, $type)) {
                $rawBase64 = substr($input, strpos($input, ',') + 1);
                $ext = strtolower($type[1]);
                $ext = $ext === 'jpeg' ? 'jpg' : $ext;
                $decoded = base64_decode($rawBase64);
                if ($decoded !== false) {
                    $path = 'bank_slips/' . uniqid('slip_', true) . '.' . $ext;
                    Storage::disk('public')->put($path, $decoded);
                    return $path;
                }
            } elseif (strlen($input) > 200 && base64_decode($input, true)) {
                $decoded = base64_decode($input);
                if ($decoded !== false) {
                    $path = 'bank_slips/' . uniqid('slip_', true) . '.jpg';
                    Storage::disk('public')->put($path, $decoded);
                    return $path;
                }
            }

            // Normal file path or URL
            return $input;
        }

        return null;
    }

    /**
     * Upload a standalone bank transfer slip image (for mobile or web).
     */
    public function uploadSlip(Request $request)
    {
        try {
            $file = $request->file('bank_slip')
                ?? $request->file('slip_image')
                ?? $request->file('slip')
                ?? $request->file('image');

            if (!$file) {
                // Check if string base64 is provided
                $path = $this->resolveSlipPath($request);
                if ($path) {
                    return $this->successResponse([
                        'slip_path' => $path,
                        'slip_url'  => Storage::disk('public')->url($path),
                    ], 'Bank slip uploaded successfully', 201);
                }

                return $this->errorResponse('Please upload a bank slip file or image', 422);
            }

            $validated = $request->validate([
                'bank_slip'  => 'nullable|file|mimes:jpeg,jpg,png,webp,pdf|max:10240',
                'slip_image' => 'nullable|file|mimes:jpeg,jpg,png,webp,pdf|max:10240',
                'slip'       => 'nullable|file|mimes:jpeg,jpg,png,webp,pdf|max:10240',
                'image'      => 'nullable|file|mimes:jpeg,jpg,png,webp,pdf|max:10240',
            ]);

            $path = $file->store('bank_slips', 'public');

            return $this->successResponse([
                'slip_path'     => $path,
                'slip_url'      => Storage::disk('public')->url($path),
                'original_name' => $file->getClientOriginalName(),
                'mime_type'     => $file->getClientMimeType(),
                'size'          => $file->getSize(),
            ], 'Bank slip uploaded successfully', 201);
        } catch (\Illuminate\Validation\ValidationException | \Symfony\Component\HttpKernel\Exception\HttpException $th) {
            throw $th;
        } catch (Throwable $th) {
            return $this->errorResponse('Failed to upload bank slip: ' . $th->getMessage(), 500);
        }
    }

    /**
     * Attach a bank transfer slip to an existing sale (from mobile or web).
     */
    public function attachSlipToSale(Request $request, $saleId)
    {
        $sale = Sale::findOrFail($saleId);
        abort_if($request->user()->role === 'DSR_REP' && (int) $sale->rep_id !== $request->user()->id, 404);
        $request->validate(['payment_id' => 'nullable|integer']);
        $query = $sale->payments()->whereIn('payment_type', ['ONLINE', 'BANK_TRANSFER', 'TRANSFER', 'CARD']);
        if ($request->filled('payment_id')) $query->whereKey($request->integer('payment_id'));
        $payment = $query->first();
        if (!$payment) {
            throw \Illuminate\Validation\ValidationException::withMessages(['payment_id' => 'Record a bank payment when creating the sale before attaching its receipt.']);
        }
        $payment = $this->savePaymentEvidence($request, $payment);
        return $this->successResponse(['sale' => $sale->fresh(['payments.bankAccount', 'shop', 'rep']), 'payment' => $payment], 'Bank slip attached to sale successfully');
    }

    public function attachSlipToPayment(Request $request, $paymentId)
    {
        $payment = SalePayment::with('sale')->findOrFail($paymentId);
        abort_if($request->user()->role === 'DSR_REP' && (int) $payment->sale?->rep_id !== $request->user()->id, 404);
        return $this->successResponse($this->savePaymentEvidence($request, $payment), 'Bank slip attached to payment successfully');
    }

    private function savePaymentEvidence(Request $request, SalePayment $payment): SalePayment
    {
        $data = $request->validate(['bank_account_id' => 'nullable|integer|exists:bank_accounts,id',
            'reference_number' => 'nullable|string|max:255', 'notes' => 'nullable|string', 'amount' => 'nullable|numeric|min:0']);
        return DB::transaction(function () use ($request, $payment, $data) {
            $sale = Sale::whereKey($payment->sale_id)->lockForUpdate()->firstOrFail();
            $payment = SalePayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($sale->status === 'CANCELLED' || $payment->status === 'VERIFIED'
                || !in_array($payment->payment_type, ['ONLINE', 'BANK_TRANSFER', 'TRANSFER', 'CARD'])) {
                throw \Illuminate\Validation\ValidationException::withMessages(['payment' => 'Receipts can only be attached to unverified bank payments on active invoices.']);
            }
            if ((isset($data['bank_account_id']) && (int) $data['bank_account_id'] !== (int) $payment->bank_account_id)
                || (isset($data['amount']) && round((float) $data['amount'], 2) !== round((float) $payment->amount, 2))) {
                throw \Illuminate\Validation\ValidationException::withMessages(['payment' => 'Attaching a receipt cannot change the recorded account or amount.']);
            }
            $path = $this->resolveSlipPath($request);
            if (!$path) throw \Illuminate\Validation\ValidationException::withMessages(['bank_slip' => 'Please provide a bank slip image.']);
            $payment->update(['slip_image' => $path, 'reference_number' => $data['reference_number'] ?? $payment->reference_number,
                'notes' => $data['notes'] ?? $payment->notes, 'status' => $payment->status === 'REJECTED' ? 'REJECTED' : 'PENDING']);
            return $payment->load(['bankAccount', 'sale.shop', 'sale.rep', 'verifiedBy']);
        }, 3);
    }

    public function index(Request $request)
    {
        try {
            $query = SalePayment::with([
                'bankAccount',
                'verifiedBy:id,name,role',
                'sale' => function ($q) {
                    $q->with(['shop', 'rep:id,name,phone', 'branch:id,name,code']);
                },
            ])->where(function ($q) {
                $q->whereNotNull('bank_account_id')
                  ->orWhereIn('payment_type', ['ONLINE', 'BANK_TRANSFER', 'TRANSFER'])
                  ->orWhereNotNull('slip_image');
            });

            // Filter by verification status (PENDING, VERIFIED, REJECTED, CONFIRMED)
            if ($request->filled('status')) {
                $query->where('status', strtoupper($request->status));
            }

            // Filter by specific bank account
            if ($request->filled('bank_account_id')) {
                $query->where('bank_account_id', $request->bank_account_id);
            }

            // Filter by slip presence
            if ($request->has('has_slip')) {
                $hasSlip = filter_var($request->has_slip, FILTER_VALIDATE_BOOLEAN);
                if ($hasSlip) {
                    $query->whereNotNull('slip_image');
                } else {
                    $query->whereNull('slip_image');
                }
            }

            // Filter by rep
            if ($request->filled('rep_id')) {
                $repId = $request->rep_id;
                $query->whereHas('sale', function ($sq) use ($repId) {
                    $sq->where('rep_id', $repId);
                });
            }

            // Filter by shop
            if ($request->filled('shop_id')) {
                $shopId = $request->shop_id;
                $query->whereHas('sale', function ($sq) use ($shopId) {
                    $sq->where('shop_id', $shopId);
                });
            }

            // Filter by date range
            if ($request->filled('date_from')) {
                $query->whereDate('payment_date', '>=', $request->date_from);
            }
            if ($request->filled('date_to')) {
                $query->whereDate('payment_date', '<=', $request->date_to);
            }

            // Search by reference number, notes, sale_code, or shop name
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('reference_number', 'like', "%{$search}%")
                      ->orWhere('notes', 'like', "%{$search}%")
                      ->orWhereHas('sale', function ($sq) use ($search) {
                          $sq->where('sale_code', 'like', "%{$search}%")
                            ->orWhereHas('shop', function ($shq) use ($search) {
                                $shq->where('shop_name', 'like', "%{$search}%")
                                    ->orWhere('shop_code', 'like', "%{$search}%")
                                    ->orWhere('owner_name', 'like', "%{$search}%");
                            });
                      })
                      ->orWhereHas('bankAccount', function ($bq) use ($search) {
                          $bq->where('bank_name', 'like', "%{$search}%")
                            ->orWhere('account_number', 'like', "%{$search}%");
                      });
                });
            }

            // Clone query to compute summary metrics
            $totalCount = (clone $query)->count();
            $totalAmount = (float) (clone $query)->sum('amount');
            $pendingCount = (clone $query)->where('status', 'PENDING')->count();
            $verifiedCount = (clone $query)->where('status', 'VERIFIED')->count();
            $rejectedCount = (clone $query)->where('status', 'REJECTED')->count();
            $withSlipCount = (clone $query)->whereNotNull('slip_image')->count();

            $query->orderBy('id', 'desc');

            $data = $request->has('per_page')
                ? $query->paginate((int) $request->input('per_page', 20))
                : $query->get();

            return response()->json([
                'success' => true,
                'message' => 'Bank transfer records retrieved successfully',
                'summary' => [
                    'total_transfers'      => $totalCount,
                    'total_amount'         => $totalAmount,
                    'pending_verification' => $pendingCount,
                    'verified_transfers'   => $verifiedCount,
                    'rejected_transfers'   => $rejectedCount,
                    'with_slip_uploaded'   => $withSlipCount,
                ],
                'data' => $data,
            ], 200);
        } catch (Throwable $th) {
            return $this->errorResponse('Failed to retrieve bank transfers: ' . $th->getMessage(), 500);
        }
    }

    /**
     * Show single bank transfer record with detailed verification and sale context.
     */
    public function show($id)
    {
        try {
            $payment = SalePayment::with([
                'bankAccount',
                'verifiedBy:id,name,role',
                'sale' => function ($q) {
                    $q->with([
                        'shop',
                        'rep',
                        'branch',
                        'salesItems.item',
                    ]);
                },
            ])->find($id);

            if (!$payment) {
                return $this->errorResponse('Bank transfer payment not found', 404);
            }

            return $this->successResponse($payment, 'Bank transfer payment retrieved successfully', 200);
        } catch (Throwable $th) {
            return $this->errorResponse('Failed to retrieve bank transfer: ' . $th->getMessage(), 500);
        }
    }

    public function verify(Request $request, $id)
    {
        abort_unless(in_array($request->user()->role, ['AGENT', 'SYSTEM_ADMIN']), 403);
        $data = $request->validate(['status' => 'required|in:VERIFIED,REJECTED',
            'rejection_reason' => 'nullable|string|max:500', 'notes' => 'nullable|string|max:2000']);
        $payment = DB::transaction(function () use ($request, $id, $data) {
            $payment = SalePayment::with('sale')->findOrFail($id);
            if ($payment->sale?->rep_id) \App\Models\User::whereKey($payment->sale->rep_id)->lockForUpdate()->first();
            if ($payment->sale?->shop_id) \App\Models\Shop::whereKey($payment->sale->shop_id)->lockForUpdate()->first();
            $sale = Sale::whereKey($payment->sale_id)->lockForUpdate()->firstOrFail();
            $payment = SalePayment::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($sale->status === 'CANCELLED') {
                throw \Illuminate\Validation\ValidationException::withMessages(['sale' => 'A cancelled sale cannot receive verified payments.']);
            }
            if ($payment->bank_account_id && (($payment->status === 'REJECTED') !== ($data['status'] === 'REJECTED'))) {
                $sign = $data['status'] === 'REJECTED' ? -1 : 1;
                BankAccount::whereKey($payment->bank_account_id)->lockForUpdate()->firstOrFail()->increment('current_balance', $sign * (float) $payment->amount);
            }
            $oldDue = (float) $sale->due_amount;
            $payment->update(['status' => $data['status'], 'verified_by' => $request->user()->id, 'verified_at' => now(),
                'rejection_reason' => $data['status'] === 'REJECTED' ? ($data['rejection_reason'] ?? 'Rejected by agent') : null,
                'notes' => $data['notes'] ?? $payment->notes]);
            $paid = (float) $sale->payments()->where('payment_type', '!=', 'CREDIT')->where('status', '!=', 'REJECTED')->sum('amount');
            $due = max(0, round((float) $sale->total_amount - $paid, 2));
            $sale->update(['paid_amount' => min((float) $sale->total_amount, $paid), 'due_amount' => $due]);
            if ($sale->shop_id) \App\Models\Shop::whereKey($sale->shop_id)->increment('current_credit_balance', $due - $oldDue);
            return $payment->load(['bankAccount', 'sale.shop', 'sale.rep', 'verifiedBy:id,name,role']);
        }, 3);
        return $this->successResponse($payment, 'Payment verification saved');
    }
}
