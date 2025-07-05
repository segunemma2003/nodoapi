<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use App\Models\PurchaseOrder;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Address;

class PurchaseOrderApproved extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public PurchaseOrder $purchaseOrder;
    public array $paymentDetails;
    public string $recipientType;

    public function __construct(PurchaseOrder $purchaseOrder, array $paymentDetails, string $recipientType = 'business')
    {
        $this->purchaseOrder = $purchaseOrder;
        $this->paymentDetails = $paymentDetails;
        $this->recipientType = $recipientType;
    }

    public function envelope(): Envelope
    {
        $subject = $this->recipientType === 'business'
            ? 'Purchase Order Approved & Payment Sent - PO #' . $this->purchaseOrder->po_number
            : 'Purchase Order Approved - PO #' . $this->purchaseOrder->po_number;

        return new Envelope(
            from: new Address(
                config('mail.from.address'),
                config('mail.from.name')
            ),
            subject: $subject,
            tags: ['purchase-order', 'approval', $this->recipientType],
            metadata: [
                'po_id' => $this->purchaseOrder->id,
                'recipient_type' => $this->recipientType,
                'amount' => $this->purchaseOrder->net_amount,
            ],
        );
    }

    public function content(): Content
    {
        $template = $this->recipientType === 'business'
            ? 'emails.purchase-order-approved-business'
            : 'emails.purchase-order-approved-vendor';

        return new Content(
            markdown: $template,
            with: [
                'purchaseOrder' => $this->purchaseOrder,
                'business' => $this->purchaseOrder->business,
                'vendor' => $this->purchaseOrder->vendor,
                'items' => $this->purchaseOrder->items ?? [],
                'paymentDetails' => $this->paymentDetails,
                'approvedBy' => $this->purchaseOrder->approvedBy,
                'dashboardUrl' => $this->recipientType === 'business'
                    ? config('app.frontend_url') . '/business/purchase-orders/' . $this->purchaseOrder->id
                    : config('app.vendor_url', '#') . '/purchase-orders/' . $this->purchaseOrder->id,
            ]
        );
    }

public function attachments(): array
    {
        $attachments = [];

        // Attach PO PDF
        $poPdfPath = storage_path('app/private/purchase_orders/po_' . $this->purchaseOrder->id . '.pdf');
        if (file_exists($poPdfPath)) {
            $attachments[] = Attachment::fromPath($poPdfPath)
                ->as('Purchase_Order_' . $this->purchaseOrder->po_number . '.pdf')
                ->withMime('application/pdf');
        }

        // Attach payment receipt PDF (for vendor)
        if ($this->recipientType === 'vendor') {
            $receiptPath = storage_path('app/private/payment_receipts/payment_' . $this->paymentDetails['transfer_code'] . '.pdf');
            if (file_exists($receiptPath)) {
                $attachments[] = Attachment::fromPath($receiptPath)
                    ->as('Payment_Receipt_' . $this->paymentDetails['reference'] . '.pdf')
                    ->withMime('application/pdf');
            }
        }

        return $attachments;
    }
}
