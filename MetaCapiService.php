<?php

namespace App\Services;

use App\Models\GeneralWebSettings;
use App\Models\Orders;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MetaCapiService
{
    /**
     * Get Meta Pixel ID from database or default
     */
    public static function getPixelId(): string
    {
        $setting = GeneralWebSettings::where('name', 'fb_pixel_id')->first();
        return !empty($setting?->value) ? trim($setting->value) : '1814018549762511';
    }

    /**
     * Get Meta Conversions API Access Token from database or default
     */
    public static function getAccessToken(): string
    {
        $setting = GeneralWebSettings::where('name', 'fb_capi_access_token')->first();
        return !empty($setting?->value) ? trim($setting->value) : 'EAAWVCFJjBBQBSbD68VZAUIMvlsaOO4EC8l0yXPDMEwDVuZBPDGW0B8bkMNtM4g4BgPAZBxHZByZA8JyDwo1ZBsux6vk6Bjd5U6Q9MRq4JZCZBm9M6mLoZAJswHaqTG1FTDHbz9xC1MFUgPOK1uU1prCate3BZBTfrJWPbpe30cbeYp7LkkxtiHe9gUzxNSpZBDMWodXNgZDZD';
    }

    /**
     * Get Meta CAPI Test Event Code
     */
    public static function getTestEventCode(): ?string
    {
        $setting = GeneralWebSettings::where('name', 'fb_capi_test_code')->first();
        return !empty($setting?->value) ? trim($setting->value) : 'TEST23234';
    }

    /**
     * Check if CAPI is enabled
     */
    public static function isEnabled(): bool
    {
        $setting = GeneralWebSettings::where('name', 'fb_capi_status')->first();
        if ($setting) {
            return (string)$setting->value === '1' || (string)$setting->value === 'on';
        }
        return true; // Enabled by default
    }

    /**
     * Send Purchase Event to Meta Conversions API
     *
     * @param Orders $order
     * @param string|null $eventId
     * @param array|null $clientContext
     * @return bool
     */
    public static function sendPurchaseEvent(Orders $order, ?string $eventId = null, ?array $clientContext = null): bool
    {
        if (!self::isEnabled()) {
            return false;
        }

        $pixelId = self::getPixelId();
        $accessToken = self::getAccessToken();

        if (empty($pixelId) || empty($accessToken)) {
            Log::warning('Meta CAPI: Missing Pixel ID or Access Token.');
            return false;
        }

        $eventId = $eventId ?: 'purchase_' . $order->id;

        // Extract and normalize customer data
        $customerName = trim($order->name ?? '');
        $nameParts = explode(' ', $customerName, 2);
        $firstName = $nameParts[0] ?? '';
        $lastName = $nameParts[1] ?? '';

        $rawPhone = preg_replace('/[^0-9]/', '', $order->phone ?? '');
        if (strlen($rawPhone) === 11 && str_starts_with($rawPhone, '01')) {
            $normalizedPhone = '88' . $rawPhone;
        } elseif (strlen($rawPhone) === 13 && str_starts_with($rawPhone, '8801')) {
            $normalizedPhone = $rawPhone;
        } else {
            $normalizedPhone = $rawPhone;
        }

        $email = strtolower(trim($order->email ?? ($order->user->email ?? '')));
        $city = strtolower(trim($order->district ?? 'dhaka'));
        $district = strtolower(trim($order->district ?? ''));

        // Prepare User Data for Advanced Matching (SHA-256 Hashed)
        $userData = [
            'country' => [hash('sha256', 'bd')],
            'client_ip_address' => $clientContext['ip'] ?? $order->ip_address ?? request()->ip(),
            'client_user_agent' => $clientContext['user_agent'] ?? request()->userAgent(),
        ];

        if (!empty($normalizedPhone)) {
            $userData['ph'] = [hash('sha256', $normalizedPhone)];
        }
        if (!empty($email)) {
            $userData['em'] = [hash('sha256', $email)];
        }
        if (!empty($firstName)) {
            $userData['fn'] = [hash('sha256', strtolower($firstName))];
        }
        if (!empty($lastName)) {
            $userData['ln'] = [hash('sha256', strtolower($lastName))];
        }
        if (!empty($city)) {
            $userData['ct'] = [hash('sha256', $city)];
        }
        if (!empty($district)) {
            $userData['st'] = [hash('sha256', $district)];
        }
        if (!empty($order->user_id) && $order->user_id > 0) {
            $userData['external_id'] = [hash('sha256', (string)$order->user_id)];
        } else {
            $userData['external_id'] = [hash('sha256', 'order_' . $order->id)];
        }

        // Add Meta Browser Cookies if present
        $fbp = $clientContext['fbp'] ?? request()->cookie('_fbp');
        if (!empty($fbp)) {
            $userData['fbp'] = $fbp;
        }

        $fbc = $clientContext['fbc'] ?? request()->cookie('_fbc');
        if (!empty($fbc)) {
            $userData['fbc'] = $fbc;
        }

        // Build Order Items (Contents)
        $order->loadMissing('orderDetails.product');
        $contents = [];
        $contentIds = [];
        $totalQuantity = 0;

        if ($order->orderDetails && $order->orderDetails->isNotEmpty()) {
            foreach ($order->orderDetails as $detail) {
                $pId = (string)($detail->product_id ?? $detail->product?->id);
                $pQty = (int)($detail->product_qty ?? 1);
                $pPrice = (float)($detail->unit_price ?? $detail->product?->new_price ?? 0);

                $contents[] = [
                    'id' => $pId,
                    'quantity' => $pQty,
                    'item_price' => $pPrice,
                ];
                $contentIds[] = $pId;
                $totalQuantity += $pQty;
            }
        }

        // Custom Data
        $customData = [
            'currency' => 'BDT',
            'value' => (float)($order->grand_total ?? 0),
            'content_type' => 'product',
            'order_id' => (string)$order->id,
            'num_items' => $totalQuantity ?: 1,
        ];

        if (!empty($contents)) {
            $customData['contents'] = $contents;
            $customData['content_ids'] = $contentIds;
        }

        // Event Payload
        $eventData = [
            'event_name' => 'Purchase',
            'event_time' => time(),
            'event_id' => $eventId,
            'event_source_url' => url()->current(),
            'action_source' => 'website',
            'user_data' => $userData,
            'custom_data' => $customData,
        ];

        $payload = [
            'data' => [$eventData],
        ];

        // Attach Test Event Code if present
        $testCode = self::getTestEventCode();
        if (!empty($testCode)) {
            $payload['test_event_code'] = $testCode;
        }

        try {
            $response = Http::timeout(6)->post("https://graph.facebook.com/v19.0/{$pixelId}/events?access_token={$accessToken}", $payload);

            if ($response->successful()) {
                Log::info("Meta CAPI Purchase Event Sent Successfully for Order #{$order->id} (Event ID: {$eventId})", [
                    'response' => $response->json(),
                ]);
                return true;
            } else {
                Log::error("Meta CAPI Purchase Event Failed for Order #{$order->id}", [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return false;
            }
        } catch (\Exception $e) {
            Log::error("Meta CAPI Exception for Order #{$order->id}: " . $e->getMessage());
            return false;
        }
    }
}
