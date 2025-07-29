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

class VendorApproved extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Vendor $vendor;
    public array $approvalDetails;

    public function __construct(Vendor $vendor, array $approvalDetails = [])
    {
        $this->vendor = $vendor;
        $this->approvalDetails = $approvalDetails;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('mail.from.address'),
                config('mail.from.name')
            ),
            subject: 'Vendor Approved - ' . $this->vendor->name,
            tags: ['vendor', 'approval', 'business'],
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
            markdown: 'emails.vendor-approved',
            with: [
                'vendor' => $this->vendor,
                'business' => $this->vendor->business,
                'approvalDetails' => $this->approvalDetails,
                'dashboardUrl' => config('app.frontend_url') . '/business/vendors/' . $this->vendor->id,
            ]
        );
    }
}
