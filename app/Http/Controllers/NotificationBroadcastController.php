<?php

namespace App\Http\Controllers;

use App\Jobs\SendRepBroadcastNotification;
use App\Models\NotificationBroadcast;
use App\Models\NotificationDelivery;
use App\Models\RepNotification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class NotificationBroadcastController extends Controller
{
    private function authorizeAgent(Request $request): void
    {
        abort_unless($request->user()->role === 'AGENT' && $request->user()->status === 'ACTIVE', 403,
            'Only active agents can send or view rep broadcasts.');
    }

    private function audience(): array
    {
        $reps = User::where('role', 'DSR_REP')->where('status', 'ACTIVE')->get(['id', 'fcm_token']);
        $tokens = $reps->pluck('fcm_token');
        $registered = $tokens->filter(fn ($token) => is_string($token) && trim($token) !== '');

        return [
            'total_reps' => $tokens->count(),
            'rep_ids' => $reps->modelKeys(),
            'skipped_reps' => $tokens->count() - $registered->count(),
            'tokens' => $registered->map(fn ($token) => trim($token))->unique()->values()->all(),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAgent($request);
        $audience = $this->audience();

        return response()->json(['success' => true, 'data' => [
            'audience' => [
                'total_reps' => $audience['total_reps'],
                'skipped_reps' => $audience['skipped_reps'],
                'targeted_devices' => count($audience['tokens']),
            ],
            'broadcasts' => NotificationBroadcast::where('created_by', $request->user()->id)
                ->latest('id')->paginate(20),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAgent($request);
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:100'],
            'body' => ['required', 'string', 'max:1000'],
        ]);
        $audience = $this->audience();
        if ($audience['total_reps'] === 0) {
            return response()->json(['success' => false,
                'message' => 'There are no active reps to receive this announcement.'], 422);
        }

        [$broadcast, $deliveryIds] = DB::transaction(function () use ($request, $validated, $audience) {
            $broadcast = NotificationBroadcast::create([
                'created_by' => $request->user()->id,
                'title' => $validated['title'],
                'body' => $validated['body'],
                'total_reps' => $audience['total_reps'],
                'skipped_reps' => $audience['skipped_reps'],
                'targeted_devices' => count($audience['tokens']),
                'status' => empty($audience['tokens']) ? 'completed' : 'queued',
            ]);
            $timestamp = now();
            foreach (array_chunk($audience['rep_ids'], 500) as $repIds) {
                RepNotification::insert(array_map(fn ($repId) => [
                    'broadcast_id' => $broadcast->id,
                    'user_id' => $repId,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ], $repIds));
            }
            $ids = [];
            foreach ($audience['tokens'] as $token) {
                $ids[] = NotificationDelivery::create([
                    'broadcast_id' => $broadcast->id, 'device_token' => $token,
                ])->id;
            }

            return [$broadcast, $ids];
        });

        foreach ($deliveryIds as $id) {
            try {
                Log::info('Dispatching SendRepBroadcastNotification job', ['delivery_id' => $id]);
                SendRepBroadcastNotification::dispatch($id);
                Log::info('Dispatched SendRepBroadcastNotification job', ['delivery_id' => $id]);
            } catch (Throwable $exception) {
                Log::error('Unable to dispatch rep notification', ['delivery_id' => $id, 'error' => $exception->getMessage()]);
                (new SendRepBroadcastNotification($id))->finish(false);
            }
        }

        return response()->json(['success' => true, 'message' => 'Announcement saved to rep inboxes. Check delivery history for push results.',
            'data' => $broadcast->refresh()], 202);
    }
}
