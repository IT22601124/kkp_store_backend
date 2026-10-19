<?php

namespace Tests\Feature;

use App\Jobs\SendRepBroadcastNotification;
use App\Models\NotificationBroadcast;
use App\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationBroadcastTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create(['role' => 'AGENT', 'status' => 'ACTIVE']));
        Cache::put('firebase_fcm_access_token', 'fake-oauth-token', 300);
        config(['services.firebase.project_id' => 'test-project']);
        Http::preventStrayRequests();
    }

    public function test_only_active_agents_can_access_broadcasts(): void
    {
        foreach ([['role' => 'DSR_REP'], ['role' => 'AGENT', 'status' => 'INACTIVE']] as $attributes) {
            Sanctum::actingAs(User::factory()->create($attributes));
            $this->getJson('/api/notification-broadcasts')->assertForbidden();
            $this->postJson('/api/notification-broadcasts', ['title' => 'Hi', 'body' => 'Hello'])->assertForbidden();
        }
    }

    public function test_broadcast_snapshots_active_reps_and_deduplicates_devices(): void
    {
        Queue::fake();
        User::factory()->count(2)->create(['fcm_token' => 'device-1']);
        User::factory()->create(['fcm_token' => ' device-2 ']);
        User::factory()->create(['fcm_token' => null]);
        User::factory()->create(['fcm_token' => '   ']);
        User::factory()->create(['status' => 'INACTIVE', 'fcm_token' => 'inactive-device']);
        User::factory()->create(['role' => 'AGENT', 'fcm_token' => 'agent-device']);

        $this->getJson('/api/notification-broadcasts')->assertOk()->assertJsonPath('data.audience', [
            'total_reps' => 5, 'skipped_reps' => 2, 'targeted_devices' => 2,
        ]);
        $this->postJson('/api/notification-broadcasts', ['title' => 'Meeting', 'body' => 'Meet at 9 AM.'])
            ->assertStatus(202)->assertJsonPath('data.targeted_devices', 2)->assertJsonPath('data.status', 'queued');
        Queue::assertPushed(SendRepBroadcastNotification::class, 2);
        $this->assertDatabaseCount('notification_deliveries', 2);
        Http::assertNothingSent();
    }

    public function test_invalid_messages_and_empty_audience_do_not_queue_notifications(): void
    {
        Queue::fake();
        $this->postJson('/api/notification-broadcasts', ['title' => ' ', 'body' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors(['title', 'body']);
        $this->postJson('/api/notification-broadcasts', ['title' => str_repeat('x', 101), 'body' => str_repeat('x', 1001)])
            ->assertUnprocessable()->assertJsonValidationErrors(['title', 'body']);
        $this->postJson('/api/notification-broadcasts', ['title' => 'Title', 'body' => 'Message'])
            ->assertUnprocessable();
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('notification_broadcasts', 0);
    }

    public function test_jobs_record_partial_results_without_resending_completed_deliveries(): void
    {
        Queue::fake();
        User::factory()->create(['fcm_token' => 'good-device']);
        User::factory()->create(['fcm_token' => 'bad-device']);
        Http::fake(['fcm.googleapis.com/*' => Http::sequence()
            ->push(['name' => 'projects/test/messages/1'])
            ->push(['error' => ['message' => 'Unregistered']], 404)]);
        $this->postJson('/api/notification-broadcasts', ['title' => 'Title', 'body' => 'Message'])->assertStatus(202);
        foreach (NotificationDelivery::all() as $delivery) {
            $job = new SendRepBroadcastNotification($delivery->id);
            $job->handle();
            $job->handle();
            $job->failed(new \RuntimeException('duplicate failure callback'));
        }
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request['message']['data']['type'] === 'announcement'
            && $request['message']['notification']['title'] === 'Title');
        $this->assertDatabaseHas('notification_broadcasts', ['status' => 'partial', 'sent_count' => 1, 'failed_count' => 1]);
        $this->assertSame(0, NotificationDelivery::whereNotNull('device_token')->count());
        $history = $this->getJson('/api/notification-broadcasts')->assertOk();
        $this->assertStringNotContainsString('good-device', $history->getContent());
    }

    public function test_successful_broadcast_completes_using_sync_queue(): void
    {
        User::factory()->create(['fcm_token' => 'device']);
        Http::fake(['fcm.googleapis.com/*' => Http::response(['name' => 'projects/test/messages/1'])]);
        $this->postJson('/api/notification-broadcasts', ['title' => 'Title', 'body' => 'Message'])
            ->assertStatus(202)->assertJsonPath('data.status', 'completed')->assertJsonPath('data.sent_count', 1);
    }

    public function test_job_failure_is_counted_once(): void
    {
        Queue::fake();
        User::factory()->create(['fcm_token' => 'device']);
        $this->postJson('/api/notification-broadcasts', ['title' => 'Title', 'body' => 'Message'])->assertStatus(202);
        $job = new SendRepBroadcastNotification(NotificationDelivery::firstOrFail()->id);
        $job->failed(new \RuntimeException('Worker timed out'));
        $job->failed(new \RuntimeException('Worker timed out'));
        $this->assertDatabaseHas('notification_broadcasts', ['status' => 'failed', 'sent_count' => 0, 'failed_count' => 1]);
    }

    public function test_agents_only_see_their_own_broadcast_history(): void
    {
        NotificationBroadcast::create(['created_by' => User::factory()->create(['role' => 'AGENT'])->id,
            'title' => 'Private history', 'body' => 'Message', 'total_reps' => 1, 'skipped_reps' => 0, 'targeted_devices' => 1]);
        $this->getJson('/api/notification-broadcasts')->assertOk()->assertJsonPath('data.broadcasts.total', 0);
    }
}
