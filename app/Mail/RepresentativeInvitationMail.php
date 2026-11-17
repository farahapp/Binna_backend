<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RepresentativeInvitationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $invitationUrl,
        public readonly string $purpose,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'BINAA2 representative account invitation');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.representative-invitation');
    }
}
