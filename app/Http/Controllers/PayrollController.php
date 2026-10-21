<?php

namespace App\Http\Controllers;

use App\Models\SalaryPayroll;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayrollController extends Controller
{
    public function settings(Request $request)
    {
        if ($request->isMethod('PUT')) {
            $data = $request->validate(['itemRules' => 'required|array', 'itemRules.*.itemId' => 'required|integer|distinct|exists:items,id',
                'itemRules.*.type' => 'required|in:PERCENTAGE,FIXED_PER_UNIT', 'itemRules.*.rateValue' => 'required|numeric|min:0',
                'targetBonusAmount' => 'required|numeric|min:0']);
            foreach ($data['itemRules'] as $rule) {
                if ($rule['type'] === 'PERCENTAGE' && $rule['rateValue'] > 100) {
                    throw ValidationException::withMessages(['itemRules' => 'Percentage rates cannot exceed 100.']);
                }
            }
            DB::table('system_settings')->updateOrInsert(['key' => 'payroll_commission'], ['value' => json_encode($data), 'updated_at' => now(), 'created_at' => now()]);
        }
        $stored = DB::table('system_settings')->where('key', 'payroll_commission')->value('value');
        return response()->json(['success' => true, 'data' => $stored ? json_decode($stored, true) : ['itemRules' => [], 'targetBonusAmount' => 0]]);
    }

    public function index()
    {
        return response()->json(['success' => true, 'data' => SalaryPayroll::latest('id')->get()]);
    }

    private function input(Request $request, ?SalaryPayroll $existing = null): array
    {
        $data = $request->validate([
            'referrer_id' => ($existing ? 'sometimes' : 'required').'|integer|exists:users,id',
            'pay_period' => ($existing ? 'sometimes' : 'required').'|date_format:Y-m',
            'basic_salary' => 'sometimes|numeric|min:0', 'bike_allowance' => 'sometimes|numeric|min:0',
            'fuel_allowance' => 'sometimes|numeric|min:0', 'earned_commission' => 'sometimes|numeric|min:0',
            'fuel_advances' => 'sometimes|numeric|min:0', 'epf_etf_deduction' => 'sometimes|numeric|min:0',
            'total_deductions' => 'sometimes|numeric|min:0', 'commission_breakdown' => 'nullable|array',
            'commission_breakdown.*' => 'numeric|min:0', 'payment_status' => 'sometimes|in:DRAFT,APPROVED,DISBURSED',
        ]);
        $values = array_merge($existing?->toArray() ?? [], $data);
        foreach (['basic_salary', 'bike_allowance', 'fuel_allowance', 'earned_commission', 'fuel_advances', 'epf_etf_deduction'] as $key) {
            $values[$key] = round((float) ($values[$key] ?? 0), 2);
        }
        if (array_key_exists('fuel_advances', $data) || array_key_exists('epf_etf_deduction', $data) || !$existing) {
            $values['total_deductions'] = $values['fuel_advances'] + $values['epf_etf_deduction'];
        }
        $values['net_payable'] = round($values['basic_salary'] + $values['bike_allowance'] + $values['fuel_allowance']
            + $values['earned_commission'] - (float) ($values['total_deductions'] ?? 0), 2);
        if ($values['net_payable'] < 0) {
            throw ValidationException::withMessages(['total_deductions' => 'Deductions exceed earnings.']);
        }
        if (User::findOrFail($values['referrer_id'])->role !== 'DSR_REP') {
            throw ValidationException::withMessages(['referrer_id' => 'Select a sales representative.']);
        }

        return array_intersect_key($values, array_flip((new SalaryPayroll)->getFillable()));
    }

    public function store(Request $request)
    {
        $data = $this->input($request);
        $data['payment_status'] = 'DRAFT';
        $payroll = DB::transaction(function () use ($data) {
            User::whereKey($data['referrer_id'])->lockForUpdate()->firstOrFail();
            if (SalaryPayroll::where('referrer_id', $data['referrer_id'])->where('pay_period', $data['pay_period'])->exists()) {
                throw ValidationException::withMessages(['pay_period' => 'A payroll already exists for this representative and period.']);
            }
            return SalaryPayroll::create($data);
        });

        return response()->json(['success' => true, 'data' => $payroll], 201);
    }

    public function show(int $id)
    {
        return response()->json(['success' => true, 'data' => SalaryPayroll::findOrFail($id)]);
    }

    public function update(Request $request, int $id)
    {
        $payroll = DB::transaction(function () use ($request, $id) {
            $payroll = SalaryPayroll::whereKey($id)->lockForUpdate()->firstOrFail();
            $data = $this->input($request, $payroll);
            if ((int) $data['referrer_id'] !== (int) $payroll->referrer_id || $data['pay_period'] !== $payroll->pay_period) {
                throw ValidationException::withMessages(['pay_period' => 'Create a separate draft for a different representative or pay period.']);
            }
            $status = $data['payment_status'] ?? $payroll->payment_status;
            if ($payroll->payment_status === 'DISBURSED') {
                if ($status !== 'DISBURSED' || count($request->except('payment_status')) > 0) {
                    throw ValidationException::withMessages(['payment_status' => 'Disbursed payrolls cannot be edited.']);
                }
                return $payroll;
            }
            if ($status !== $payroll->payment_status && !in_array($payroll->payment_status.':'.$status, ['DRAFT:APPROVED', 'APPROVED:DISBURSED'])) {
                throw ValidationException::withMessages(['payment_status' => 'Approve a draft before disbursing it.']);
            }
            if ($payroll->payment_status === 'APPROVED' && count($request->except('payment_status')) > 0) {
                throw ValidationException::withMessages(['payroll' => 'Approved payroll amounts cannot be edited.']);
            }
            if ($status === 'DISBURSED') {
                $data['payment_date'] = now()->toDateString();
            }
            $payroll->update($data);
            return $payroll;
        });

        return response()->json(['success' => true, 'data' => $payroll]);
    }

    public function destroy(int $id)
    {
        DB::transaction(function () use ($id) {
            $payroll = SalaryPayroll::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($payroll->payment_status !== 'DRAFT') {
                throw ValidationException::withMessages(['payroll' => 'Only draft payrolls can be deleted.']);
            }
            $payroll->delete();
        });

        return response()->json(['success' => true]);
    }
}
