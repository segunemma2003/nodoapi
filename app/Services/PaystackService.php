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
     * Verify transfer status by transfer code
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
     * NEW: Check transfer status and handle different states
     */
    public function getTransferStatus($transferCode)
    {
        $result = $this->verifyTransfer($transferCode);

        if (!$result['success']) {
            return $result;
        }

        $transfer = $result['data'];
        $status = $transfer['status'] ?? 'unknown';

        return [
            'success' => true,
            'status' => $status,
            'data' => $transfer,
            'is_successful' => in_array($status, ['success', 'pending']),
            'is_failed' => in_array($status, ['failed', 'reversed']),
            'needs_attention' => in_array($status, ['otp', 'pending']),
            'message' => $this->getStatusMessage($status)
        ];
    }

    /**
     * NEW: Get human-readable status message
     */
    private function getStatusMessage($status)
    {
        return match($status) {
            'success' => 'Transfer completed successfully',
            'pending' => 'Transfer is being processed',
            'failed' => 'Transfer failed',
            'reversed' => 'Transfer was reversed',
            'otp' => 'Transfer requires OTP verification from recipient bank',
            default => 'Transfer status unknown'
        };
    }

    /**
     * NEW: Verify webhook signature (for webhook security)
     */
    public function verifyWebhookSignature($payload, $signature)
    {
        $secretKey = $this->secretKey;
        $computedSignature = hash_hmac('sha512', $payload, $secretKey);

        return hash_equals($signature, $computedSignature);
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
            // Debug: Log the secret key (first 10 characters only for security)
            Log::info('PaystackService verifyAccountNumber', [
                'secret_key_preview' => substr($this->secretKey, 0, 10) . '...',
                'secret_key_length' => strlen($this->secretKey),
                'account_number' => $accountNumber,
                'bank_code' => $bankCode
            ]);

            if (empty($this->secretKey)) {
                return [
                    'success' => false,
                    'message' => 'Paystack secret key is not configured'
                ];
            }

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->secretKey,
                'Content-Type' => 'application/json',
            ])->get($this->baseUrl . '/bank/resolve', [
                'account_number' => $accountNumber,
                'bank_code' => $bankCode
            ]);

            $responseData = $response->json();

            // Debug: Log the response
            Log::info('Paystack API Response', [
                'status_code' => $response->status(),
                'response' => $responseData
            ]);

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
}
