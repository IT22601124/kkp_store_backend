<?php

namespace App\Http\Controllers;

use App\Models\SalaryPayroll;
use Illuminate\Http\Request;

class PayrollController extends Controller
{
    private function formatPayroll(SalaryPayroll $p): array
    {
        return [
            'id' => (int)$p->id,
            'referrer_id' => (int)$p->referrer_id,
            'referrerId' => (int)$p->referrer_id,
            'pay_period' => $p->pay_period,
            'payPeriod' => $p->pay_period,
            'basic_salary' => (float)$p->basic_salary,
            'basicSalary' => (float)$p->basic_salary,
            'bike_allowance' => (float)$p->bike_allowance,
            'bikeAllowance' => (float)$p->bike_allowance,
            'fuel_allowance' => (float)$p->fuel_allowance,
            'fuelAllowance' => (float)$p->fuel_allowance,
            'earned_commission' => (float)$p->earned_commission,
            'earnedCommission' => (float)$p->earned_commission,
            'total_deductions' => (float)$p->total_deductions,
            'totalDeductions' => (float)$p->total_deductions,
            'net_payable' => (float)$p->net_payable,
            'netPayable' => (float)$p->net_payable,
            'payment_status' => $p->payment_status,
            'paymentStatus' => $p->payment_status,
            'created_at' => $p->created_at,
            'updated_at' => $p->updated_at
        ];
    }

    public function index()
    {
        $list = SalaryPayroll::orderBy('id', 'desc')->get();
        return response()->json(['success' => true, 'data' => $list->map(fn($p) => $this->formatPayroll($p))]);
    }

    public function store(Request $request)
    {
        $basic = $request->input('basic_salary') ?? $request->input('basicSalary') ?? 45000;
        $bike = $request->input('bike_allowance') ?? $request->input('bikeAllowance') ?? 15000;
        $fuel = $request->input('fuel_allowance') ?? $request->input('fuelAllowance') ?? 10000;
        $commission = $request->input('earned_commission') ?? $request->input('earnedCommission') ?? 0;
        $deductions = $request->input('total_deductions') ?? $request->input('totalDeductions') ?? 0;
        $net = ($basic + $bike + $fuel + $commission) - $deductions;

        $p = SalaryPayroll::create([
            'referrer_id' => $request->input('referrer_id') ?? $request->input('referrerId') ?? 1,
            'pay_period' => $request->input('pay_period') ?? $request->input('payPeriod') ?? date('Y-m'),
            'basic_salary' => $basic,
            'bike_allowance' => $bike,
            'fuel_allowance' => $fuel,
            'earned_commission' => $commission,
            'total_deductions' => $deductions,
            'net_payable' => $net,
            'payment_status' => $request->input('payment_status') ?? 'DRAFT'
        ]);

        return response()->json(['success' => true, 'message' => 'Payroll created', 'data' => $this->formatPayroll($p)], 201);
    }

    public function show($id)
    {
        $p = SalaryPayroll::find($id);
        if (!$p) return response()->json(['success' => false, 'message' => 'Payroll not found'], 404);
        return response()->json(['success' => true, 'data' => $this->formatPayroll($p)]);
    }

    public function update(Request $request, $id)
    {
        $p = SalaryPayroll::find($id);
        if (!$p) return response()->json(['success' => false, 'message' => 'Payroll not found'], 404);
        $p->update($request->all());
        return response()->json(['success' => true, 'message' => 'Payroll updated', 'data' => $this->formatPayroll($p)]);
    }

    public function destroy($id)
    {
        $p = SalaryPayroll::find($id);
        if ($p) $p->delete();
        return response()->json(['success' => true, 'message' => 'Payroll deleted']);
    }
}
