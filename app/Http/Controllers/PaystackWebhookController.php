<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PaystackService;
use App\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaystackWebhookController extends Controller
{
    protected $paystackService;

    public function __construct(PaystackService $paystackService)
    {
        $this->paystackService = $paystackService;
    }

    /**
     * Handle Paystack webhooks for transfer events
     */
    public function handleWebhook(Request $request)
    {
        // Get the payload and signature
        $payload = $request->getContent();
        $signature = $request->header('x-paystack-signature');

        // Verify webhook signature
        if (!$this->paystackService->verifyWebhookSignature($payload, $signature)) {
            Log::warning('Invalid Paystack webhook signature', [
                'signature' => $signature,
                'payload_length' => strlen($payload)
            ]);

            return response()->json(['message' => 'Invalid signature'], 400);
        }

        $event = json_decode($payload, true);

        if (!$event) {
            return response()->json(['message' => 'Invalid JSON payload'], 400);
        }

        $eventType = $event['event'] ?? null;

        Log::info('Paystack webhook received', [
            'event_type' => $eventType,
            'data' => $event['data'] ?? null
        ]);

        // Handle different event types
        switch ($eventType) {
            case 'transfer.success':
                $this->handleTransferSuccess($event['data']);
                break;

            case 'transfer.failed':
                $this->handleTransferFailed($event['data']);
                break;

            case 'transfer.reversed':
                $this->handleTransferReversed($event['data']);
                break;

            default:
                Log::info('Unhandled Paystack webhook event', ['event_type' => $eventType]);
        }

        return response()->json(['message' => 'Webhook processed successfully']);
    }

    /**
     * Handle successful transfer
     */
    private function handleTransferSuccess($transferData)
    {
        $reference = $transferData['reference'] ?? null;

        if (!$reference || !str_starts_with($reference, 'PO_VENDOR_')) {
            return;
        }

        // Extract PO number from reference
        $poNumber = $this->extractPONumberFromReference($reference);
        $po = PurchaseOrder::where('po_number', $poNumber)->first();

        if (!$po) {
            Log::warning('PO not found for successful transfer', [
                'reference' => $reference,
                'po_number' => $poNumber
            ]);
            return;
        }

        // Update transfer status in PO notes
        $po->update([
            'notes' => ($po->notes ?? '') . "\nPayment completed successfully via Paystack on " . now()->format('Y-m-d H:i:s'),
        ]);

        Log::info('Transfer success recorded for PO', [
            'po_id' => $po->id,
            'po_number' => $po->po_number,
            'transfer_reference' => $reference,
            'amount' => $transferData['amount'] ?? 0
        ]);

        // Optional: Send additional notification to business/vendor about successful payment
        $this->notifyTransferSuccess($po, $transferData);
    }

    /**
     * Handle failed transfer
     */
    private function handleTransferFailed($transferData)
    {
        $reference = $transferData['reference'] ?? null;

        if (!$reference || !str_starts_with($reference, 'PO_VENDOR_')) {
            return;
        }

        $poNumber = $this->extractPONumberFromReference($reference);
        $po = PurchaseOrder::where('po_number', $poNumber)->first();

        if (!$po) {
            Log::warning('PO not found for failed transfer', [
                'reference' => $reference,
                'po_number' => $poNumber
            ]);
            return;
        }

        // Update PO status - might need admin attention
        $po->update([
            'status' => 'payment_failed',
            'notes' => ($po->notes ?? '') . "\nPayment failed via Paystack: " . ($transferData['failure_reason'] ?? 'Unknown reason'),
        ]);

        Log::error('Transfer failed for PO', [
            'po_id' => $po->id,
            'po_number' => $po->po_number,
            'transfer_reference' => $reference,
            'failure_reason' => $transferData['failure_reason'] ?? 'Unknown'
        ]);

        // Notify admin about failed payment
        $this->notifyTransferFailure($po, $transferData);
    }

    /**
     * Handle reversed transfer
     */
    private function handleTransferReversed($transferData)
    {
        $reference = $transferData['reference'] ?? null;

        if (!$reference || !str_starts_with($reference, 'PO_VENDOR_')) {
            return;
        }

        $poNumber = $this->extractPONumberFromReference($reference);
        $po = PurchaseOrder::where('po_number', $poNumber)->first();

        if (!$po) {
            return;
        }

        $po->update([
            'notes' => ($po->notes ?? '') . "\nPayment was reversed by bank on " . now()->format('Y-m-d H:i:s'),
        ]);

        Log::warning('Transfer reversed for PO', [
            'po_id' => $po->id,
            'po_number' => $po->po_number,
            'transfer_reference' => $reference
        ]);
    }

    /**
     * Extract PO number from payment reference
     */
    private function extractPONumberFromReference($reference)
    {
        // Reference format: PO_VENDOR_{PO_NUMBER}_{timestamp}
        $parts = explode('_', $reference);
        return $parts[2] ?? null;
    }

    /**
     * Notify about successful transfer
     */
    private function notifyTransferSuccess($po, $transferData)
    {
        // You can implement additional success notifications here
        // For example, SMS to vendor, push notification to business app, etc.
    }

    /**
     * Notify about failed transfer - requires admin attention
     */
    private function notifyTransferFailure($po, $transferData)
    {
        // Notify admin about failed payment that needs attention
        // You can send email to admin or create a support ticket

        Log::critical('Purchase Order payment failed - Admin attention required', [
            'po_id' => $po->id,
            'po_number' => $po->po_number,
            'business_name' => $po->business->name,
            'vendor_name' => $po->vendor->name,
            'amount' => $po->net_amount,
            'failure_reason' => $transferData['failure_reason'] ?? 'Unknown'
        ]);
    }
}
