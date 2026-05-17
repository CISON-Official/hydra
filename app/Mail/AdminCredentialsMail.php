<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminCredentialsMail extends Mailable
{
    use Queueable, SerializesModels;

    // Declare the properties so they are automatically accessible in your email view template
    public User $admin;
    public string $apiKey;

    /**
     * Create a new message instance.
     */
    public function __construct(User $admin, string $apiKey)
    {
        $this->admin = $admin;
        $this->apiKey = $apiKey;
    }

    /**
     * Get the message envelope.
     */
    public function __envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Admin Account Credentials',
        );
    }

    /**
     * Get the message content definition.
     */
    public function __content(): Content
    {
        return new Content(
            view: 'emails.admin_credentials', // This points to resources/views/emails/admin_credentials.blade.php
        );
    }
}
