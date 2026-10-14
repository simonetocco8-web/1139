<?php

namespace App\Domains\Identity\Delivery;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class TechnicalMessage extends Mailable
{
    public function __construct(public string $neutralSubject, public string $neutralBody) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->neutralSubject);
    }

    public function content(): Content
    {
        return new Content(text: 'identity.message', with: ['text' => $this->neutralBody]);
    }
}
