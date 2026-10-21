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
                ->where('status', 'COMPLETED')->whereYear('sale_date', $year)
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

    private function input(Request $request, bool $partial = false): array
    {
        foreach (['referrerId'=>'referrer_id', 'targetMonth'=>'target_month', 'targetAmount'=>'target_amount',
            'achievedAmount'=>'achieved_amount', 'targetUnits'=>'target_units', 'achievedUnits'=>'achieved_units',
            'commissionRatePercent'=>'commission_rate_percent'] as $alias=>$key) {
            if (!$request->has($key) && $request->has($alias)) $request->merge([$key=>$request->input($alias)]);
        }
        $presence = $partial ? 'sometimes' : 'required';
        $data = $request->validate(['referrer_id'=>$presence.'|integer|exists:users,id',
            'target_month'=>$presence.'|date_format:Y-m', 'target_amount'=>$presence.'|numeric|min:0',
            'achieved_amount'=>'sometimes|numeric|min:0', 'target_units'=>'sometimes|integer|min:0',
            'achieved_units'=>'sometimes|integer|min:0', 'commission_rate_percent'=>'sometimes|numeric|between:0,100',
            'status'=>'sometimes|in:ACTIVE,ACHIEVED,INACTIVE']);
        if (isset($data['referrer_id']) && User::findOrFail($data['referrer_id'])->role !== 'DSR_REP') {
            throw \Illuminate\Validation\ValidationException::withMessages(['referrer_id'=>'Select a sales representative.']);
        }
        return $data;
    }

    public function store(Request $request)
    {
        $data = $this->input($request);
        $target = MonthlyTarget::updateOrCreate(['referrer_id'=>$data['referrer_id'], 'target_month'=>$data['target_month']], $data);
        return response()->json(['success'=>true, 'data'=>$this->formatTarget($target->load('referrer'))], 201);
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

        $payload = $this->input($request, true);
        if (isset($payload['referrer_id']) || isset($payload['target_month'])) {
            $duplicate = MonthlyTarget::where('referrer_id', $payload['referrer_id'] ?? $target->referrer_id)
                ->where('target_month', $payload['target_month'] ?? $target->target_month)->where('id', '!=', $target->id)->exists();
            if ($duplicate) throw \Illuminate\Validation\ValidationException::withMessages(['target_month'=>'A target already exists for this representative and month.']);
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
