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
     * Create transfer recipient for vendor payments
     */
    public function createTransferRecipient($data)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->secretKey,
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/transferrecipient', $data);

            $responseData = $response->json();

            if ($response->successful() && $responseData['status']) {
                return [
                    'success' => true,
                    'data' => $responseData['data'],
                    'message' => $responseData['message'] ?? 'Transfer recipient created successfully'
                ];
            }

            return [
                'success' => false,
                'message' => $responseData['message'] ?? 'Failed to create transfer recipient',
                'error' => $responseData
            ];

        } catch (\Exception $e) {
            Log::error('Paystack createTransferRecipient failed', [
                'data' => $data,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Transfer recipient creation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Initiate transfer to recipient
     */
    public function initiateTransfer($data)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->secretKey,
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/transfer', $data);

            $responseData = $response->json();

            if ($response->successful() && $responseData['status']) {
                return [
                    'success' => true,
                    'data' => $responseData['data'],
                    'message' => $responseData['message'] ?? 'Transfer initiated successfully'
                ];
            }

            return [
                'success' => false,
                'message' => $responseData['message'] ?? 'Transfer initiation failed',
                'error' => $responseData
            ];

        } catch (\Exception $e) {
            Log::error('Paystack initiateTransfer failed', [
                'data' => $data,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Transfer initiation failed: ' . $e->getMessage()
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

            $responseData = $response->json();

            if ($response->successful() && $responseData['status']) {
                return [
                    'success' => true,
                    'data' => $responseData['data'],
                    'message' => $responseData['message'] ?? 'Transfer verification successful'
                ];
            }

            return [
                'success' => false,
                'message' => $responseData['message'] ?? 'Transfer verification failed'
            ];

        } catch (\Exception $e) {
            Log::error('Paystack verifyTransfer failed', [
                'transfer_code' => $transferCode,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Transfer verification failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * List banks for account validation
     */
    public function listBanks()
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->secretKey,
            ])->get($this->baseUrl . '/bank');

            $responseData = $response->json();

            if ($response->successful() && $responseData['status']) {
                return [
                    'success' => true,
                    'data' => $responseData['data'],
                    'message' => 'Banks retrieved successfully'
                ];
            }

            return [
                'success' => false,
                'message' => $responseData['message'] ?? 'Failed to retrieve banks'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to retrieve banks: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Verify bank account details
     */
    public function verifyAccountNumber($accountNumber, $bankCode)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->secretKey,
            ])->get($this->baseUrl . '/bank/resolve', [
                'account_number' => $accountNumber,
                'bank_code' => $bankCode
            ]);

            $responseData = $response->json();

            if ($response->successful() && $responseData['status']) {
                return [
                    'success' => true,
                    'data' => $responseData['data'],
                    'message' => 'Account verification successful'
                ];
            }

            return [
                'success' => false,
                'message' => $responseData['message'] ?? 'Account verification failed'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Account verification failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get transfer by reference
     */
    public function getTransferByReference($reference)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->secretKey,
            ])->get($this->baseUrl . '/transfer', [
                'reference' => $reference
            ]);

            $responseData = $response->json();

            if ($response->successful() && $responseData['status']) {
                return [
                    'success' => true,
                    'data' => $responseData['data'],
                    'message' => 'Transfer retrieved successfully'
                ];
            }

            return [
                'success' => false,
                'message' => $responseData['message'] ?? 'Transfer not found'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to retrieve transfer: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get wallet balance
     */
    public function getBalance()
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->secretKey,
            ])->get($this->baseUrl . '/balance');

            $responseData = $response->json();

            if ($response->successful() && $responseData['status']) {
                return [
                    'success' => true,
                    'data' => $responseData['data'],
                    'message' => 'Balance retrieved successfully'
                ];
            }

            return [
                'success' => false,
                'message' => $responseData['message'] ?? 'Failed to retrieve balance'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to retrieve balance: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Legacy method for backward compatibility
     * @deprecated Use createTransferRecipient instead
     */
    public function transferToVendor($vendor, $amount, $reference, $description)
    {
        // Create recipient data
        $recipientData = [
            'type' => 'nuban',
            'name' => $vendor->name,
            'account_number' => $vendor->account_number,
            'bank_code' => $vendor->bank_code,
            'currency' => 'NGN'
        ];

        // Create recipient
        $recipient = $this->createTransferRecipient($recipientData);

        if (!$recipient['success']) {
            return $recipient;
        }

        // Initiate transfer
        $transferData = [
            'source' => 'balance',
            'amount' => $amount * 100, // Convert to kobo
            'recipient' => $recipient['data']['recipient_code'],
            'reference' => $reference,
            'reason' => $description
        ];

        return $this->initiateTransfer($transferData);
    }
}
