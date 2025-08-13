<?php

namespace App\Console\Commands;

use App\Models\User;
use Exception;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Console\Command;

class SendPushNotification extends Command
{
    protected $signature = 'send:push-notifications {data}';

    protected $description = 'Send push notifications to devices in the background';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $dataJson = $this->argument('data'); // Get the data from the command argument
        $data = json_decode($dataJson, true); // Decode JSON data back to an array

        // Here you can reuse your notification sending logic
        $this->sendNotification($data); // Call the existing method to send notifications
    }
    private function sendNotification($data)
    {
        info("sendNotification function executed");

        // Firebase service account credentials
        $serviceAccountPath = base_path('config/service-account.json');
        if (!file_exists($serviceAccountPath)) {
            throw new \Exception("Service account file not found at $serviceAccountPath");
        }

        // Use correct Google Auth class
        $scopes = ['https://www.googleapis.com/auth/firebase.messaging'];
        $credentials = new ServiceAccountCredentials($scopes, $serviceAccountPath);
        $accessTokenArray = $credentials->fetchAuthToken();

        if (!isset($accessTokenArray['access_token'])) {
            throw new \Exception("Unable to fetch access token from service account credentials.");
        }

        $accessToken = $accessTokenArray['access_token'];

        $url = "https://fcm.googleapis.com/v1/projects/foodride-73f43/messages:send";
        $headers = [
            "Authorization: Bearer $accessToken",
            "Content-Type: application/json"
        ];

        // Fetch tokens (Android + iOS)
        $query = User::query();

        if ($data['zone_id'] !== 'all') {
            $query->where('zone_id', $data['zone_id']);
        }

        $users = $query->select('id', 'f_name', 'cm_firebase_token', 'cm_firebase_token_ios')
            ->orderByDesc('id')
            ->get();

        $validTokens = [];

        foreach ($users as $user) {
            foreach (['cm_firebase_token', 'cm_firebase_token_ios'] as $field) {
                $token = trim($user->{$field});
                if (!empty($token) && strlen($token) > 100) {
                    $validTokens[$token] = $user->id;
                }
            }
        }

        $results = [];
        
        foreach ($validTokens as $token => $userId) {
            $payload = [
                'message' => [
                    'token' => $token,
                    'notification' => [
                        'title' => $data['title'],
                        'body' => $data['message'],
                        'image' => $data['image'] ?? null,
                    ],
                    'apns' => [
                        'payload' => [
                            'aps' => [
                                'alert' => [
                                    'title' => $data['title'],
                                    'body' => $data['message'],
                                ],
                                'sound' => 'default',
                                'badge' => 1,
                                'content-available' => 1,
                            ]
                        ]
                    ],
                    'data' => $data,
                ]
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $results[] = [
                'token' => $token,
                'response' => $response,
                'http_code' => $httpCode,
            ];

            // Handle invalid tokens
            $decoded = json_decode($response, true);
            if (
                isset($decoded['error']['status']) &&
                in_array($decoded['error']['status'], ['INVALID_ARGUMENT', 'UNREGISTERED'])
            ) {

                // Invalidate token in DB
                User::where('id', $userId)->update([
                    'cm_firebase_token' => null,
                    'cm_firebase_token_ios' => null
                ]);

                info("Invalid FCM token removed for user ID: $userId");
            }
        }

        info("Notification Results: " . json_encode($results, JSON_PRETTY_PRINT));

        return $results;
    }
}
