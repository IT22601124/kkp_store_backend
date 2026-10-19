<?php

namespace Tests\Feature;

use App\Models\NotificationBroadcast;
use App\Models\RepNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function agent(): User
    {
        return User::factory()->create(['role' => 'AGENT', 'status' => 'ACTIVE']);
    }

    private function rep(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['role' => 'DSR_REP', 'status' => 'ACTIVE'], $attributes));
    }

    public function test_broadcast_is_saved_for_each_rep_even_without_push_tokens(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->agent());
        $first = $this->rep(['fcm_token' => 'shared-device']);
        $second = $this->rep(['fcm_token' => 'shared-device']);
        $noDevice = $this->rep();
        $inactive = $this->rep(['status' => 'INACTIVE']);
        $response = $this->postJson('/api/notification-broadcasts', ['title' => 'Meeting', 'body' => '9 AM tomorrow.'])
            ->assertAccepted()->assertJsonPath('data.targeted_devices', 1);
        $id = $response->json('data.id');
        $this->assertDatabaseCount('rep_notifications', 3);
        foreach ([$first, $second, $noDevice] as $rep) {
            $this->assertDatabaseHas('rep_notifications', ['broadcast_id' => $id, 'user_id' => $rep->id, 'read_at' => null]);
        }
        $this->assertDatabaseMissing('rep_notifications', ['user_id' => $inactive->id]);
    }

    public function test_announcement_can_be_saved_when_no_devices_are_registered(): void
    {
        Queue::fake();
        $rep = $this->rep();
        Sanctum::actingAs($this->agent());
        $this->postJson('/api/notification-broadcasts', ['title' => 'Inbox only', 'body' => 'Saved for later.'])
            ->assertAccepted()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.targeted_devices', 0);
        Queue::assertNothingPushed();
        Sanctum::actingAs($rep);
        $this->getJson('/api/v1/mobile/notifications')->assertOk()
            ->assertJsonPath('data.unread_count', 1)
            ->assertJsonPath('data.notifications.data.0.title', 'Inbox only');
    }

    public function test_push_failure_does_not_remove_the_saved_notification(): void
    {
        $rep = $this->rep(['fcm_token' => 'invalid-device']);
        Cache::put('firebase_fcm_access_token', 'test-token', 300);
        Http::fake(['fcm.googleapis.com/*' => Http::response(['error' => ['message' => 'Unregistered']], 404)]);
        Sanctum::actingAs($this->agent());
        $this->postJson('/api/notification-broadcasts', ['title' => 'Saved', 'body' => 'Still in the inbox.'])
            ->assertAccepted()->assertJsonPath('data.status', 'failed');
        Sanctum::actingAs($rep);
        $this->getJson('/api/v1/mobile/notifications')->assertOk()->assertJsonPath('data.notifications.total', 1);
    }

    public function test_inbox_is_private_paginated_and_ordered_newest_first(): void
    {
        $agent = $this->agent();
        $rep = $this->rep();
        $otherRep = $this->rep();
        for ($index = 1; $index <= 25; $index++) {
            $broadcast = NotificationBroadcast::create(['created_by' => $agent->id, 'title' => "Notice $index",
                'body' => 'Full message', 'total_reps' => 1, 'skipped_reps' => 1, 'targeted_devices' => 0]);
            RepNotification::create(['broadcast_id' => $broadcast->id, 'user_id' => $rep->id]);
        }
        Sanctum::actingAs($rep);
        $this->getJson('/api/v1/mobile/notifications')->assertOk()
            ->assertJsonCount(20, 'data.notifications.data')->assertJsonPath('data.notifications.data.0.title', 'Notice 25')
            ->assertJsonPath('data.notifications.data.0.body', 'Full message')->assertJsonPath('data.unread_count', 25);
        $this->getJson('/api/v1/mobile/notifications?page=2')->assertOk()->assertJsonCount(5, 'data.notifications.data');
        Sanctum::actingAs($otherRep);
        $this->getJson('/api/v1/mobile/notifications')->assertOk()->assertJsonPath('data.notifications.total', 0);
        $this->postJson('/api/v1/mobile/notifications/'.RepNotification::firstOrFail()->id.'/read')->assertNotFound();
    }

    public function test_read_status_is_persistent_idempotent_and_scoped_to_the_rep(): void
    {
        Queue::fake();
        $rep = $this->rep();
        $otherRep = $this->rep();
        Sanctum::actingAs($this->agent());
        $this->postJson('/api/notification-broadcasts', ['title' => 'Read me', 'body' => 'Message'])->assertAccepted();
        $item = RepNotification::where('user_id', $rep->id)->firstOrFail();
        Sanctum::actingAs($rep);
        $this->postJson("/api/v1/mobile/notifications/{$item->id}/read")->assertOk()->assertJsonPath('data.unread_count', 0);
        $firstReadTime = $item->refresh()->read_at->toISOString();
        $this->travel(5)->minutes();
        $this->postJson("/api/v1/mobile/notifications/{$item->id}/read")->assertOk()->assertJsonPath('data.read_at', $firstReadTime);
        $this->getJson('/api/v1/mobile/notifications')->assertOk()->assertJsonPath('data.notifications.data.0.read_at', $firstReadTime);
        Sanctum::actingAs($otherRep);
        $this->getJson('/api/v1/mobile/notifications')->assertOk()->assertJsonPath('data.unread_count', 1);
        $this->postJson('/api/v1/mobile/notifications/read-all')->assertOk()->assertJsonPath('data.unread_count', 0);
        $this->assertNotNull(RepNotification::where('user_id', $otherRep->id)->firstOrFail()->read_at);
    }

    public function test_inbox_requires_authenticated_active_rep(): void
    {
        $this->getJson('/api/v1/mobile/notifications')->assertUnauthorized();
        foreach ([$this->agent(), $this->rep(['status' => 'INACTIVE'])] as $user) {
            Sanctum::actingAs($user);
            $this->getJson('/api/v1/mobile/notifications')->assertForbidden();
            $this->postJson('/api/v1/mobile/notifications/read-all')->assertForbidden();
            $this->postJson('/api/v1/mobile/notifications/1/read')->assertForbidden();
        }
    }
}
