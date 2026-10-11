<?php

namespace Tests\Feature;

use App\Http\Traits\FirebaseNotification;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FirebaseNotificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.firebase.credentials' => '/path/does/not/exist.json']);
    }

    /**
     * A helper class that consumes the trait.
     */
    protected function getConsumer(): object
    {
        return new class {
            use FirebaseNotification;

            public function testGetProjectId(): string
            {
                return $this->getFirebaseProjectId();
            }

            public function testGetServerKey(): ?string
            {
                return $this->getFirebaseServerKey();
            }

            public function testSendViaFcmHttpV1(string $accessToken, array $params): array
            {
                return $this->sendViaFcmHttpV1($accessToken, $params);
            }
        };
    }

    public function test_trait_can_detect_project_id_from_google_services_json(): void
    {
        $consumer = $this->getConsumer();
        $projectId = $consumer->testGetProjectId();

        $this->assertEquals('sundarambrother-934fe', $projectId);
    }

    public function test_rejects_empty_device_token(): void
    {
        $consumer = $this->getConsumer();
        $result = $consumer->sendFirebaseNotification('', 'Test Title', 'Test Body');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('cannot be empty', $result['message']);
    }

    public function test_rejects_empty_topic(): void
    {
        $consumer = $this->getConsumer();
        $result = $consumer->sendFirebaseTopicNotification('', 'Test Title', 'Test Body');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('cannot be empty', $result['message']);
    }

    public function test_handles_unconfigured_credentials_gracefully(): void
    {
        config(['services.firebase.credentials' => '/path/does/not/exist.json']);
        config(['services.firebase.server_key' => null]);

        $consumer = $this->getConsumer();
        $result = $consumer->sendFirebaseNotification('dummy-fcm-token-12345', 'Hello', 'World');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('credentials not configured', $result['message']);
    }

    public function test_can_send_fcm_http_v1_notification_with_access_token(): void
    {
        Http::fake([
            'https://fcm.googleapis.com/v1/projects/sundarambrother-934fe/messages:send' => Http::response([
                'name' => 'projects/sundarambrother-934fe/messages/0:1612345678901234',
            ], 200),
        ]);

        $consumer = $this->getConsumer();
        $result = $consumer->testSendViaFcmHttpV1('mock-oauth2-token-xyz', [
            'target_type' => 'token',
            'target'      => 'device-token-12345',
            'title'       => 'New Stock Request Approved',
            'body'        => 'Warehouse approved your stock requisition of 50 SIMs.',
            'data'        => [
                'request_id' => 45,
                'status'     => 'APPROVED',
            ],
            'image'       => 'https://example.com/logo.png',
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('Notification sent successfully.', $result['message']);

        Http::assertSent(function ($request) {
            $data = $request->data();
            return $request->url() === 'https://fcm.googleapis.com/v1/projects/sundarambrother-934fe/messages:send'
                && $request->header('Authorization')[0] === 'Bearer mock-oauth2-token-xyz'
                && $data['message']['token'] === 'device-token-12345'
                && $data['message']['notification']['title'] === 'New Stock Request Approved'
                && $data['message']['notification']['image'] === 'https://example.com/logo.png'
                && $data['message']['data']['request_id'] === '45'
                && $data['message']['data']['status'] === 'APPROVED'
                && $data['message']['android']['priority'] === 'HIGH';
        });
    }

    public function test_can_send_legacy_fcm_notification_when_server_key_is_provided(): void
    {
        config(['services.firebase.server_key' => 'test-server-key-abcdef']);

        Http::fake([
            'https://fcm.googleapis.com/fcm/send' => Http::response([
                'multicast_id'  => 123456789,
                'success'       => 1,
                'failure'       => 0,
                'canonical_ids' => 0,
                'results'       => [['message_id' => '0:123456789abcdef']],
            ], 200),
        ]);

        $consumer = $this->getConsumer();
        $result = $consumer->sendFirebaseNotification(
            'valid-device-token-abc',
            'Order Confirmed',
            'Your order has been approved.',
            ['order_id' => '1001', 'type' => 'ORDER_APPROVED']
        );

        $this->assertTrue($result['success']);
        $this->assertEquals('Notification sent successfully via Legacy FCM.', $result['message']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://fcm.googleapis.com/fcm/send'
                && $request->header('Authorization')[0] === 'key=test-server-key-abcdef'
                && $request['notification']['title'] === 'Order Confirmed'
                && $request['to'] === 'valid-device-token-abc';
        });
    }

    public function test_can_send_legacy_multicast(): void
    {
        config(['services.firebase.server_key' => 'test-server-key-abcdef']);

        Http::fake([
            'https://fcm.googleapis.com/fcm/send' => Http::response([
                'multicast_id' => 99999,
                'success'      => 2,
                'failure'      => 0,
            ], 200),
        ]);

        $consumer = $this->getConsumer();
        $result = $consumer->sendFirebaseNotificationToMultiple(
            ['token-1', 'token-2'],
            'Stock Alert',
            'Low stock on SIM cards'
        );

        $this->assertTrue($result['success']);
        $this->assertEquals(2, $result['sent_count']);
        $this->assertEquals(0, $result['failed_count']);
    }

    public function test_can_send_topic_notification(): void
    {
        config(['services.firebase.server_key' => 'test-server-key-abcdef']);

        Http::fake([
            'https://fcm.googleapis.com/fcm/send' => Http::response([
                'message_id' => 98765,
            ], 200),
        ]);

        $consumer = $this->getConsumer();
        $result = $consumer->sendFirebaseTopicNotification(
            'all_reps',
            'Daily Briefing',
            'Morning route plans ready'
        );

        $this->assertTrue($result['success']);

        Http::assertSent(function ($request) {
            return $request['to'] === '/topics/all_reps';
        });
    }
}
