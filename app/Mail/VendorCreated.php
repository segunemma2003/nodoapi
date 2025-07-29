<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use App\Models\Vendor;
use Illuminate\Mail\Mailables\Address;

class VendorCreated extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Vendor $vendor;

    public function __construct(Vendor $vendor)
    {
        $this->vendor = $vendor;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('mail.from.address'),
                config('mail.from.name')
            ),
            subject: 'New Vendor Pending Approval - ' . $this->vendor->name,
            tags: ['vendor', 'admin-notification'],
            metadata: [
                'vendor_id' => $this->vendor->id,
                'business_id' => $this->vendor->business_id,
                'vendor_name' => $this->vendor->name,
            ],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.vendor-created',
            with: [
                'vendor' => $this->vendor,
                'business' => $this->vendor->business,
                'adminUrl' => config('app.admin_url', config('app.frontend_url')) . '/admin/vendors/' . $this->vendor->id,
            ]
        );
    }
}
