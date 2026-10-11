<?php

namespace App\Events;

use App\Models\StockRequest;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StockRequestCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public StockRequest $stockRequest;

    /**
     * Create a new event instance.
     */
    public function __construct(StockRequest $stockRequest)
    {
        $this->stockRequest = $stockRequest->loadMissing(['rep.dsrProfile', 'branch', 'items.item']);
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('stock-requests'),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'stock.requested';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $rep = $this->stockRequest->rep;
        $branch = $this->stockRequest->branch;
        $items = $this->stockRequest->items;

        $itemsSummary = $items ? $items->map(function ($line) {
            return [
                'id' => $line->id,
                'item_id' => $line->item_id,
                'item_name' => $line->item->name ?? "Item #{$line->item_id}",
                'item_code' => $line->item->code ?? '',
                'quantity' => (int) $line->requested_quantity,
                'unit_price' => (float) ($line->item->selling_price ?? 0),
            ];
        })->toArray() : [];

        $totalValue = array_reduce($itemsSummary, function ($sum, $item) {
            return $sum + ($item['quantity'] * $item['unit_price']);
        }, 0);

        return [
            'stock_request' => $this->stockRequest->toArray(),
            'id' => $this->stockRequest->id,
            'request_code' => $this->stockRequest->request_code,
            'rep_id' => $this->stockRequest->rep_id,
            'rep_name' => $rep->name ?? 'Sales Rep',
            'rep_code' => $rep->dsrProfile->rep_code ?? "REP-{$this->stockRequest->rep_id}",
            'branch_id' => $this->stockRequest->branch_id,
            'branch_name' => $branch->name ?? 'Main Warehouse',
            'status' => $this->stockRequest->status,
            'total_value' => $totalValue,
            'items_count' => count($itemsSummary),
            'items' => $itemsSummary,
            'created_at' => $this->stockRequest->created_at?->toIso8601String() ?? now()->toIso8601String(),
            'message' => "New stock request ({$this->stockRequest->request_code}) from " . ($rep->name ?? 'Sales Rep'),
        ];
    }
}
