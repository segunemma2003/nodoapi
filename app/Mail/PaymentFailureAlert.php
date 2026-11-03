<?php

namespace App\Mail;

use App\Models\PurchaseOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Internal alert for a vendor payment that failed or was reversed.
 * Deliberately not queued: an alert must not depend on the worker dyno.
 */
class PaymentFailureAlert extends Mailable
{
    use Queueable, SerializesModels;

    public const TITLES = [
        'failed' => 'Vendor payment failed',
        'reversed' => 'Vendor payment reversed by bank',
        'not_initiated' => 'Vendor payment could not be started',
    ];

    public function __construct(
        public PurchaseOrder $purchaseOrder,
        public string $type,
        public string $reason,
        public ?string $reference = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: '[ACTION NEEDED] ' . (self::TITLES[$this->type] ?? 'Vendor payment problem') . ' - PO #' . $this->purchaseOrder->po_number,
            tags: ['purchase-order', 'payment-alert', $this->type],
            metadata: [
                'po_id' => $this->purchaseOrder->id,
                'vendor_id' => $this->purchaseOrder->vendor_id,
                'amount' => $this->purchaseOrder->net_amount,
            ],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.payment-failure-alert',
            with: [
                'title' => self::TITLES[$this->type] ?? 'Vendor payment problem',
                'purchaseOrder' => $this->purchaseOrder,
                'business' => $this->purchaseOrder->business,
                'vendor' => $this->purchaseOrder->vendor,
                'reason' => $this->reason,
                'reference' => $this->reference,
            ],
        );
    }
}
