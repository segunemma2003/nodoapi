<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaystackService
{
    private $secretKey;
    private $baseUrl;

    public function __construct()
    {
        $this->secretKey = config('services.paystack.secret_key');
        $this->baseUrl = 'https://api.paystack.co';
    }

    /**
     * Make payment to vendor account
     */
    public function transferToVendor($vendor, $amount, $reference, $description)
    {
        try {
            // First, create transfer recipient if not exists
            $recipient = $this->createTransferRecipient($vendor);

            if (!$recipient['success']) {
                return $recipient;
            }

            // Initiate transfer
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->secretKey,
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/transfer', [
                'source' => 'balance',
                'amount' => $amount * 100, // Convert to kobo
                'recipient' => $recipient['data']['recipient_code'],
                'reference' => $reference,
                'reason' => $description,
            ]);

            $data = $response->json();

            if ($response->successful() && $data['status']) {
                return [
                    'success' => true,
                    'data' => [
                        'transfer_code' => $data['data']['transfer_code'],
                        'amount' => $amount,
                        'reference' => $reference,
                        'status' => $data['data']['status'],
                        'recipient_code' => $recipient['data']['recipient_code'],
                    ]
                ];
            }

            return [
                'success' => false,
                'message' => $data['message'] ?? 'Transfer failed',
                'error' => $data
            ];

        } catch (\Exception $e) {
            Log::error('Paystack transfer failed', [
                'vendor_id' => $vendor->id,
                'amount' => $amount,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Payment service error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Create transfer recipient for vendor
     */
    private function createTransferRecipient($vendor)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->secretKey,
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/transferrecipient', [
                'type' => 'nuban',
                'name' => $vendor->name,
                'account_number' => $vendor->account_number,
                'bank_code' => $vendor->bank_code,
                'currency' => 'NGN',
            ]);

            $data = $response->json();

            if ($response->successful() && $data['status']) {
                return [
                    'success' => true,
                    'data' => [
                        'recipient_code' => $data['data']['recipient_code'],
                    ]
                ];
            }

            return [
                'success' => false,
                'message' => $data['message'] ?? 'Failed to create recipient'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to create transfer recipient: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Verify transfer status
     */
    public function verifyTransfer($transferCode)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->secretKey,
            ])->get($this->baseUrl . '/transfer/' . $transferCode);

            $data = $response->json();

            if ($response->successful() && $data['status']) {
                return [
                    'success' => true,
                    'data' => $data['data']
                ];
            }

            return [
                'success' => false,
                'message' => $data['message'] ?? 'Verification failed'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Transfer verification failed: ' . $e->getMessage()
            ];
        }
    }
}
