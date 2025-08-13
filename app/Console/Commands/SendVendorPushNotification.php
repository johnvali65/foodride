<?php

namespace App\Console\Commands;

use App\Models\Vendor;
use Exception;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Console\Command;

class SendVendorPushNotification extends Command
{
    protected $signature = 'send:push-vendor-notifications {data}';

    protected $description = 'Send vendor push notifications to devices in the background';

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
        // Path to your Firebase service account JSON file
        $serviceAccountPath = base_path('config/service-account.json');

        // OAuth 2.0 scopes for Firebase
        $scopes = ['https://www.googleapis.com/auth/firebase.messaging'];

        // Get OAuth 2.0 access token
        $credentials = new ServiceAccountCredentials($scopes, $serviceAccountPath);
        $token = $credentials->fetchAuthToken()['access_token'];

        // Prepare the URL and headers for the FCM HTTP V1 API
        $url = "https://fcm.googleapis.com/v1/projects/foodride-73f43/messages:send";
        $headers = [
            "Authorization: Bearer " . $token,
            "Content-Type: application/json"
        ];

        // Set the message data
        $messageData = [
            'message' => [
                'token' => '', // Will be filled in later for each registration token
                'notification' => [
                    'title' => $data['title'] ?? '', // Use null coalescing to avoid undefined index error
                    'body' => $data['description'] ?? '', // Same here for description
                    'image' => $data['image'] ?? '', // Same here for image
                    // 'sound' => 'notification.wav', // Uncomment if sound is needed
                ],
                'data' => [
                    'order_id' => (string) ($data['order_id'] ?? ''), // Default to empty string if not set
                    'type' => (string) ($data['type'] ?? ''), // Same for type
                    'conversation_id' => (string) ($data['conversation_id'] ?? ''), // Default to empty if not set
                    'sender_type' => (string) ($data['sender_type'] ?? ''), // Same for sender_type
                    'is_read' => "0", // Assuming is_read is always 0 for new notifications
                ],
            ],
        ];

        if ($data['zone_id'] == 'all') {
            $tokens = Vendor::select('firebase_token')
                ->where('firebase_token', '!=', '@')
                // ->limit(1000)
                ->pluck('firebase_token')
                ->toArray();
        } else {
            // Get the Firebase tokens from the database
            $tokens = Vendor::select('vendors.firebase_token')
                ->join('restaurants', 'vendors.id', '=', 'restaurants.vendor_id')
                ->where('vendors.firebase_token', '!=', '@')
                ->where('restaurants.zone_id', $data['zone_id'])
                ->pluck('vendors.firebase_token')
                ->toArray();
        }

        // Initialize an array to hold responses
        $responses = [];

        // Loop through each token and send the notification
        foreach ($tokens as $token) {
            // Set the token for this message
            $messageData['message']['token'] = $token;

            // Prepare the POST data
            $postData = json_encode($messageData);
            info('The Payload data is......... ' . $postData);
            // Initialize cURL
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            // Execute the request
            $result = curl_exec($ch);
            // Close the cURL session
            curl_close($ch);

            // Collect the response
            $responses[] = json_decode($result, true);
        }

        info('The response is.......' . json_encode($responses));
        return $responses; // Return all responses from the notifications sent
    }
}
