<?php

namespace App\Services;

use App\Mail\PaymentFailureAlert;
use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PaymentAlertService
{
    /**
     * Email the payment-alert list about a vendor payment problem.
     * Never throws: a broken mailer must not break payment handling.
     */
    public static function notify(PurchaseOrder $po, string $type, string $reason, ?string $reference = null): void
    {
        Log::critical('Vendor payment problem - admin attention required', [
            'type' => $type,
            'po_id' => $po->id,
            'po_number' => $po->po_number,
            'amount' => $po->net_amount,
            'reason' => $reason,
            'reference' => $reference,
        ]);

        $recipients = config('services.payment_alerts.emails', []);
        if (empty($recipients)) {
            Log::warning('No PAYMENT_ALERT_EMAILS configured; payment alert not emailed', ['po_id' => $po->id]);
            return;
        }

        try {
            $po->loadMissing(['business', 'vendor']);
            Mail::to($recipients)->send(new PaymentFailureAlert($po, $type, $reason, $reference));
        } catch (\Throwable $e) {
            Log::error('Failed to send payment failure alert email', [
                'po_id' => $po->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
