<?php

namespace App\Http\Controllers;

use App\Models\MonthlyTarget;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Http\Request;
use Carbon\Carbon;

class TargetController extends Controller
{
    private function formatTarget(MonthlyTarget $target): array
    {
        $rep = $target->referrer;

        // Auto-calculate achieved sales from sales table if achieved_amount is 0
        $salesAchieved = (float)$target->achieved_amount;
        $unitsAchieved = (float)$target->achieved_units;

        try {
            $date = Carbon::parse($target->target_month);
            $year = $date->year;
            $month = $date->month;

            $salesQuery = Sale::where('rep_id', $target->referrer_id)
                ->whereYear('sale_date', $year)
                ->whereMonth('sale_date', $month);

            $calculatedSales = (float)$salesQuery->sum('total_amount');
            if ($calculatedSales > 0 && $salesAchieved == 0) {
                $salesAchieved = $calculatedSales;
            }
        } catch (\Throwable $e) {
            // ignore date parsing issues
        }

        $targetAmt = (float)$target->target_amount;
        $pct = $targetAmt > 0 ? round(($salesAchieved / $targetAmt) * 100, 1) : 0;

        return [
            'id' => (int)$target->id,
            'referrer_id' => (int)$target->referrer_id,
            'referrerId' => (int)$target->referrer_id,
            'rep_name' => $rep?->name ?? 'DSR Representative',
            'rep_code' => $rep?->rep_code ?? $rep?->code ?? ($target->referrer_id ? "DSR-{$target->referrer_id}" : ''),
            'rep_phone' => $rep?->phone ?? '',
            'rep_email' => $rep?->email ?? '',
            'target_month' => $target->target_month,
            'targetMonth' => $target->target_month,
            'target_amount' => $targetAmt,
            'targetAmount' => $targetAmt,
            'achieved_amount' => $salesAchieved,
            'achievedAmount' => $salesAchieved,
            'target_units' => (float)$target->target_units,
            'targetUnits' => (float)$target->target_units,
            'achieved_units' => $unitsAchieved,
            'achievedUnits' => $unitsAchieved,
            'commission_rate_percent' => (float)$target->commission_rate_percent,
            'commissionRatePercent' => (float)$target->commission_rate_percent,
            'achievement_percentage' => $pct,
            'achievementPercentage' => $pct,
            'status' => $target->status ?: 'ACTIVE',
            'created_at' => $target->created_at,
            'updated_at' => $target->updated_at
        ];
    }

    public function index(Request $request)
    {
        $query = MonthlyTarget::with('referrer')->orderBy('target_month', 'desc')->orderBy('id', 'desc');

        if ($request->has('referrer_id')) {
            $query->where('referrer_id', $request->query('referrer_id'));
        }

        if ($request->has('target_month')) {
            $query->where('target_month', $request->query('target_month'));
        }

        $targets = $query->get();
        return response()->json(['success' => true, 'data' => $targets->map(fn($t) => $this->formatTarget($t))]);
    }

    public function store(Request $request)
    {
        $referrerId = $request->input('referrer_id') ?? $request->input('referrerId') ?? 1;
        $targetMonth = $request->input('target_month') ?? $request->input('targetMonth') ?? date('Y-m');
        $targetAmount = (float)($request->input('target_amount') ?? $request->input('targetAmount') ?? 50000);
        $achievedAmount = (float)($request->input('achieved_amount') ?? $request->input('achievedAmount') ?? 0);
        $targetUnits = (float)($request->input('target_units') ?? $request->input('targetUnits') ?? 0);
        $achievedUnits = (float)($request->input('achieved_units') ?? $request->input('achievedUnits') ?? 0);
        $commissionRate = (float)($request->input('commission_rate_percent') ?? $request->input('commissionRatePercent') ?? 2.5);
        $status = $request->input('status') ?? 'ACTIVE';

        $target = MonthlyTarget::updateOrCreate(
            [
                'referrer_id' => $referrerId,
                'target_month' => $targetMonth,
            ],
            [
                'target_amount' => $targetAmount,
                'achieved_amount' => $achievedAmount,
                'target_units' => $targetUnits,
                'achieved_units' => $achievedUnits,
                'commission_rate_percent' => $commissionRate,
                'status' => $status
            ]
        );

        $target->load('referrer');

        return response()->json(['success' => true, 'message' => 'Monthly target set successfully', 'data' => $this->formatTarget($target)], 201);
    }

    public function show($id)
    {
        $target = MonthlyTarget::with('referrer')->find($id);
        if (!$target) return response()->json(['success' => false, 'message' => 'Target not found'], 404);
        return response()->json(['success' => true, 'data' => $this->formatTarget($target)]);
    }

    public function update(Request $request, $id)
    {
        $target = MonthlyTarget::find($id);
        if (!$target) return response()->json(['success' => false, 'message' => 'Target not found'], 404);

        $payload = [];
        if ($request->has('referrer_id') || $request->has('referrerId')) {
            $payload['referrer_id'] = $request->input('referrer_id') ?? $request->input('referrerId');
        }
        if ($request->has('target_month') || $request->has('targetMonth')) {
            $payload['target_month'] = $request->input('target_month') ?? $request->input('targetMonth');
        }
        if ($request->has('target_amount') || $request->has('targetAmount')) {
            $payload['target_amount'] = (float)($request->input('target_amount') ?? $request->input('targetAmount'));
        }
        if ($request->has('achieved_amount') || $request->has('achievedAmount')) {
            $payload['achieved_amount'] = (float)($request->input('achieved_amount') ?? $request->input('achievedAmount'));
        }
        if ($request->has('target_units') || $request->has('targetUnits')) {
            $payload['target_units'] = (float)($request->input('target_units') ?? $request->input('targetUnits'));
        }
        if ($request->has('achieved_units') || $request->has('achievedUnits')) {
            $payload['achieved_units'] = (float)($request->input('achieved_units') ?? $request->input('achievedUnits'));
        }
        if ($request->has('commission_rate_percent') || $request->has('commissionRatePercent')) {
            $payload['commission_rate_percent'] = (float)($request->input('commission_rate_percent') ?? $request->input('commissionRatePercent'));
        }
        if ($request->has('status')) {
            $payload['status'] = $request->input('status');
        }

        $target->update($payload);
        $target->load('referrer');

        return response()->json(['success' => true, 'message' => 'Monthly target updated successfully', 'data' => $this->formatTarget($target)]);
    }

    public function destroy($id)
    {
        $target = MonthlyTarget::find($id);
        if ($target) $target->delete();
        return response()->json(['success' => true, 'message' => 'Target deleted successfully']);
    }
}
