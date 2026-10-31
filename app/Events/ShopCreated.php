<?php

namespace App\Events;

use App\Models\Shop;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ShopCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Shop $shop)
    {
        $this->shop->loadMissing('route');
    }

    public function broadcastOn(): array
    {
        return [new Channel('shops')];
    }

    public function broadcastAs(): string
    {
        return 'shop.created';
    }

    public function broadcastWith(): array
    {
        return [
            'shop' => $this->shop->toArray(),
            'id' => (int) $this->shop->id,
            'shop_id' => (int) $this->shop->id,
            'route_id' => (int) $this->shop->route_id,
            'shop_code' => $this->shop->shop_code,
            'shop_name' => $this->shop->shop_name,
            'message' => "New shop {$this->shop->shop_name} was created.",
        ];
    }
}
