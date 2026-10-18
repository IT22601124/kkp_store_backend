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
        } catch (Throwable $th) {
            return $this->errorResponse('Failed to upload bank slip: ' . $th->getMessage(), 500);
        }
    }

    /**
     * Attach a bank transfer slip to an existing sale (from mobile or web).
     */
    public function attachSlipToSale(Request $request, $saleId)
    {
        try {
            $sale = Sale::with(['payments.bankAccount', 'shop', 'rep'])->find($saleId);

            if (!$sale) {
                return $this->errorResponse('Sale not found', 404);
            }

            $request->validate([
                'bank_account_id'  => 'nullable|integer|exists:bank_accounts,id',
                'reference_number' => 'nullable|string|max:255',
                'notes'            => 'nullable|string',
                'payment_id'       => 'nullable|integer|exists:sale_payments,id',
                'amount'           => 'nullable|numeric|min:0',
            ]);

            $slipPath = $this->resolveSlipPath($request);

            if (!$slipPath) {
                return $this->errorResponse('Please provide a bank slip image (file, base64, or pre-uploaded path)', 422);
            }

            DB::beginTransaction();

            // Locate target payment
            $targetPayment = null;
            if ($request->filled('payment_id')) {
                $targetPayment = $sale->payments->firstWhere('id', (int) $request->payment_id);
            }

            if (!$targetPayment) {
                // Find payment with matching bank_account_id or bank transfer/online type
                $targetPayment = $sale->payments->first(function ($p) use ($request) {
                    if ($request->filled('bank_account_id') && (int) $p->bank_account_id === (int) $request->bank_account_id) {
                        return true;
                    }
                    return in_array(strtoupper($p->payment_type), ['ONLINE', 'BANK_TRANSFER', 'TRANSFER']);
                });
            }

            if (!$targetPayment) {
                // Find any payment with empty slip or create a new one
                $targetPayment = $sale->payments->first(fn($p) => empty($p->slip_image));
            }

            if ($targetPayment) {
                $updateData = [
                    'slip_image' => $slipPath,
                    'status'     => 'PENDING',
                ];

                if ($request->filled('bank_account_id')) {
                    $updateData['bank_account_id'] = $request->bank_account_id;
                }

                if ($request->filled('reference_number')) {
                    $updateData['reference_number'] = $request->reference_number;
                }

                if ($request->filled('notes')) {
                    $updateData['notes'] = $request->notes;
                }

                if (!in_array(strtoupper($targetPayment->payment_type), ['ONLINE', 'BANK_TRANSFER', 'TRANSFER'])) {
                    $updateData['payment_type'] = 'BANK_TRANSFER';
                }

                $targetPayment->update($updateData);
            } else {
                // Create a new bank payment for the sale
                $pAmount = (float) ($request->input('amount') ?? ($sale->due_amount > 0 ? $sale->due_amount : $sale->total_amount));
                $targetPayment = SalePayment::create([
                    'sale_id'          => $sale->id,
                    'bank_account_id'  => $request->input('bank_account_id'),
                    'payment_type'     => 'BANK_TRANSFER',
                    'amount'           => $pAmount,
                    'reference_number' => $request->input('reference_number'),
                    'payment_date'     => now(),
                    'slip_image'       => $slipPath,
                    'status'           => 'PENDING',
                    'notes'            => $request->input('notes'),
                ]);

                if (!empty($targetPayment->bank_account_id) && $pAmount > 0) {
                    BankAccount::find($targetPayment->bank_account_id)?->increment('current_balance', $pAmount);
                }
            }

            DB::commit();

            return $this->successResponse([
                'sale'    => $sale->fresh(['payments.bankAccount', 'payments.verifiedBy', 'shop', 'rep']),
                'payment' => $targetPayment->fresh(['bankAccount', 'verifiedBy']),
            ], 'Bank slip attached to sale successfully', 200);
        } catch (Throwable $th) {
            DB::rollBack();
            return $this->errorResponse('Failed to attach bank slip: ' . $th->getMessage(), 500);
        }
    }

    /**
     * Attach a bank slip directly to a specific payment record.
     */
    public function attachSlipToPayment(Request $request, $paymentId)
    {
        try {
            $payment = SalePayment::with(['sale.shop', 'sale.rep', 'bankAccount'])->find($paymentId);

            if (!$payment) {
                return $this->errorResponse('Payment record not found', 404);
            }

            $request->validate([
                'bank_account_id'  => 'nullable|integer|exists:bank_accounts,id',
                'reference_number' => 'nullable|string|max:255',
                'notes'            => 'nullable|string',
            ]);

            $slipPath = $this->resolveSlipPath($request);

            if (!$slipPath) {
                return $this->errorResponse('Please provide a bank slip image (file, base64, or pre-uploaded path)', 422);
            }

            $updateData = [
                'slip_image' => $slipPath,
                'status'     => 'PENDING',
            ];

            if ($request->filled('bank_account_id')) {
                $updateData['bank_account_id'] = $request->bank_account_id;
            }

            if ($request->filled('reference_number')) {
                $updateData['reference_number'] = $request->reference_number;
            }

            if ($request->filled('notes')) {
                $updateData['notes'] = $request->notes;
            }

            if (!in_array(strtoupper($payment->payment_type), ['ONLINE', 'BANK_TRANSFER', 'TRANSFER'])) {
                $updateData['payment_type'] = 'BANK_TRANSFER';
            }

            $payment->update($updateData);

            return $this->successResponse(
                $payment->fresh(['bankAccount', 'sale.shop', 'sale.rep', 'verifiedBy']),
                'Bank slip attached to payment successfully',
                200
            );
        } catch (Throwable $th) {
            return $this->errorResponse('Failed to attach bank slip to payment: ' . $th->getMessage(), 500);
        }
    }

    /**
     * List bank transfer payments and slips for Agent / Web view.
     */
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

    /**
     * Verify or Reject a bank transfer slip (Agent/Web action).
     */
    public function verify(Request $request, $id)
    {
        try {
            $payment = SalePayment::with(['bankAccount', 'sale'])->find($id);

            if (!$payment) {
                return $this->errorResponse('Bank transfer payment not found', 404);
            }

            $validated = $request->validate([
                'status'           => 'required|string|in:VERIFIED,REJECTED,PENDING,CONFIRMED',
                'rejection_reason' => 'nullable|string|max:500',
                'notes'            => 'nullable|string|max:500',
            ]);

            $newStatus = strtoupper($validated['status']);
            $user = $request->user();

            $payment->status = $newStatus;
            $payment->verified_by = $user ? $user->id : null;
            $payment->verified_at = now();

            if ($newStatus === 'REJECTED') {
                $payment->rejection_reason = $validated['rejection_reason'] ?? $validated['notes'] ?? 'Rejected by agent';
            } elseif ($newStatus === 'VERIFIED') {
                $payment->rejection_reason = null;
            }

            if (!empty($validated['notes'])) {
                $payment->notes = $payment->notes ? ($payment->notes . ' | ' . $validated['notes']) : $validated['notes'];
            }

            $payment->save();

            return $this->successResponse(
                $payment->fresh(['bankAccount', 'sale.shop', 'sale.rep', 'verifiedBy:id,name,role']),
                "Bank transfer slip marked as {$newStatus} successfully",
                200
            );
        } catch (Throwable $th) {
            return $this->errorResponse('Failed to update bank transfer verification: ' . $th->getMessage(), 500);
        }
    }
}
