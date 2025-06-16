<?php

namespace App\Mail;

use App\Models\Business;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Queue\SerializesModels;

class BusinessCredentials extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Business $business;
    public string $password;

    /**
     * Create a new message instance.
     */
    public function __construct(Business $business, string $password)
    {
        $this->business = $business;
        $this->password = $password;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('mail.from.address'),
                config('mail.from.name')
            ),
            subject: 'Your Business Account Credentials - ' . config('app.name'),
            tags: ['business-credentials', 'onboarding'],
            metadata: [
                'business_id' => $this->business->id,
                'business_name' => $this->business->name,
            ],
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'emails.business-credentials',
            with: [
                'business' => $this->business,
                'password' => $this->password,
                'loginUrl' => config('app.frontend_url', config('app.url')) . '/login',
            ]
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
