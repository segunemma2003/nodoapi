<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class CreditPaymentDueDigest extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Collection $overduePurchaseOrders;

    public Collection $dueSoonPurchaseOrders;

    public function __construct(Collection $overduePurchaseOrders, Collection $dueSoonPurchaseOrders)
    {
        $this->overduePurchaseOrders = $overduePurchaseOrders;
        $this->dueSoonPurchaseOrders = $dueSoonPurchaseOrders;
    }

    public function envelope(): Envelope
    {
        $overdueCount = $this->overduePurchaseOrders->count();
        $dueSoonCount = $this->dueSoonPurchaseOrders->count();

        return new Envelope(
            from: new Address(
                config('mail.from.address'),
                config('mail.from.name')
            ),
            subject: "Vendor payments due: {$overdueCount} overdue, {$dueSoonCount} due soon",
            tags: ['credit-plan', 'payment-due-digest'],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.credit-payment-due-digest',
            with: [
                'overduePurchaseOrders' => $this->overduePurchaseOrders,
                'dueSoonPurchaseOrders' => $this->dueSoonPurchaseOrders,
                'adminUrl' => config('app.admin_url', config('app.frontend_url')) . '/credit-plan',
            ]
        );
    }
}
