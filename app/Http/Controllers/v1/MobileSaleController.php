<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\BankAccount;
use App\Models\DailySettlement;
use App\Models\DailySettlementItem;
use App\Models\DsrBankDeposit;
use App\Models\Sale;
use App\Models\SalePayment;
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
            $query = Sale::with(['shop', 'salesItems.item', 'payments.bankAccount'])
                ->where('rep_id', $rep->id);

            if ($request->has('date')) {
                $dateVal = $request->query('date');
                if ($dateVal === 'today') {
                    $dateVal = now()->format('Y-m-d');
                }
                $query->whereDate('sale_date', $dateVal);
            }

            if ($request->filled('start_date') || $request->filled('date_from')) {
                $query->whereDate('sale_date', '>=', $request->query('start_date') ?? $request->query('date_from'));
            }
            if ($request->filled('end_date') || $request->filled('date_to')) {
                $query->whereDate('sale_date', '<=', $request->query('end_date') ?? $request->query('date_to'));
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
        } catch (\Illuminate\Validation\ValidationException | \Illuminate\Database\Eloquent\ModelNotFoundException | \Symfony\Component\HttpKernel\Exception\HttpException $th) {
            throw $th;
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

            if ($request->filled('start_date') || $request->filled('date_from')) {
                $query->whereDate('sale_date', '>=', $request->query('start_date') ?? $request->query('date_from'));
            }
            if ($request->filled('end_date') || $request->filled('date_to')) {
                $query->whereDate('sale_date', '<=', $request->query('end_date') ?? $request->query('date_to'));
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
        } catch (\Illuminate\Validation\ValidationException | \Illuminate\Database\Eloquent\ModelNotFoundException | \Symfony\Component\HttpKernel\Exception\HttpException $th) {
            throw $th;
        } catch (Throwable $th) {
            return $this->errorResponse('Failed to retrieve today sales', 500, $th->getMessage());
        }
    }

    /** Store a new sale created by mobile rep with optional split payments. */
    public function store(Request $request)
    {
        abort_unless($request->user()->role === 'DSR_REP', 403);
        return app(\App\Http\Controllers\SaleController::class)->store($request);
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
        } catch (\Illuminate\Validation\ValidationException | \Illuminate\Database\Eloquent\ModelNotFoundException | \Symfony\Component\HttpKernel\Exception\HttpException $th) {
            throw $th;
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
                ->when($request->user()->role === 'DSR_REP', fn ($q) => $q->where('rep_id', $request->user()->id))
                ->where('due_amount', '>', 0)
                ->orderBy('id', 'desc')
                ->get();

            return $this->successResponse($sales, 'Shop credit sales retrieved successfully');
        } catch (\Illuminate\Validation\ValidationException | \Illuminate\Database\Eloquent\ModelNotFoundException | \Symfony\Component\HttpKernel\Exception\HttpException $th) {
            throw $th;
        } catch (Throwable $th) {
            return $this->errorResponse('Failed to retrieve shop credit sales', 500, $th->getMessage());
        }
    }

    /** Get daily settlement sheet calculation or stored sheet for sales & collections. */
    public function getDailySettlementSheet(Request $request)
    {
        try {
            $user = $request->user();
            $repId = $user->role === 'DSR_REP' ? $user->id : (int) $request->query('rep_id', $user->id);
            $request->validate(['date' => 'nullable|date_format:Y-m-d', 'rep_id' => 'nullable|integer|exists:users,id']);
            $date = $request->query('date', now()->format('Y-m-d'));

            // Check if settlement sheet is already submitted & stored in database for this date and rep
            if ($repId && !$request->boolean('live')) {
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
                        'cash_counted' => $savedSettlement->cash_counted,
                        'total_credit_collected' => (float) $savedSettlement->total_credit_collected,
                        'is_saved' => true,
                    ], 'Stored daily settlement sheet retrieved successfully');
                }
            }

            return $this->successResponse(app(\App\Services\SettlementSummary::class)->forRep((int) $repId, $date), 'Live daily settlement preview retrieved successfully');
        } catch (\Illuminate\Validation\ValidationException | \Illuminate\Database\Eloquent\ModelNotFoundException | \Symfony\Component\HttpKernel\Exception\HttpException $th) {
            throw $th;
        } catch (Throwable $th) {
            return $this->errorResponse('Failed to retrieve daily settlement sheet', 500, $th->getMessage());
        }
    }

    /** Store & Submit daily settlement sheet into database. */
    public function storeDailySettlementSheet(Request $request)
    {
        try {
            $user = $request->user();
            $repId = $user->role === 'DSR_REP' ? $user->id : (int) $request->input('rep_id', $user->id);
            $request->validate(['rep_id' => 'nullable|integer|exists:users,id']);

            if (!$repId) {
                return $this->errorResponse('Rep ID is required to submit settlement sheet', 422);
            }

            $validator = Validator::make($request->all(), [
                'date' => 'required|date_format:Y-m-d',
                'cash_counted' => 'nullable|numeric|min:0|decimal:0,2',
                'shops_visited_count' => 'nullable|integer|min:0',
                'total_sales_value' => 'nullable|numeric|min:0',
                'total_cash_collected' => 'nullable|numeric|min:0',
                'total_cheques_collected' => 'nullable|numeric|min:0',
                'total_online_collected' => 'nullable|numeric|min:0',
                'total_credit_issued' => 'nullable|numeric|min:0',
                'ending_shop_credit' => 'nullable|numeric|min:0',
                'dsr_trip_id' => 'nullable|integer',
                'notes' => 'nullable|string',
                'items' => 'nullable|array',
                'items.*.item_id' => 'required|integer|exists:items,id',
                'items.*.sold_qty' => 'nullable|integer|min:0',
                'items.*.sold_quantity' => 'nullable|integer|min:0',
                'items.*.total_revenue' => 'nullable|numeric|min:0',
                'bank_deposits' => 'nullable|array',
                'bank_deposits.*.bank_account_id' => 'required_with:bank_deposits|integer|exists:bank_accounts,id',
                'bank_deposits.*.amount' => 'required_with:bank_deposits|numeric|min:0.01',
                'bank_deposits.*.slip_reference' => 'nullable|string',
                'bank_deposits.*.notes' => 'nullable|string',
                'bank_deposits.*.deposit_slip_image' => 'nullable|string|max:2048',
                'bank_deposits.*.slip_image' => 'nullable|string|max:2048',
            ]);

            if ($validator->fails()) {
                return $this->errorResponse('Validation failed', 422, $validator->errors());
            }

            $date = $request->input('date', now()->format('Y-m-d'));

            $settlement = DB::transaction(function () use ($request, $repId, $date) {
                $rep = \App\Models\User::whereKey($repId)->lockForUpdate()->firstOrFail();
                if ($rep->role !== 'DSR_REP') {
                    throw \Illuminate\Validation\ValidationException::withMessages(['rep_id' => 'Select a sales representative.']);
                }
                $snapshot = app(\App\Services\SettlementSummary::class)->forRep((int) $repId, $date);
                $snapshot['items'] = $snapshot['items_matrix'];
                unset($snapshot['status']);
                $request->merge($snapshot);
                $settlementRecord = DailySettlement::where('rep_id', $repId)
                    ->whereDate('settlement_date', $date)
                    ->first();

                $settlementCode = $settlementRecord
                    ? $settlementRecord->settlement_code
                    : ('SETTLE-' . now()->format('Ymd') . '-' . strtoupper(bin2hex(random_bytes(8))));

                if ($settlementRecord && $settlementRecord->status === 'APPROVED') {
                    throw \Illuminate\Validation\ValidationException::withMessages(['status' => 'Approved settlements cannot be edited.']);
                }
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
                        'cash_counted' => $request->input('cash_counted'),
                        'total_credit_collected' => $request->input('total_credit_collected', 0),
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
                        'cash_counted' => $request->input('cash_counted'),
                        'total_credit_collected' => $request->input('total_credit_collected', 0),
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

                // Treat supplied deposits as a snapshot; a retry must not credit the bank again.
                if ($request->has('bank_deposits') && is_array($request->input('bank_deposits'))) {
                    $normalize = fn ($bd) => [
                        'bank_account_id' => (int) $bd['bank_account_id'],
                        'amount' => number_format((float) $bd['amount'], 2, '.', ''),
                        'slip_reference' => $bd['slip_reference'] ?? null,
                        'deposit_slip_image' => $bd['deposit_slip_image'] ?? $bd['slip_image'] ?? null,
                        'notes' => $bd['notes'] ?? null,
                    ];
                    $old = $settlementRecord->bankDeposits()->orderBy('id')->lockForUpdate()->get();
                    $incoming = array_map($normalize, $request->input('bank_deposits'));
                    $previous = $old->map(fn ($bd) => $normalize($bd->toArray()))->all();
                    if ($incoming !== $previous) {
                        if ($old->contains(fn ($deposit) => in_array($deposit->status, ['VERIFIED', 'REJECTED']))) {
                            throw \Illuminate\Validation\ValidationException::withMessages(['bank_deposits' => 'Reviewed deposits cannot be replaced.']);
                        }
                        // Stable bank-lock order prevents conflicting settlement edits from deadlocking.
                        BankAccount::whereIn('id', array_unique(array_merge(array_column($previous, 'bank_account_id'), array_column($incoming, 'bank_account_id'))))
                            ->orderBy('id')->lockForUpdate()->get();
                        foreach ($old as $bd) {
                            BankAccount::whereKey($bd->bank_account_id)->decrement('current_balance', (float) $bd->amount);
                        }
                        $settlementRecord->bankDeposits()->delete();
                        foreach ($incoming as $bd) {
                            DsrBankDeposit::create($bd + [
                                'rep_id' => $repId, 'daily_settlement_id' => $settlementRecord->id,
                                'dsr_trip_id' => $request->input('dsr_trip_id'), 'deposit_date' => $date, 'status' => 'CONFIRMED',
                            ]);
                            BankAccount::whereKey($bd['bank_account_id'])->increment('current_balance', (float) $bd['amount']);
                        }
                    }
                }

                return $settlementRecord->load(['items', 'rep', 'dsrTrip', 'bankDeposits.bankAccount']);
            });

            return $this->successResponse($settlement, 'Daily settlement sheet submitted and saved to database successfully', 201);
        } catch (\Illuminate\Validation\ValidationException | \Illuminate\Database\Eloquent\ModelNotFoundException | \Symfony\Component\HttpKernel\Exception\HttpException $th) {
            throw $th;
        } catch (Throwable $th) {
            return $this->errorResponse('Failed to store daily settlement sheet: ' . $th->getMessage(), 500);
        }
    }

    /** List all daily settlement sheets for admin/supervisor audit. */
    public function getAllDailySettlements(Request $request)
    {
        abort_unless(in_array($request->user()->role, ['AGENT', 'SYSTEM_ADMIN']), 403);
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

            foreach ($settlements as $settlement) {
                $settlement->setAttribute('sale_payments', SalePayment::with('bankAccount')
                    ->whereDate('payment_date', $settlement->settlement_date->format('Y-m-d'))
                    ->whereHas('sale', fn ($q) => $q->where('rep_id', $settlement->rep_id)->where('status', 'COMPLETED'))
                    ->where(function ($q) { $q->whereNotNull('bank_account_id')->orWhereNotNull('slip_image')->orWhereIn('payment_type', ['ONLINE', 'BANK_TRANSFER', 'TRANSFER']); })
                    ->get());
            }
            return $this->successResponse($settlements, 'Daily settlements list retrieved successfully');
        } catch (\Illuminate\Validation\ValidationException | \Illuminate\Database\Eloquent\ModelNotFoundException | \Symfony\Component\HttpKernel\Exception\HttpException $th) {
            throw $th;
        } catch (Throwable $th) {
            return $this->errorResponse('Failed to retrieve daily settlements: ' . $th->getMessage(), 500);
        }
    }

    /** Update daily settlement approval status. */
    public function updateDailySettlementStatus(Request $request, $id)
    {
        abort_unless(in_array($request->user()->role, ['AGENT', 'SYSTEM_ADMIN']), 403);
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

            $settlement = DB::transaction(function () use ($request, $settlement) {
                \App\Models\User::whereKey($settlement->rep_id)->lockForUpdate()->first();
                $settlement->refresh();
                if ($settlement->status !== $request->input('status')) {
                    $settlement->audited_by = $request->user()->id;
                    $settlement->audited_at = now();
                }
                $settlement->status = $request->input('status');
                if ($request->filled('notes')) {
                    $settlement->notes = $request->input('notes');
                }
                $settlement->save();
                return $settlement;
            });

            return $this->successResponse($settlement, 'Daily settlement status updated successfully');
        } catch (\Illuminate\Validation\ValidationException | \Illuminate\Database\Eloquent\ModelNotFoundException | \Symfony\Component\HttpKernel\Exception\HttpException $th) {
            throw $th;
        } catch (Throwable $th) {
            return $this->errorResponse('Failed to update settlement status: ' . $th->getMessage(), 500);
        }
    }
}
