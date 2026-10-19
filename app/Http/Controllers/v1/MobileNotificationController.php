<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Models\RepNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileNotificationController extends Controller
{
    private function authorizeRep(Request $request): void
    {
        abort_unless($request->user()->role === 'DSR_REP' && $request->user()->status === 'ACTIVE', 403);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeRep($request);
        $notifications = RepNotification::query()
            ->join('notification_broadcasts', 'notification_broadcasts.id', '=', 'rep_notifications.broadcast_id')
            ->where('rep_notifications.user_id', $request->user()->id)
            ->select('rep_notifications.id', 'rep_notifications.broadcast_id', 'rep_notifications.read_at',
                'rep_notifications.created_at', 'notification_broadcasts.title', 'notification_broadcasts.body')
            ->orderByDesc('rep_notifications.id')->paginate(20);

        return response()->json(['success' => true, 'data' => [
            'notifications' => $notifications,
            'unread_count' => $this->unreadCount($request),
        ]]);
    }

    public function markRead(Request $request, int $notification): JsonResponse
    {
        $this->authorizeRep($request);
        $item = RepNotification::where('user_id', $request->user()->id)->findOrFail($notification);
        // Updating only unread rows preserves the first read time on repeated taps.
        RepNotification::whereKey($item->id)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['success' => true, 'data' => [
            'id' => $item->id,
            'read_at' => $item->refresh()->read_at,
            'unread_count' => $this->unreadCount($request),
        ]]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $this->authorizeRep($request);
        RepNotification::where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['success' => true, 'data' => ['unread_count' => $this->unreadCount($request)]]);
    }

    private function unreadCount(Request $request): int
    {
        return RepNotification::where('user_id', $request->user()->id)->whereNull('read_at')->count();
    }
}
