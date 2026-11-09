<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class IngressMailConnectivityTest extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Ingress email connectivity test');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.ingress-connectivity-test');
    }
}
