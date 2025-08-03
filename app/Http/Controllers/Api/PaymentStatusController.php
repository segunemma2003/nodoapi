<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class PaymentStatusController extends Controller
{
    /**
     * Update payment status for a purchase order
     * Open API - No authentication required
     */
    public function updatePaymentStatus(Request $request)
    {
        // Validate request
        $validator = Validator::make($request->all(), [
            'po_number' => 'required|string|max:50',
            'payment_status' => 'required|in:unpaid,partially_paid,fully_paid',
            'total_paid_amount' => 'required|numeric|min:0',
            'outstanding_amount' => 'required|numeric|min:0',
            'webhook_signature' => 'required|string',
            'timestamp' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        // Verify webhook signature
        if (!$this->verifyWebhookSignature($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid webhook signature'
            ], 401);
        }

        try {
            // Find PO by number
            $po = PurchaseOrder::where('po_number', $request->po_number)->first();

            if (!$po) {
                return response()->json([
                    'success' => false,
                    'message' => 'Purchase order not found',
                    'po_number' => $request->po_number
                ], 404);
            }

            // Update payment status
            $po->update([
                'payment_status' => $request->payment_status,
                'total_paid_amount' => $request->total_paid_amount,
                'outstanding_amount' => $request->outstanding_amount,
            ]);

            // Log the update
            Log::info('Payment status updated via webhook', [
                'po_number' => $po->po_number,
                'po_id' => $po->id,
                'old_status' => $po->getOriginal('payment_status'),
                'new_status' => $request->payment_status,
                'total_paid' => $request->total_paid_amount,
                'outstanding' => $request->outstanding_amount,
                'webhook_timestamp' => $request->timestamp,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Payment status updated successfully',
                'data' => [
                    'po_number' => $po->po_number,
                    'po_id' => $po->id,
                    'payment_status' => $po->payment_status,
                    'total_paid_amount' => $po->total_paid_amount,
                    'outstanding_amount' => $po->outstanding_amount,
                    'updated_at' => $po->updated_at,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to update payment status via webhook', [
                'po_number' => $request->po_number,
                'error' => $e->getMessage(),
                'request_data' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update payment status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get PO details by number (for webhook verification)
     */
    public function getPoDetails(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'po_number' => 'required|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'PO number is required'
            ], 400);
        }

        $po = PurchaseOrder::where('po_number', $request->po_number)
            ->with(['business', 'vendor'])
            ->first();

        if (!$po) {
            return response()->json([
                'success' => false,
                'message' => 'Purchase order not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'po_id' => $po->id,
                'po_number' => $po->po_number,
                'business_id' => $po->business_id,
                'business_name' => $po->business->name,
                'vendor_id' => $po->vendor_id,
                'vendor_name' => $po->vendor->name,
                'net_amount' => $po->net_amount,
                'payment_status' => $po->payment_status,
                'total_paid_amount' => $po->total_paid_amount,
                'outstanding_amount' => $po->outstanding_amount,
                'status' => $po->status,
                'created_at' => $po->created_at,
                'updated_at' => $po->updated_at,
            ]
        ]);
    }

    /**
     * Verify webhook signature
     */
    private function verifyWebhookSignature(Request $request)
    {
        $webhookSecret = config('services.payment_webhook.secret_key');

        if (empty($webhookSecret)) {
            Log::warning('Webhook secret key not configured');
            return false;
        }

        $payload = $request->po_number . $request->payment_status . $request->total_paid_amount . $request->outstanding_amount . $request->timestamp;
        $expectedSignature = hash_hmac('sha256', $payload, $webhookSecret);

        return hash_equals($expectedSignature, $request->webhook_signature);
    }
}
