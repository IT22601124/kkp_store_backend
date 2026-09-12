<?php

namespace App\Http\Controllers;

use App\Models\DsrTrip;
use Illuminate\Http\Request;

class DsrTripController extends Controller
{
    private function formatTrip(DsrTrip $trip): array
    {
        return [
            'id' => (int)$trip->id,
            'referrer_id' => (int)$trip->referrer_id,
            'referrerId' => (int)$trip->referrer_id,
            'route_id' => (int)$trip->route_id,
            'routeId' => (int)$trip->route_id,
            'trip_date' => $trip->trip_date,
            'tripDate' => $trip->trip_date,
            'starting_inventory_value' => (float)$trip->starting_inventory_value,
            'issued_stock_value' => (float)$trip->issued_stock_value,
            'total_sales_value' => (float)$trip->total_sales_value,
            'total_cash_collected' => (float)$trip->total_cash_collected,
            'total_credit_sales' => (float)$trip->total_credit_sales,
            'total_cheques_collected' => (float)$trip->total_cheques_collected,
            'total_bank_deposits' => (float)$trip->total_bank_deposits,
            'closing_inventory_value' => (float)$trip->closing_inventory_value,
            'variance_amount' => (float)$trip->variance_amount,
            'status' => $trip->status,
            'notes' => $trip->notes ?? '',
            'created_at' => $trip->created_at,
            'updated_at' => $trip->updated_at
        ];
    }

    public function index()
    {
        $trips = DsrTrip::orderBy('id', 'desc')->get();
        return response()->json([
            'success' => true,
            'data' => $trips->map(fn($t) => $this->formatTrip($t))
        ]);
    }

    public function store(Request $request)
    {
        $trip = DsrTrip::create([
            'referrer_id' => $request->input('referrer_id') ?? $request->input('referrerId') ?? 1,
            'route_id' => $request->input('route_id') ?? $request->input('routeId') ?? 1,
            'trip_date' => $request->input('trip_date') ?? $request->input('tripDate') ?? date('Y-m-d'),
            'starting_inventory_value' => $request->input('starting_inventory_value') ?? $request->input('startingInventoryValue') ?? 0,
            'issued_stock_value' => $request->input('issued_stock_value') ?? $request->input('issuedStockValue') ?? 0,
            'total_sales_value' => $request->input('total_sales_value') ?? $request->input('totalSalesValue') ?? 0,
            'total_cash_collected' => $request->input('total_cash_collected') ?? $request->input('totalCashCollected') ?? 0,
            'total_credit_sales' => $request->input('total_credit_sales') ?? $request->input('totalCreditSales') ?? 0,
            'total_cheques_collected' => $request->input('total_cheques_collected') ?? $request->input('totalChequesCollected') ?? 0,
            'total_bank_deposits' => $request->input('total_bank_deposits') ?? $request->input('totalBankDeposits') ?? 0,
            'closing_inventory_value' => $request->input('closing_inventory_value') ?? $request->input('closingInventoryValue') ?? 0,
            'variance_amount' => $request->input('variance_amount') ?? $request->input('varianceAmount') ?? 0,
            'status' => $request->input('status') ?? 'SUBMITTED',
            'notes' => $request->input('notes') ?? ''
        ]);

        return response()->json([
            'success' => true,
            'message' => 'DSR trip sheet submitted successfully',
            'data' => $this->formatTrip($trip)
        ], 201);
    }

    public function show($id)
    {
        $trip = DsrTrip::find($id);
        if (!$trip) {
            return response()->json(['success' => false, 'message' => 'Trip sheet not found'], 404);
        }
        return response()->json(['success' => true, 'data' => $this->formatTrip($trip)]);
    }

    public function update(Request $request, $id)
    {
        $trip = DsrTrip::find($id);
        if (!$trip) {
            return response()->json(['success' => false, 'message' => 'Trip sheet not found'], 404);
        }

        $trip->update($request->all());
        return response()->json(['success' => true, 'message' => 'Trip sheet updated', 'data' => $this->formatTrip($trip)]);
    }

    public function destroy($id)
    {
        $trip = DsrTrip::find($id);
        if ($trip) $trip->delete();
        return response()->json(['success' => true, 'message' => 'Trip sheet deleted']);
    }
}
