<?php

namespace App\Http\Controllers;

use App\Models\MonthlyTarget;
use Illuminate\Http\Request;

class TargetController extends Controller
{
    private function formatTarget(MonthlyTarget $target): array
    {
        return [
            'id' => (int)$target->id,
            'referrer_id' => (int)$target->referrer_id,
            'referrerId' => (int)$target->referrer_id,
            'target_month' => $target->target_month,
            'targetMonth' => $target->target_month,
            'target_amount' => (float)$target->target_amount,
            'targetAmount' => (float)$target->target_amount,
            'achieved_amount' => (float)$target->achieved_amount,
            'achievedAmount' => (float)$target->achieved_amount,
            'target_units' => (float)$target->target_units,
            'targetUnits' => (float)$target->target_units,
            'achieved_units' => (float)$target->achieved_units,
            'achievedUnits' => (float)$target->achieved_units,
            'commission_rate_percent' => (float)$target->commission_rate_percent,
            'commissionRatePercent' => (float)$target->commission_rate_percent,
            'status' => $target->status,
            'created_at' => $target->created_at,
            'updated_at' => $target->updated_at
        ];
    }

    public function index()
    {
        $targets = MonthlyTarget::orderBy('id', 'desc')->get();
        return response()->json(['success' => true, 'data' => $targets->map(fn($t) => $this->formatTarget($t))]);
    }

    public function store(Request $request)
    {
        $target = MonthlyTarget::create([
            'referrer_id' => $request->input('referrer_id') ?? $request->input('referrerId') ?? 1,
            'target_month' => $request->input('target_month') ?? $request->input('targetMonth') ?? date('Y-m'),
            'target_amount' => $request->input('target_amount') ?? $request->input('targetAmount') ?? 500000,
            'achieved_amount' => $request->input('achieved_amount') ?? $request->input('achievedAmount') ?? 0,
            'target_units' => $request->input('target_units') ?? $request->input('targetUnits') ?? 1000,
            'achieved_units' => $request->input('achieved_units') ?? $request->input('achievedUnits') ?? 0,
            'commission_rate_percent' => $request->input('commission_rate_percent') ?? $request->input('commissionRatePercent') ?? 2.5,
            'status' => $request->input('status') ?? 'ACTIVE'
        ]);

        return response()->json(['success' => true, 'message' => 'Monthly target set', 'data' => $this->formatTarget($target)], 201);
    }

    public function show($id)
    {
        $target = MonthlyTarget::find($id);
        if (!$target) return response()->json(['success' => false, 'message' => 'Target not found'], 404);
        return response()->json(['success' => true, 'data' => $this->formatTarget($target)]);
    }

    public function update(Request $request, $id)
    {
        $target = MonthlyTarget::find($id);
        if (!$target) return response()->json(['success' => false, 'message' => 'Target not found'], 404);
        $target->update($request->all());
        return response()->json(['success' => true, 'message' => 'Target updated', 'data' => $this->formatTarget($target)]);
    }

    public function destroy($id)
    {
        $target = MonthlyTarget::find($id);
        if ($target) $target->delete();
        return response()->json(['success' => true, 'message' => 'Target deleted']);
    }
}
