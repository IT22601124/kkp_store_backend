<?php

namespace App\Http\Controllers;

use App\Http\Traits\ApiResponse;
use App\Models\BankAccount;
use App\Models\DsrBankDeposit;
use App\Models\DsrTrip;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class BankAccountController extends Controller
{
    use ApiResponse;

    /**
     * Display a listing of agent bank accounts.
     */
    public function index(Request $request)
    {
        try {
            $query = BankAccount::withCount(['salePayments', 'dsrBankDeposits']);

            if ($request->has('active') && ($request->active === '1' || $request->active === 'true')) {
                $query->where('is_active', true);
            }

            if ($request->filled('search')) {
                $search = $request->query('search');
                $query->where(function ($q) use ($search) {
                    $q->where('bank_name', 'like', "%{$search}%")
                        ->orWhere('account_number', 'like', "%{$search}%")
                        ->orWhere('account_name', 'like', "%{$search}%")
                        ->orWhere('branch_name', 'like', "%{$search}%");
                });
            }

            $accounts = $query->orderBy('is_default', 'desc')
                ->orderBy('id', 'asc')
                ->get();

            return $this->successResponse(
                $accounts,
                'Agent bank accounts retrieved successfully',
                200
            );
        } catch (Throwable $th) {
            return $this->errorResponse(
                'Failed to retrieve bank accounts',
                500,
                $th->getMessage()
            );
        }
    }

    /**
     * Get active bank accounts for quick dropdown selection (e.g., mobile apps & POS).
     */
    public function getActiveAccounts()
    {
        try {
            $accounts = BankAccount::where('is_active', true)
                ->orderBy('is_default', 'desc')
                ->orderBy('bank_name', 'asc')
                ->get(['id', 'bank_name', 'account_number', 'account_name', 'branch_name', 'is_default', 'current_balance']);

            return $this->successResponse(
                $accounts,
                'Active agent bank accounts retrieved successfully',
                200
            );
        } catch (Throwable $th) {
            return $this->errorResponse(
                'Failed to retrieve active bank accounts',
                500,
                $th->getMessage()
            );
        }
    }

    /**
     * Store a newly created agent bank account.
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'bank_name'       => 'required|string|max:255',
                'account_number'  => 'required|string|max:100|unique:bank_accounts,account_number',
                'account_name'    => 'required|string|max:255',
                'branch_name'     => 'nullable|string|max:255',
                'account_type'    => 'nullable|string|in:CURRENT,SAVINGS,OTHER',
                'currency'        => 'nullable|string|max:10',
                'opening_balance' => 'nullable|numeric|min:0',
                'current_balance' => 'nullable|numeric|min:0',
                'is_default'      => 'nullable|boolean',
                'is_active'       => 'nullable|boolean',
                'notes'           => 'nullable|string',
            ]);

            DB::beginTransaction();

            $isDefault = $request->boolean('is_default', false);
            if ($isDefault) {
                BankAccount::query()->update(['is_default' => false]);
            }

            $user = $request->user();
            $validated['user_id'] = $user ? $user->id : null;
            $validated['is_default'] = $isDefault;
            $validated['is_active'] = $request->boolean('is_active', true);
            $validated['current_balance'] = $request->input('current_balance', $request->input('opening_balance', 0.00));
            $validated['account_type'] = $request->input('account_type', 'CURRENT');
            $validated['currency'] = $request->input('currency', 'LKR');

            $account = BankAccount::create($validated);

            DB::commit();

            return $this->successResponse(
                $account,
                'Agent bank account created successfully',
                201
            );
        } catch (ValidationException $ve) {
            DB::rollBack();
            return $this->errorResponse(
                'Validation failed',
                422,
                $ve->errors()
            );
        } catch (Throwable $th) {
            DB::rollBack();
            return $this->errorResponse(
                'Failed to create bank account',
                500,
                $th->getMessage()
            );
        }
    }

    /**
     * Display the specified bank account with details & summary.
     */
    public function show($id)
    {
        try {
            $account = BankAccount::with([
                'salePayments' => function ($q) {
                    $q->latest()->limit(10);
                },
                'dsrBankDeposits' => function ($q) {
                    $q->latest()->limit(10);
                }
            ])->find($id);

            if (!$account) {
                return $this->errorResponse(
                    'Bank account not found',
                    404
                );
            }

            $totalSalePayments = (float) $account->salePayments()->sum('amount');
            $totalDsrDeposits = (float) $account->dsrBankDeposits()->sum('amount');

            return $this->successResponse([
                'account' => $account,
                'metrics' => [
                    'total_sale_payments_received' => $totalSalePayments,
                    'total_dsr_deposits_received'  => $totalDsrDeposits,
                    'total_transactions_count'     => $account->salePayments()->count() + $account->dsrBankDeposits()->count(),
                ]
            ], 'Bank account retrieved successfully');
        } catch (Throwable $th) {
            return $this->errorResponse(
                'Failed to retrieve bank account',
                500,
                $th->getMessage()
            );
        }
    }

    /**
     * Update the specified bank account.
     */
    public function update(Request $request, $id)
    {
        try {
            $account = BankAccount::find($id);

            if (!$account) {
                return $this->errorResponse(
                    'Bank account not found',
                    404
                );
            }

            $validated = $request->validate([
                'bank_name'       => 'sometimes|required|string|max:255',
                'account_number'  => [
                    'sometimes',
                    'required',
                    'string',
                    'max:100',
                    Rule::unique('bank_accounts', 'account_number')->ignore($account->id),
                ],
                'account_name'    => 'sometimes|required|string|max:255',
                'branch_name'     => 'nullable|string|max:255',
                'account_type'    => 'nullable|string|in:CURRENT,SAVINGS,OTHER',
                'currency'        => 'nullable|string|max:10',
                'opening_balance' => 'nullable|numeric|min:0',
                'current_balance' => 'nullable|numeric|min:0',
                'is_default'      => 'nullable|boolean',
                'is_active'       => 'nullable|boolean',
                'notes'           => 'nullable|string',
            ]);

            DB::beginTransaction();

            if ($request->has('is_default') && $request->boolean('is_default')) {
                BankAccount::where('id', '!=', $account->id)->update(['is_default' => false]);
            }

            $account->update($validated);

            DB::commit();

            return $this->successResponse(
                $account,
                'Bank account updated successfully'
            );
        } catch (ValidationException $ve) {
            DB::rollBack();
            return $this->errorResponse(
                'Validation failed',
                422,
                $ve->errors()
            );
        } catch (Throwable $th) {
            DB::rollBack();
            return $this->errorResponse(
                'Failed to update bank account',
                500,
                $th->getMessage()
            );
        }
    }

    /**
     * Remove the specified bank account.
     */
    public function destroy($id)
    {
        try {
            $account = BankAccount::find($id);

            if (!$account) {
                return $this->errorResponse(
                    'Bank account not found',
                    404
                );
            }

            $paymentsCount = $account->salePayments()->count();
            $depositsCount = $account->dsrBankDeposits()->count();

            if ($paymentsCount > 0 || $depositsCount > 0) {
                return $this->errorResponse(
                    "Cannot delete bank account because it has {$paymentsCount} linked payment(s) and {$depositsCount} deposit record(s). Please deactivate the account instead.",
                    400
                );
            }

            $account->delete();

            return $this->successResponse(
                null,
                'Bank account deleted successfully'
            );
        } catch (Throwable $th) {
            return $this->errorResponse(
                'Failed to delete bank account',
                500,
                $th->getMessage()
            );
        }
    }

    /**
     * Toggle the active status of the bank account.
     */
    public function toggleStatus(Request $request, $id)
    {
        try {
            $account = BankAccount::find($id);

            if (!$account) {
                return $this->errorResponse(
                    'Bank account not found',
                    404
                );
            }

            $newStatus = $request->has('is_active')
                ? $request->boolean('is_active')
                : !$account->is_active;

            $account->update(['is_active' => $newStatus]);

            return $this->successResponse(
                $account,
                'Bank account status updated to ' . ($newStatus ? 'ACTIVE' : 'INACTIVE')
            );
        } catch (Throwable $th) {
            return $this->errorResponse(
                'Failed to toggle bank account status',
                500,
                $th->getMessage()
            );
        }
    }

    /**
     * Set a bank account as default.
     */
    public function setDefault($id)
    {
        try {
            $account = BankAccount::find($id);

            if (!$account) {
                return $this->errorResponse(
                    'Bank account not found',
                    404
                );
            }

            DB::beginTransaction();

            BankAccount::query()->update(['is_default' => false]);
            $account->update([
                'is_default' => true,
                'is_active'  => true, // Default account must be active
            ]);

            DB::commit();

            return $this->successResponse(
                $account,
                "{$account->bank_name} ({$account->account_number}) is now set as default"
            );
        } catch (Throwable $th) {
            DB::rollBack();
            return $this->errorResponse(
                'Failed to set default bank account',
                500,
                $th->getMessage()
            );
        }
    }

    /**
     * Summary overview of all agent bank accounts and balances.
     */
    public function getAccountSummary()
    {
        try {
            $accounts = BankAccount::withCount(['salePayments', 'dsrBankDeposits'])->get();

            $totalBalance = (float) $accounts->sum('current_balance');
            $activeCount = $accounts->where('is_active', true)->count();
            $defaultAccount = $accounts->firstWhere('is_default', true);

            $accountDetails = $accounts->map(function ($acc) {
                $salePaymentsSum = (float) $acc->salePayments()->sum('amount');
                $dsrDepositsSum = (float) $acc->dsrBankDeposits()->sum('amount');

                return [
                    'id'                     => $acc->id,
                    'bank_name'              => $acc->bank_name,
                    'account_number'         => $acc->account_number,
                    'account_name'           => $acc->account_name,
                    'branch_name'            => $acc->branch_name,
                    'account_type'           => $acc->account_type,
                    'currency'               => $acc->currency,
                    'current_balance'        => (float) $acc->current_balance,
                    'is_default'             => $acc->is_default,
                    'is_active'              => $acc->is_active,
                    'total_sales_received'   => $salePaymentsSum,
                    'total_dsr_deposits'     => $dsrDepositsSum,
                    'total_inflow'           => $salePaymentsSum + $dsrDepositsSum,
                ];
            });

            return $this->successResponse([
                'total_accounts_count' => $accounts->count(),
                'active_accounts_count'=> $activeCount,
                'total_balance'        => $totalBalance,
                'currency'             => 'LKR',
                'default_account'      => $defaultAccount,
                'accounts'             => $accountDetails,
            ], 'Agent bank accounts summary retrieved successfully');
        } catch (Throwable $th) {
            return $this->errorResponse(
                'Failed to retrieve bank accounts summary',
                500,
                $th->getMessage()
            );
        }
    }

    /**
     * Record a direct bank deposit into an agent bank account (from DSR trip, settlement, or manual).
     */
    public function recordDeposit(Request $request)
    {
        try {
            $validated = $request->validate([
                'bank_account_id'      => 'required|integer|exists:bank_accounts,id',
                'amount'               => 'required|numeric|min:0.01',
                'deposit_date'         => 'nullable|date',
                'slip_reference'       => 'nullable|string|max:255',
                'deposit_slip_image'   => 'nullable|string',
                'dsr_trip_id'          => 'nullable|integer|exists:dsr_trips,id',
                'daily_settlement_id'  => 'nullable|integer|exists:daily_settlements,id',
                'notes'                => 'nullable|string',
            ]);

            DB::beginTransaction();

            $account = BankAccount::findOrFail($validated['bank_account_id']);
            $user = $request->user();

            $deposit = DsrBankDeposit::create([
                'bank_account_id'     => $account->id,
                'rep_id'              => $user ? $user->id : null,
                'dsr_trip_id'         => $validated['dsr_trip_id'] ?? null,
                'daily_settlement_id' => $validated['daily_settlement_id'] ?? null,
                'deposit_date'        => $validated['deposit_date'] ?? now()->format('Y-m-d'),
                'amount'              => (float) $validated['amount'],
                'slip_reference'      => $validated['slip_reference'] ?? null,
                'deposit_slip_image'  => $validated['deposit_slip_image'] ?? null,
                'status'              => 'CONFIRMED',
                'notes'               => $validated['notes'] ?? null,
            ]);

            // Increment current balance of the account
            $account->increment('current_balance', (float) $validated['amount']);

            // If linked to DSR Trip, recalculate total_bank_deposits
            if (!empty($validated['dsr_trip_id'])) {
                $trip = DsrTrip::find($validated['dsr_trip_id']);
                if ($trip) {
                    $totalTripDeposits = DsrBankDeposit::where('dsr_trip_id', $trip->id)->sum('amount');
                    $trip->update(['total_bank_deposits' => $totalTripDeposits]);
                }
            }

            DB::commit();

            return $this->successResponse([
                'deposit'         => $deposit->load('bankAccount'),
                'updated_balance' => (float) $account->fresh()->current_balance,
            ], 'Deposit recorded successfully into ' . $account->bank_name, 201);
        } catch (ValidationException $ve) {
            DB::rollBack();
            return $this->errorResponse('Validation failed', 422, $ve->errors());
        } catch (Throwable $th) {
            DB::rollBack();
            return $this->errorResponse('Failed to record bank deposit', 500, $th->getMessage());
        }
    }
}
