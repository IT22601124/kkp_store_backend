<?php

namespace App\Jobs;

use App\Http\Traits\FirebaseNotification;
use App\Models\NotificationBroadcast;
use App\Models\NotificationDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class SendRepBroadcastNotification implements ShouldQueue
{
    use FirebaseNotification, Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public bool $failOnTimeout = true;

    public function __construct(public int $deliveryId) {}

    public function handle(): void
    {
        // Claim once: an interrupted delivery must not send again on queue redelivery.
        $claimed = NotificationDelivery::whereKey($this->deliveryId)
            ->where('status', 'queued')->update(['status' => 'processing']);
        if (! $claimed) {
            return;
        }

        $delivery = NotificationDelivery::findOrFail($this->deliveryId);
        $broadcast = NotificationBroadcast::findOrFail($delivery->broadcast_id);
        $broadcast->update(['status' => 'processing']);

        $result = $this->sendFirebaseNotification(
            $delivery->device_token,
            $broadcast->title,
            $broadcast->body,
            ['type' => 'announcement', 'broadcast_id' => (string) $broadcast->id],
        );

        // Legacy FCM can return HTTP 200 with a failed recipient.
        $sent = $result['success'] && (int) ($result['response']['failure'] ?? 0) === 0;
        $this->finish($sent);
    }

    public function failed(?Throwable $exception): void
    {
        $this->finish(false);
    }

    public function finish(bool $sent): void
    {
        DB::transaction(function () use ($sent) {
            $delivery = NotificationDelivery::whereKey($this->deliveryId)->lockForUpdate()->first();
            if (! $delivery || in_array($delivery->status, ['sent', 'failed'], true)) {
                return;
            }

            $delivery->update(['status' => $sent ? 'sent' : 'failed', 'device_token' => null]);
            $broadcast = NotificationBroadcast::whereKey($delivery->broadcast_id)->lockForUpdate()->firstOrFail();
            $broadcast->increment($sent ? 'sent_count' : 'failed_count');
            if ($broadcast->sent_count + $broadcast->failed_count >= $broadcast->targeted_devices) {
                $broadcast->update(['status' => $broadcast->failed_count === 0
                    ? 'completed' : ($broadcast->sent_count > 0 ? 'partial' : 'failed')]);
            }
        });
    }
}
