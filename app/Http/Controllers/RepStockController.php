<?php

namespace App\Http\Controllers;

use App\Http\Traits\ApiResponse;
use App\Models\AcceptedRequestItem;
use App\Models\Item;
use App\Models\RepStock;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\StockRequest;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class RepStockController extends Controller
{
    use ApiResponse;

    /**
     * Display a listing of sales rep stocks.
     */
    public function index(Request $request)
    {
        try {
            $query = RepStock::with(['rep', 'items.item']);

            if ($request->has('rep_id')) {
                $query->where('rep_id', $request->query('rep_id'));
            }

            if ($request->has('status')) {
                $query->where('status', $request->query('status'));
            }

            $repStocks = $query->orderBy('updated_at', 'desc')->get();

            return $this->successResponse(
                $repStocks,
                'Rep stock balances retrieved successfully',
                200
            );
        } catch (Throwable $th) {
            return $this->errorResponse(
                'Failed to retrieve rep stocks',
                500,
                $th->getMessage()
            );
        }
    }

    /**
     * Get stock balances for a specific rep.
     */
    public function getByRep($repId)
    {
        try {
            $repStocks = RepStock::where('rep_id', $repId)
                ->with(['rep', 'items.item'])
                ->orderBy('updated_at', 'desc')
                ->get();

            if ($repStocks->isEmpty()) {
                return $this->successResponse(
                    [],
                    "Stock balances for rep #{$repId} retrieved successfully",
                    200
                );
            }

            $allItems = $repStocks->pluck('items')->flatten();

            $consolidatedItems = $allItems->groupBy('item_id')->map(function ($group) {
                $firstItem = $group->first();
                $totalQty = (int) $group->sum('quantity');
                $item = $firstItem->item;
                $unitPrice = $item ? (float) $item->selling_price : 0;
                $totalValue = round($totalQty * $unitPrice, 2);

                $batchNumbers = $group->pluck('batch_number')->filter()->unique()->values()->all();

                return [
                    'id' => $firstItem->id,
                    'rep_stock_id' => $firstItem->rep_stock_id,
                    'item_id' => (int) $firstItem->item_id,
                    'quantity' => $totalQty,
                    'total_value' => $totalValue,
                    'batch_number' => !empty($batchNumbers) ? implode(', ', $batchNumbers) : null,
                    'batch_numbers' => $batchNumbers,
                    'item' => $item,
                    'created_at' => $firstItem->created_at,
                    'updated_at' => $firstItem->updated_at,
                ];
            })->values();

            $latestRepStock = $repStocks->first();

            $consolidatedRepStock = [
                'id' => $latestRepStock->id,
                'rep_id' => (int) $repId,
                'total_value' => (float) $consolidatedItems->sum('total_value'),
                'status' => $latestRepStock->status,
                'created_at' => $latestRepStock->created_at,
                'updated_at' => $latestRepStock->updated_at,
                'rep' => $latestRepStock->rep,
                'items' => $consolidatedItems,
            ];

            return $this->successResponse(
                [$consolidatedRepStock],
                "Stock balances for rep #{$repId} retrieved successfully",
                200
            );
        } catch (Throwable $th) {
            return $this->errorResponse(
                'Failed to retrieve rep stock details',
                500,
                $th->getMessage()
            );
        }
    }

    /**
     * Display details for a specific rep stock record.
     */
    public function show($id)
    {
        try {
            $repStock = RepStock::with(['rep', 'items.item'])->find($id);

            if (!$repStock) {
                return $this->errorResponse('Rep stock record not found', 404);
            }

            return $this->successResponse(
                $repStock,
                'Rep stock details retrieved successfully',
                200
            );
        } catch (Throwable $th) {
            return $this->errorResponse(
                'Failed to retrieve rep stock record',
                500,
                $th->getMessage()
            );
        }
    }

    /**
     * Issue stock for sales rep and fulfill stock request.
     */
    public function issueStockForRep(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'request_id' => 'nullable|integer',
                'from_branch_id' => 'nullable|integer',
                'to_referrer_id' => 'nullable|integer',
                'rep_id' => 'nullable|integer',
                'items' => 'required|array|min:1',
                'items.*.item_id' => 'required|integer|exists:items,id',
                'items.*.quantity' => 'required|integer|min:1',
                'items.*.batch_number' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return $this->errorResponse('Validation failed', 422, $validator->errors()->first());
            }

            $user = $request->user();
            $requestId = $request->input('request_id');
            $stockRequest = null;
            if ($requestId) {
                $stockRequest = StockRequest::find($requestId);
            }

            $rep_id = $stockRequest ? $stockRequest->rep_id : ($request->input('to_referrer_id') ?? $request->input('rep_id') ?? 1);
            $branch_id = $stockRequest ? ($stockRequest->branch_id ?? 1) : ($request->input('from_branch_id') ?? 1);

            if ($stockRequest) {
                try {
                    $stockRequest->status = 'APPROVED';
                    $stockRequest->save();
                } catch (Throwable $th) {
                    Log::info('err updating stock request status', [$th]);
                }
            } else {
                $requestCode = 'REQ-' . date('Ymd') . '-' . str_pad(StockRequest::count() + 1, 4, '0', STR_PAD_LEFT);
                $stockRequest = StockRequest::create([
                    'request_code' => $requestCode,
                    'branch_id' => $branch_id,
                    'rep_id' => $rep_id,
                    'request_date' => now(),
                    'status' => 'APPROVED',
                    'notes' => 'Direct Stock Issuance from Web Portal',
                ]);
            }
            $repStock = DB::transaction(function () use ($request, $stockRequest, $user, $rep_id, $branch_id) {
                // 1. Update stock_requests status to APPROVED
             


                // 2. Pre-calculate totals and line items
                $totalValue = 0;
                $lineDetails = [];

                foreach ($request->items as $itemData) {
                    $item = Item::findOrFail($itemData['item_id']);
                    $qty = (int) $itemData['quantity'];
                    $unitPrice = (float) $item->selling_price;
                    $subtotal = $qty * $unitPrice;
                    $totalValue += $subtotal;

                    $lineDetails[] = [
                        'item' => $item,
                        'qty' => $qty,
                        'unitPrice' => $unitPrice,
                        'subtotal' => $subtotal,
                        'batch_number' => $itemData['batch_number'] ?? null,
                    ];
                }

                // 3. Create Stock Transfer audit header
                $transferCode = 'TRF-' . str_pad(StockTransfer::count() + 1, 5, '0', STR_PAD_LEFT);
                $stockTransfer = StockTransfer::create([
                    'transfer_code' => $transferCode,
                    'from_branch_id' => $branch_id,
                    'to_referrer_id' => $rep_id,
                    'transfer_date' => now(),
                    'status' => 'ISSUED',
                    'total_value' => $totalValue,
                    'notes' => 'Stock issued for Request #' . $stockRequest->request_code,
                ]);

                // 4. Update Main Warehouse Stock & Movement Audit Logs
                foreach ($lineDetails as $line) {
                    $item = $line['item'];
                    $qty = $line['qty'];

                    $mainStock = Stock::firstOrCreate(
                        [
                            'branch_id' => $branch_id,
                            'referrer_id' => null,
                            'item_id' => $item->id,
                        ],
                        [
                            'quantity' => 0,
                            'unit_cost' => $item->purchase_price,
                            'unit_price' => $item->selling_price,
                            'total_value' => 0.00,
                        ]
                    );

                    $sourceQtyBefore = $mainStock->quantity;
                    $mainStock->quantity -= $qty;
                    $mainStock->total_value = $mainStock->quantity * $mainStock->unit_price;
                    $mainStock->save();

                    StockTransferItem::create([
                        'stock_transfer_id' => $stockTransfer->id,
                        'item_id' => $item->id,
                        'quantity' => $qty,
                        'unit_price' => $line['unitPrice'],
                        'subtotal' => $line['subtotal'],
                    ]);

                    StockMovement::create([
                        'stock_id' => $mainStock->id,
                        'item_id' => $item->id,
                        'branch_id' => $branch_id,
                        'referrer_id' => null,
                        'movement_type' => 'TRANSFER_OUT',
                        'quantity_before' => $sourceQtyBefore,
                        'quantity_change' => -$qty,
                        'quantity_after' => $mainStock->quantity,
                        'reference_type' => StockTransfer::class,
                        'reference_id' => $stockTransfer->id,
                        'performed_by' => $user->name ?? 'Branch Supervisor',
                        'notes' => "Stock issued to Rep #{$rep_id} for Request #{$stockRequest->request_code}",
                    ]);
                }

                // 5. Create RepStock and AcceptedRequestItem records
                $repStock = RepStock::create([
                    'rep_id' => $rep_id,
                    'total_value' => $totalValue,
                    'status' => 'APPROVED',
                ]);

                foreach ($lineDetails as $line) {
                    AcceptedRequestItem::create([
                        'rep_stock_id' => $repStock->id,
                        'item_id' => $line['item']->id,
                        'quantity' => $line['qty'],
                        'batch_number' => $line['batch_number'],
                    ]);
                }

                return $repStock->load(['rep', 'items.item']);
            });

            return $this->successResponse($repStock, 'Stock issued to rep successfully', 200);
        } catch (Throwable $th) {
            return $this->errorResponse('Failed to issue stock to rep: ' . $th->getMessage(), 500);
        }
    }
}
