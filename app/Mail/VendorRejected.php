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

class VendorRejected extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Vendor $vendor;
    public array $rejectionDetails;

    public function __construct(Vendor $vendor, array $rejectionDetails = [])
    {
        $this->vendor = $vendor;
        $this->rejectionDetails = $rejectionDetails;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('mail.from.address'),
                config('mail.from.name')
            ),
            subject: 'Vendor Rejected - ' . $this->vendor->name,
            tags: ['vendor', 'rejection', 'business'],
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
            markdown: 'emails.vendor-rejected',
            with: [
                'vendor' => $this->vendor,
                'business' => $this->vendor->business,
                'rejectionDetails' => $this->rejectionDetails,
                'dashboardUrl' => config('app.frontend_url') . '/business/vendors/' . $this->vendor->id,
            ]
        );
    }
}
