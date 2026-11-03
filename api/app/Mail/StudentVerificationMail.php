<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class StudentVerificationMail extends Mailable
{
    public function __construct(public string $verificationUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Complete your Ingress student account');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.student-verification');
    }
}
