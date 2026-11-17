<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RegistrationStatusChangedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $requestNumber,
        public readonly string $companyName,
        public readonly string $fromStatus,
        public readonly string $toStatus,
        public readonly string $action,
        public readonly string $note,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'BINAA2 registration update — '.$this->requestNumber,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.registration-status-changed');
    }
}
