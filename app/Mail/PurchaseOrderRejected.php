<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use App\Models\PurchaseOrder;
use Illuminate\Mail\Mailables\Address;

class PurchaseOrderRejected extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public PurchaseOrder $purchaseOrder;
    public string $rejectionReason;

    public function __construct(PurchaseOrder $purchaseOrder, string $rejectionReason)
    {
        $this->purchaseOrder = $purchaseOrder;
        $this->rejectionReason = $rejectionReason;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('mail.from.address'),
                config('mail.from.name')
            ),
            subject: 'Purchase Order Rejected - PO #' . $this->purchaseOrder->po_number,
            tags: ['purchase-order', 'rejection', 'vendor'],
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
            markdown: 'emails.purchase-order-rejected',
            with: [
                'purchaseOrder' => $this->purchaseOrder,
                'business' => $this->purchaseOrder->business,
                'vendor' => $this->purchaseOrder->vendor,
                'items' => $this->purchaseOrder->items ?? [],
                'rejectionReason' => $this->rejectionReason,
                'rejectedBy' => $this->purchaseOrder->approvedBy, // Same field stores who rejected
                'supportEmail' => config('mail.from.address'),
            ]
        );
    }
}
