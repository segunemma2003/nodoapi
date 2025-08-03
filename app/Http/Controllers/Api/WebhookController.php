<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class WebhookController extends Controller
{
    /**
     * Send webhook notification when PO is created
     */
    public function sendPoCreatedWebhook(PurchaseOrder $po)
    {
        $webhookUrl = config('services.payment_webhook.url');
        $webhookSecret = config('services.payment_webhook.secret_key');

        if (empty($webhookUrl) || !config('services.payment_webhook.enabled', true)) {
            Log::info('Webhook not configured or disabled for PO creation', [
                'po_number' => $po->po_number,
                'po_id' => $po->id
            ]);
            return;
        }

        $payload = [
            'event' => 'purchase_order.created',
            'po_number' => $po->po_number,
            'po_id' => $po->id,
            'business_id' => $po->business_id,
            'business_name' => $po->business->name,
            'vendor_id' => $po->vendor_id,
            'vendor_name' => $po->vendor->name,
            'net_amount' => $po->net_amount,
            'payment_status' => $po->payment_status,
            'status' => $po->status,
            'order_date' => $po->order_date,
            'due_date' => $po->due_date,
            'created_at' => $po->created_at,
            'timestamp' => time(),
        ];

        // Generate signature
        $signature = hash_hmac('sha256', json_encode($payload), $webhookSecret);

        $payload['webhook_signature'] = $signature;

        try {
            $response = Http::timeout(30)->post($webhookUrl, $payload);

            if ($response->successful()) {
                Log::info('PO creation webhook sent successfully', [
                    'po_number' => $po->po_number,
                    'webhook_url' => $webhookUrl,
                    'response_status' => $response->status(),
                ]);
            } else {
                Log::error('PO creation webhook failed', [
                    'po_number' => $po->po_number,
                    'webhook_url' => $webhookUrl,
                    'response_status' => $response->status(),
                    'response_body' => $response->body(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('PO creation webhook exception', [
                'po_number' => $po->po_number,
                'webhook_url' => $webhookUrl,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send webhook notification when PO payment status changes
     */
    public function sendPaymentStatusWebhook(PurchaseOrder $po, $oldStatus = null)
    {
        $webhookUrl = config('services.payment_webhook.url');
        $webhookSecret = config('services.payment_webhook.secret_key');

        if (empty($webhookUrl) || !config('services.payment_webhook.enabled', true)) {
            return;
        }

        $payload = [
            'event' => 'purchase_order.payment_status_updated',
            'po_number' => $po->po_number,
            'po_id' => $po->id,
            'business_id' => $po->business_id,
            'business_name' => $po->business->name,
            'vendor_id' => $po->vendor_id,
            'vendor_name' => $po->vendor->name,
            'net_amount' => $po->net_amount,
            'old_payment_status' => $oldStatus,
            'new_payment_status' => $po->payment_status,
            'total_paid_amount' => $po->total_paid_amount,
            'outstanding_amount' => $po->outstanding_amount,
            'status' => $po->status,
            'updated_at' => $po->updated_at,
            'timestamp' => time(),
        ];

        // Generate signature
        $signature = hash_hmac('sha256', json_encode($payload), $webhookSecret);
        $payload['webhook_signature'] = $signature;

        try {
            $response = Http::timeout(30)->post($webhookUrl, $payload);

            if ($response->successful()) {
                Log::info('Payment status webhook sent successfully', [
                    'po_number' => $po->po_number,
                    'old_status' => $oldStatus,
                    'new_status' => $po->payment_status,
                ]);
            } else {
                Log::error('Payment status webhook failed', [
                    'po_number' => $po->po_number,
                    'response_status' => $response->status(),
                    'response_body' => $response->body(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Payment status webhook exception', [
                'po_number' => $po->po_number,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
