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


class PurchaseOrderCreated extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public PurchaseOrder $purchaseOrder;

    public function __construct(PurchaseOrder $purchaseOrder)
    {
        $this->purchaseOrder = $purchaseOrder;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('mail.from.address'),
                config('mail.from.name')
            ),
            subject: 'New Purchase Order Pending Approval - PO #' . $this->purchaseOrder->po_number,
            tags: ['purchase-order', 'admin-notification'],
            metadata: [
                'po_id' => $this->purchaseOrder->id,
                'business_id' => $this->purchaseOrder->business_id,
                'amount' => $this->purchaseOrder->net_amount,
            ],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.purchase-order-created',
            with: [
                'purchaseOrder' => $this->purchaseOrder,
                'business' => $this->purchaseOrder->business,
                'vendor' => $this->purchaseOrder->vendor,
                'items' => $this->purchaseOrder->items ?? [],
                'adminUrl' => config('app.admin_url', config('app.frontend_url')) . '/admin/purchase-orders/' . $this->purchaseOrder->id,
            ]
        );
    }
}
