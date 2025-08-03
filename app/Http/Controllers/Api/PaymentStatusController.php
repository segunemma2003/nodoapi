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
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
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

            // Calculate payment amounts based on status
            $paymentAmounts = $this->calculatePaymentAmounts($po, $request->payment_status);

            // Update payment status and amounts
            $po->update([
                'payment_status' => $request->payment_status,
                'total_paid_amount' => $paymentAmounts['total_paid_amount'],
                'outstanding_amount' => $paymentAmounts['outstanding_amount'],
            ]);

            // Log the update
            Log::info('Payment status updated via API', [
                'po_number' => $po->po_number,
                'po_id' => $po->id,
                'old_status' => $po->getOriginal('payment_status'),
                'new_status' => $request->payment_status,
                'total_paid' => $paymentAmounts['total_paid_amount'],
                'outstanding' => $paymentAmounts['outstanding_amount'],
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
                    'net_amount' => $po->net_amount,
                    'updated_at' => $po->updated_at,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to update payment status via API', [
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
     * Calculate payment amounts based on the new payment status.
     * This method is used to determine the total_paid_amount and outstanding_amount
     * when the payment_status is updated via API.
     */
    private function calculatePaymentAmounts(PurchaseOrder $po, $newStatus)
    {
        $totalAmount = $po->net_amount;
        $totalPaid = $po->total_paid_amount;
        $outstanding = $po->outstanding_amount;

        switch ($newStatus) {
            case 'unpaid':
                $totalPaid = 0;
                $outstanding = $totalAmount;
                break;
            case 'partially_paid':
                // This case is tricky. If it was 'fully_paid', outstanding would be 0.
                // If it was 'unpaid', outstanding would be total.
                // If it was 'partially_paid', outstanding would be total - totalPaid.
                // So, if totalPaid is 0, outstanding is total. If totalPaid is > 0, outstanding is total - totalPaid.
                $outstanding = $totalAmount - $totalPaid;
                break;
            case 'fully_paid':
                $totalPaid = $totalAmount;
                $outstanding = 0;
                break;
        }

        return [
            'total_paid_amount' => $totalPaid,
            'outstanding_amount' => $outstanding,
        ];
    }
}
