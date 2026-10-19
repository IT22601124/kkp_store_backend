<?php

namespace App\Http\Traits;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

trait FirebaseNotification
{
    /**
     * Send a Firebase push notification to a single device token.
     *
     * @param string $deviceToken The FCM registration token of the target device.
     * @param string $title       Notification title.
     * @param string $body        Notification message body.
     * @param array  $data        Custom key-value data payload (all values converted to string).
     * @param string|null $image  Optional URL of an image to display in the notification.
     * @return array Result array ['success' => bool, 'message' => string, 'response' => mixed]
     */
    public function sendFirebaseNotification(
        string $deviceToken,
        string $title,
        string $body,
        array $data = [],
        ?string $image = null
    ): array {
        if (empty(trim($deviceToken))) {
            return [
                'success' => false,
                'message' => 'Device token cannot be empty.',
                'response' => null,
            ];
        }

        return $this->dispatchFirebaseMessage([
            'target_type' => 'token',
            'target'      => trim($deviceToken),
            'title'       => $title,
            'body'        => $body,
            'data'        => $data,
            'image'       => $image,
        ]);
    }

    /**
     * Send a Firebase push notification to multiple device tokens (multicast).
     *
     * @param array  $deviceTokens Array of device tokens.
     * @param string $title        Notification title.
     * @param string $body         Notification body.
     * @param array  $data         Custom key-value data payload.
     * @param string|null $image   Optional image URL.
     * @return array Summary array ['success' => bool, 'sent_count' => int, 'failed_count' => int, 'results' => array]
     */
    public function sendFirebaseNotificationToMultiple(
        array $deviceTokens,
        string $title,
        string $body,
        array $data = [],
        ?string $image = null
    ): array {
        $validTokens = array_values(array_unique(array_filter(array_map('trim', $deviceTokens))));

        if (empty($validTokens)) {
            return [
                'success'      => false,
                'message'      => 'No valid device tokens provided.',
                'sent_count'   => 0,
                'failed_count' => 0,
                'results'      => [],
            ];
        }

        // If Legacy FCM Server Key is active, multicast can be sent in chunks via registration_ids
        $serverKey = $this->getFirebaseServerKey();
        $accessToken = $this->getFirebaseAccessToken();

        if (empty($accessToken) && !empty($serverKey)) {
            return $this->sendLegacyMulticast($validTokens, $title, $body, $data, $image);
        }

        // For FCM HTTP v1 API, send per token
        $sentCount = 0;
        $failedCount = 0;
        $results = [];

        foreach ($validTokens as $token) {
            $result = $this->sendFirebaseNotification($token, $title, $body, $data, $image);
            $results[] = [
                'token'   => $token,
                'success' => $result['success'],
                'message' => $result['message'],
            ];

            if ($result['success']) {
                $sentCount++;
            } else {
                $failedCount++;
            }
        }

        return [
            'success'      => $sentCount > 0,
            'message'      => "Broadcast complete: {$sentCount} sent, {$failedCount} failed.",
            'sent_count'   => $sentCount,
            'failed_count' => $failedCount,
            'results'      => $results,
        ];
    }

    /**
     * Send a Firebase push notification to a topic.
     *
     * @param string $topic      The topic name (e.g. 'all_reps', 'announcements').
     * @param string $title      Notification title.
     * @param string $body       Notification body.
     * @param array  $data       Custom data payload.
     * @param string|null $image Optional image URL.
     * @return array Result array ['success' => bool, 'message' => string, 'response' => mixed]
     */
    public function sendFirebaseTopicNotification(
        string $topic,
        string $title,
        string $body,
        array $data = [],
        ?string $image = null
    ): array {
        $cleanTopic = ltrim(trim($topic), '/');
        if (str_starts_with($cleanTopic, 'topics/')) {
            $cleanTopic = substr($cleanTopic, 7);
        }

        if (empty($cleanTopic)) {
            return [
                'success' => false,
                'message' => 'Topic name cannot be empty.',
                'response' => null,
            ];
        }

        return $this->dispatchFirebaseMessage([
            'target_type' => 'topic',
            'target'      => $cleanTopic,
            'title'       => $title,
            'body'        => $body,
            'data'        => $data,
            'image'       => $image,
        ]);
    }

    /**
     * Internal method to dispatch message using either FCM HTTP v1 or Legacy API.
     */
    protected function dispatchFirebaseMessage(array $params): array
    {
        $accessToken = $this->getFirebaseAccessToken();

        // 1. Prioritize modern FCM HTTP v1 API if OAuth2 token available
        if (!empty($accessToken)) {
            return $this->sendViaFcmHttpV1($accessToken, $params);
        }

        // 2. Fall back to Legacy FCM HTTP API if Server Key is configured
        $serverKey = $this->getFirebaseServerKey();
        if (!empty($serverKey)) {
            return $this->sendViaLegacyFcm($serverKey, $params);
        }

        $msg = 'Firebase credentials not configured. Please place service-account.json in storage/app/firebase/ or set FCM_SERVER_KEY in .env.';
        Log::warning('[FirebaseNotification] ' . $msg);

        return [
            'success'  => false,
            'message'  => $msg,
            'response' => null,
        ];
    }

    /**
     * Send message using FCM HTTP v1 API.
     */
    protected function sendViaFcmHttpV1(string $accessToken, array $params): array
    {
        try {
            $projectId = $this->getFirebaseProjectId();
            $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

            // Format custom data with string values (required by FCM HTTP v1)
            $formattedData = [];
            foreach (($params['data'] ?? []) as $key => $val) {
                $formattedData[(string) $key] = is_array($val) ? json_encode($val) : (string) $val;
            }

            $messagePayload = [
                'notification' => [
                    'title' => (string) $params['title'],
                    'body'  => (string) $params['body'],
                ],
                'android' => [
                    'priority' => 'HIGH',
                    'notification' => [
                        'sound'        => 'default',
                        'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                        'channel_id' => config('services.firebase.android_channel_id', 'high_importance_channel'),
                        'notification_priority' => 'PRIORITY_HIGH',
                        'default_vibrate_timings' => true,
                    ],
                ],
                'apns' => [
                    'headers' => [
                        'apns-push-type' => 'alert',
                        'apns-priority' => '10',
                    ],
                    'payload' => [
                        'aps' => [
                            'alert' => [
                                'title' => (string) $params['title'],
                                'body' => (string) $params['body'],
                            ],
                            'sound' => 'default',
                        ],
                    ],
                ],
            ];

            if (!empty($params['image'])) {
                $messagePayload['notification']['image'] = $params['image'];
            }

            if (!empty($formattedData)) {
                $messagePayload['data'] = $formattedData;
            }

            if ($params['target_type'] === 'topic') {
                $messagePayload['topic'] = $params['target'];
            } else {
                $messagePayload['token'] = $params['target'];
            }

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type'  => 'application/json; UTF-8',
            ])->timeout(10)->post($url, ['message' => $messagePayload]);

            if ($response->successful()) {
                Log::info('[FirebaseNotification] Notification sent successfully via FCM v1', [
                    'target' => $params['target'],
                    'title'  => $params['title'],
                ]);

                return [
                    'success'  => true,
                    'message'  => 'Notification sent successfully.',
                    'response' => $response->json(),
                ];
            }

            $errorBody = $response->json() ?? $response->body();
            Log::error('[FirebaseNotification] FCM v1 send failed', [
                'status'   => $response->status(),
                'response' => $errorBody,
                'target'   => $params['target'],
            ]);

            return [
                'success'  => false,
                'message'  => $errorBody['error']['message'] ?? 'Failed to send notification via Firebase v1.',
                'response' => $errorBody,
            ];
        } catch (Throwable $th) {
            Log::error('[FirebaseNotification] Exception during FCM v1 send: ' . $th->getMessage());
            return [
                'success'  => false,
                'message'  => 'Error sending notification: ' . $th->getMessage(),
                'response' => null,
            ];
        }
    }

    /**
     * Send message using Legacy FCM HTTP API.
     */
    protected function sendViaLegacyFcm(string $serverKey, array $params): array
    {
        try {
            $url = 'https://fcm.googleapis.com/fcm/send';

            $payload = [
                'notification' => [
                    'title' => $params['title'],
                    'body'  => $params['body'],
                    'sound' => 'default',
                ],
                'data'     => $params['data'] ?? [],
                'priority' => 'high',
            ];

            if (!empty($params['image'])) {
                $payload['notification']['image'] = $params['image'];
            }

            if ($params['target_type'] === 'topic') {
                $payload['to'] = '/topics/' . $params['target'];
            } else {
                $payload['to'] = $params['target'];
            }

            $response = Http::withHeaders([
                'Authorization' => "key={$serverKey}",
                'Content-Type'  => 'application/json',
            ])->timeout(10)->post($url, $payload);

            if ($response->successful()) {
                return [
                    'success'  => true,
                    'message'  => 'Notification sent successfully via Legacy FCM.',
                    'response' => $response->json(),
                ];
            }

            return [
                'success'  => false,
                'message'  => 'Failed to send notification via Legacy FCM.',
                'response' => $response->json() ?? $response->body(),
            ];
        } catch (Throwable $th) {
            Log::error('[FirebaseNotification] Exception in legacy send: ' . $th->getMessage());
            return [
                'success'  => false,
                'message'  => 'Error sending notification: ' . $th->getMessage(),
                'response' => null,
            ];
        }
    }

    /**
     * Send legacy multicast to up to 1000 tokens at a time.
     */
    protected function sendLegacyMulticast(array $tokens, string $title, string $body, array $data = [], ?string $image = null): array
    {
        $serverKey = $this->getFirebaseServerKey();
        $chunks = array_chunk($tokens, 1000);
        $totalSuccess = 0;
        $totalFailure = 0;
        $responses = [];

        foreach ($chunks as $chunk) {
            $payload = [
                'registration_ids' => $chunk,
                'notification'     => [
                    'title' => $title,
                    'body'  => $body,
                    'sound' => 'default',
                ],
                'data'     => $data,
                'priority' => 'high',
            ];

            if ($image) {
                $payload['notification']['image'] = $image;
            }

            $res = Http::withHeaders([
                'Authorization' => "key={$serverKey}",
                'Content-Type'  => 'application/json',
            ])->timeout(15)->post('https://fcm.googleapis.com/fcm/send', $payload);

            if ($res->successful()) {
                $json = $res->json();
                $totalSuccess += $json['success'] ?? count($chunk);
                $totalFailure += $json['failure'] ?? 0;
                $responses[] = $json;
            } else {
                $totalFailure += count($chunk);
                $responses[] = $res->body();
            }
        }

        return [
            'success'      => $totalSuccess > 0,
            'message'      => "Multicast complete: {$totalSuccess} succeeded, {$totalFailure} failed.",
            'sent_count'   => $totalSuccess,
            'failed_count' => $totalFailure,
            'results'      => $responses,
        ];
    }

    /**
     * Get or generate a Google OAuth2 access token from service account credentials.
     */
    public function getFirebaseAccessToken(): ?string
    {
        return Cache::remember('firebase_fcm_access_token', 3300, function () {
            $credentials = $this->getFirebaseServiceAccountCredentials();
            if (empty($credentials) || empty($credentials['client_email']) || empty($credentials['private_key'])) {
                return null;
            }

            try {
                $now = time();
                $header = ['alg' => 'RS256', 'typ' => 'JWT'];
                $claims = [
                    'iss'   => $credentials['client_email'],
                    'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                    'aud'   => 'https://oauth2.googleapis.com/token',
                    'exp'   => $now + 3600,
                    'iat'   => $now,
                ];

                $base64UrlHeader = $this->base64UrlEncode(json_encode($header));
                $base64UrlClaims = $this->base64UrlEncode(json_encode($claims));
                $dataToSign = "{$base64UrlHeader}.{$base64UrlClaims}";

                $signature = '';
                $privateKey = openssl_pkey_get_private($credentials['private_key']);
                if (!$privateKey) {
                    Log::error('[FirebaseNotification] Invalid private key in service-account.json');
                    return null;
                }

                $signed = openssl_sign($dataToSign, $signature, $privateKey, OPENSSL_ALGO_SHA256);
                if (!$signed) {
                    Log::error('[FirebaseNotification] Failed to sign JWT with private key');
                    return null;
                }

                $jwt = "{$dataToSign}." . $this->base64UrlEncode($signature);

                $response = Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/token', [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion'  => $jwt,
                ]);

                if ($response->successful()) {
                    return $response->json()['access_token'] ?? null;
                }

                Log::error('[FirebaseNotification] OAuth2 token exchange failed: ' . $response->body());
                return null;
            } catch (Throwable $th) {
                Log::error('[FirebaseNotification] Exception creating OAuth2 token: ' . $th->getMessage());
                return null;
            }
        });
    }

    /**
     * Get service account credentials from JSON file.
     */
    protected function getFirebaseServiceAccountCredentials(): ?array
    {
        $explicitPath = config('services.firebase.credentials');
        if (!empty($explicitPath)) {
            if (file_exists($explicitPath) && is_readable($explicitPath)) {
                $content = @file_get_contents($explicitPath);
                $json = json_decode($content, true);
                if (is_array($json) && isset($json['client_email'], $json['private_key'])) {
                    return $json;
                }
            }
            return null;
        }

        $possiblePaths = [
            env('FIREBASE_CREDENTIALS'),
            storage_path('app/firebase/service-account.json'),
            storage_path('app/firebase/firebase-service-account.json'),
            storage_path('app/firebase/firebase_credentials.json'),
            base_path('service-account.json'),
        ];

        // Also auto-discover any Admin SDK json placed in storage/app/firebase/
        $firebaseDir = storage_path('app/firebase');
        if (is_dir($firebaseDir)) {
            $matchedFiles = glob($firebaseDir . '/*.json') ?: [];
            foreach ($matchedFiles as $f) {
                if (!str_ends_with($f, 'google-services.json')) {
                    $possiblePaths[] = $f;
                }
            }
        }

        foreach (array_unique(array_filter($possiblePaths)) as $path) {
            if (file_exists($path) && is_readable($path)) {
                $content = @file_get_contents($path);
                $json = json_decode($content, true);
                if (is_array($json) && isset($json['client_email'], $json['private_key'])) {
                    return $json;
                }
            }
        }

        return null;
    }

    /**
     * Get the Firebase Project ID.
     */
    public function getFirebaseProjectId(): string
    {
        // 1. Config / env
        $projectId = config('services.firebase.project_id') ?: env('FIREBASE_PROJECT_ID');
        if (!empty($projectId)) {
            return $projectId;
        }

        // 2. Extract from storage/app/firebase/google-services.json
        $googleServicesPath = storage_path('app/firebase/google-services.json');
        if (file_exists($googleServicesPath) && is_readable($googleServicesPath)) {
            $json = json_decode(@file_get_contents($googleServicesPath), true);
            if (isset($json['project_info']['project_id'])) {
                return $json['project_info']['project_id'];
            }
        }

        // 3. Extract from service-account credentials
        $creds = $this->getFirebaseServiceAccountCredentials();
        if (isset($creds['project_id']) && !empty($creds['project_id'])) {
            return $creds['project_id'];
        }

        return 'sundarambrother-934fe';
    }

    /**
     * Get legacy FCM Server Key if configured.
     */
    public function getFirebaseServerKey(): ?string
    {
        return config('services.firebase.server_key')
            ?: env('FCM_SERVER_KEY')
            ?: env('FIREBASE_SERVER_KEY')
            ?: null;
    }

    /**
     * Helper to URL-safe base64 encode.
     */
    protected function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
